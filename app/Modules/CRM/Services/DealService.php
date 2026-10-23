<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\DealItem;
use App\Modules\CRM\Models\DealStageEntry;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadInterest;
use App\Modules\CRM\Models\LostReason;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Masters\Services\LedgerLookup;
use App\Modules\Masters\Services\UomConverter;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\NumberSeriesService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Creates and changes deals. The single gate for deal data — the screens and the mobile API both come through here — so the
 * rules are the same whichever way a deal is made or changed. It does not decide who may (DealPolicy and LeadPolicy do), but
 * it does check on the server what a policy cannot know: the branch against the *actor*, whether the lead is still
 * convertible and whether the lines are real.
 *
 *  - **Conversion** (`convert`): a *qualified* lead that has an owner becomes a deal — once. The lead is locked while it is
 *    converted, `deals.lead_id` is unique, and the number is taken first, so two people converting the same lead at the same
 *    moment make one deal and one of them is told. Every line the lead listed comes across (a line of the lead may be
 *    adjusted but not dropped, and an item may not be swapped for another): to leave something out, edit the lead first.
 *    The deal keeps the lead's branch, owner and team, a snapshot of who the lead was, and the link back to the lead and to
 *    each of its lines. The lead moves to the Deal stage.
 *  - **Lines**: each is an approved, active item, a unit it is sold in, a quantity the unit allows (a unit that cannot be
 *    split takes whole numbers only) and the rate expected. The amount of a line is quantity × rate, rounded half up to paise,
 *    and the deal's value is the sum of the lines — both worked out here, never accepted from the client.
 *  - **Customer**: a deal from an existing customer's inquiry is for that customer; a deal from a new lead may be linked to a
 *    customer ledger (approved and active) once there is one.
 *  - **Content and progress**: the customer and the lines are what an approver signs off on, so they change only while the
 *    deal is a draft or was returned (`update`). Its close date, probability and remarks move on while it is open, approved or
 *    not (`updateProgress`).
 *  - **Stages** (`changeStage`): by DealStage's rules, each move written to the history; Lost needs a reason and re-opening a
 *    comment; Quotation and Won are set only by the documents that follow (`setStageBySystem`), and only for an approved
 *    deal. The lead follows the deal: Deal, Quotation, Won — and Lost with it.
 *  - **Hand-over** (`reassign`): the deal and its lead change hands together, through LeadTransferService, so the history of
 *    both is kept.
 *
 * Input: ['deal' => [customer_ledger_id, expected_close_date, probability, remarks],
 *         'items' => [[id?, lead_interest_id?, item_id, unit_id, quantity, expected_rate], …]]
 */
final class DealService
{
    public const MAX_LINES = LeadService::MAX_INTERESTS;

    /** The largest amount a stored money column holds (decimal 15,2). */
    private const MAX_AMOUNT = '9999999999999.99';

    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly NumberSeriesService $numbers,
        private readonly ItemLookup $items,
        private readonly LedgerLookup $ledgers,
        private readonly UomConverter $uoms,
        private readonly LeadService $leads,
        private readonly LeadAccess $leadAccess,
        private readonly LeadTransferService $transfers,
    ) {}

    // ---- reading ----------------------------------------------------------------------------------------------

    /**
     * The lines a lead would bring to a deal, as the conversion form starts with them: one per line the lead listed, with
     * what it already says. A line the lead only described in words has no item yet — the person picks one before converting.
     *
     * @return list<array<string, mixed>>
     */
    public function linesFromLead(Lead $lead): array
    {
        return $lead->interests()->with(['item:id,code,name', 'unit:id,code,name'])->get()->map(fn (LeadInterest $row) => [
            'lead_interest_id' => $row->getKey(),
            'item_id' => $row->item_id,
            'item_label' => $row->item === null ? '' : trim($row->item->code.' · '.$row->item->name),
            'description' => $row->description,
            'unit_id' => $row->unit_id,
            'quantity' => $row->quantity === null ? null : (string) $row->quantity,
            'expected_rate' => $row->expected_rate === null ? null : (string) $row->expected_rate,
        ])->all();
    }

    /**
     * The deal made from this lead, if it has been converted — whoever is asking, in any branch. $lock asks for the latest committed
     * answer and holds it (a locking read): inside a transaction a plain read would see only what was there when the transaction
     * began, and so miss a deal that somebody converting the same lead committed while this one waited for the lead's row lock.
     */
    public function dealOf(Lead $lead, bool $lock = false): ?Deal
    {
        return Deal::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $lead->company_id)->where('lead_id', $lead->getKey())
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    // ---- converting ---------------------------------------------------------------------------------------------

    /**
     * Turn a qualified lead into a deal.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException when something typed is not acceptable
     * @throws FieldRuleException when the lead cannot be converted (not qualified, already converted, not the actor's to convert…)
     */
    public function convert(Lead $lead, array $input, User $actor): Deal
    {
        return DB::transaction(function () use ($lead, $input, $actor) {
            // The number first, before anything is locked or written: converting queues on the branch's series instead of deadlocking.
            $number = $this->numbers->next(DocumentType::Deal, (int) $lead->location_id, now());

            $fresh = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());
            $this->assertConvertible($fresh, $actor);

            $data = $this->validate($input, null, $fresh);
            $customerId = $fresh->isExisting() ? (int) $fresh->customer_ledger_id : $data['deal']['customer_ledger_id'];

            $deal = new Deal;
            $deal->fill([
                'expected_close_date' => $data['deal']['expected_close_date'],
                'probability' => $data['deal']['probability'] ?? DealStage::Discovery->defaultProbability(),
                'remarks' => $data['deal']['remarks'],
            ]);
            $deal->forceFill([
                'company_id' => (int) $fresh->company_id, 'location_id' => (int) $fresh->location_id,
                'deal_number' => $number, 'deal_date' => now()->toDateString(),
                'lead_id' => $fresh->getKey(), 'customer_ledger_id' => $customerId,
                'party_name' => $fresh->name, 'contact_person' => $fresh->contact_person, 'mobile' => $fresh->mobile, 'email' => $fresh->email,
                'expected_value' => $data['total'], 'stage' => DealStage::Discovery->value,
                'assigned_to' => (int) $fresh->assigned_to, 'sales_team_id' => $fresh->sales_team_id,
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ]);

            try {
                $deal->save();
            } catch (UniqueConstraintViolationException) {
                // the lock above makes this unreachable in practice; the unique key is what makes it impossible
                throw new FieldRuleException('This lead has already been converted to a deal.');
            }

            $this->syncLines($deal, $data['items'], $actor);
            $this->recordStage($deal, null, DealStage::Discovery, $actor, "Converted from lead {$fresh->lead_number}", null);

            $this->leads->setStageBySystem($fresh, LeadStage::Deal, $actor, "Converted to deal {$deal->deal_number}");

            activity('leads')->causedBy($actor)->performedOn($fresh)->event('converted')
                ->withProperties(['deal_number' => $deal->deal_number, 'deal_id' => $deal->getKey()])->log('converted');

            return $deal->refresh();
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * @throws FieldRuleException
     */
    private function assertConvertible(Lead $lead, User $actor): void
    {
        // The branch is asked about the *actor* explicitly, so the answer does not depend on who (if anyone) is signed in around this call.
        $reach = $this->tenant->accessibleLocationIdsFor($actor, (int) $lead->company_id);

        if ($reach !== null && ! in_array((int) $lead->location_id, $reach, true)) {
            throw new FieldRuleException('This lead is in a branch you do not work in.');
        }

        if (! $this->leadAccess->works($actor, $lead)) {
            throw new FieldRuleException('This lead is not one of yours to convert.');
        }

        $existing = $this->dealOf($lead, lock: true);

        if ($existing !== null) {
            throw new FieldRuleException("This lead has already been converted to deal {$existing->deal_number}.");
        }

        if ($lead->stage !== LeadStage::Qualified) {
            throw new FieldRuleException('Only a qualified lead can become a deal; this one is '.strtolower($lead->stage->label()).'.');
        }

        if ($lead->assigned_to === null) {
            throw new FieldRuleException('Give the lead an owner before converting it: a deal always has one.');
        }

        if ($lead->isExisting() && ($lead->customer === null || ! $lead->customer->isUsable())) {
            throw new FieldRuleException('The customer is no longer approved and active, so the inquiry cannot become a deal.');
        }
    }

    // ---- changing -----------------------------------------------------------------------------------------------

    /**
     * Change the customer and the lines (and, with them, the close date, probability and remarks). Only while the deal is
     * still a draft or was returned, and no quotation or order has been made from it.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function update(Deal $deal, array $input, User $actor): Deal
    {
        return DB::transaction(function () use ($deal, $input, $actor) {
            $fresh = Deal::query()->lockForUpdate()->findOrFail($deal->getKey());

            if (! $fresh->isContentEditable()) {
                throw new FieldRuleException($this->lockedBecause($fresh));
            }

            $data = $this->validate($input, $fresh, $fresh->lead()->firstOrFail());

            $fresh->fill([
                'expected_close_date' => $data['deal']['expected_close_date'],
                'probability' => $data['deal']['probability'] ?? $fresh->probability,
                'remarks' => $data['deal']['remarks'],
            ]);
            $fresh->forceFill([
                'customer_ledger_id' => $data['deal']['customer_ledger_id'], 'expected_value' => $data['total'], 'updated_by' => $actor->getKey(),
            ])->save();

            $this->syncLines($fresh, $data['items'], $actor);

            return $fresh->refresh();
        });
    }

    /**
     * Change where an open deal stands — expected close date, probability, remarks — without touching what was approved.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function updateProgress(Deal $deal, array $input, User $actor): Deal
    {
        return DB::transaction(function () use ($deal, $input, $actor) {
            $fresh = Deal::query()->lockForUpdate()->findOrFail($deal->getKey());

            if (! $fresh->isOpen()) {
                throw new FieldRuleException('A deal that is '.strtolower($fresh->stage->label()).' can no longer be changed.');
            }

            $d = $this->normaliseDeal((array) ($input['deal'] ?? []));

            $validator = Validator::make(['deal' => $d], [
                'deal.expected_close_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$fresh->deal_date->toDateString(), 'before_or_equal:'.now()->addYears(5)->toDateString()],
                'deal.probability' => ['required', 'integer', 'between:1,99'],
                'deal.remarks' => ['nullable', 'string', 'max:2000'],
            ], [
                'deal.expected_close_date.after_or_equal' => 'The expected close date cannot be before the deal was made.',
                'deal.probability.between' => 'The probability of an open deal is between 1 and 99 per cent: mark the deal won or lost instead.',
            ], ['deal.expected_close_date' => 'expected close date']);

            $clean = $validator->validate()['deal'];

            $fresh->fill([
                'expected_close_date' => $clean['expected_close_date'], 'probability' => (int) $clean['probability'], 'remarks' => $d['remarks'],
            ]);
            $fresh->forceFill(['updated_by' => $actor->getKey()])->save();

            return $fresh->refresh();
        });
    }

    private function lockedBecause(Deal $deal): string
    {
        return match (true) {
            $deal->isPendingApproval() => 'This deal is waiting for approval, so its customer and lines are locked.',
            $deal->isApproved() => 'This deal is approved, so its customer and lines are locked. Re-open it, with a reason, to change them.',
            ! in_array($deal->stage, [DealStage::Discovery, DealStage::Negotiation], true) => 'A deal that is '.strtolower($deal->stage->label()).' can no longer have its lines changed.',
            default => 'This deal cannot be changed.',
        };
    }

    // ---- stages -------------------------------------------------------------------------------------------------

    /**
     * Move a deal between Discovery and Negotiation, mark it lost, or bring a lost one back. The lead follows.
     *
     * @throws FieldRuleException when the move is not one a person can make
     * @throws ValidationException when a reason or comment that the move needs is missing
     */
    public function changeStage(Deal $deal, DealStage $to, User $actor, ?string $comment = null, ?int $lostReasonId = null): Deal
    {
        $comment = $this->text($comment);

        if ($comment !== null && mb_strlen($comment) > 1000) {
            throw ValidationException::withMessages(['comment' => 'The comment may not be longer than 1000 characters.']);
        }

        return DB::transaction(function () use ($deal, $to, $actor, $comment, $lostReasonId) {
            $fresh = Deal::query()->lockForUpdate()->findOrFail($deal->getKey());
            $from = $fresh->stage;

            if ($from === $to) {
                throw new FieldRuleException('The deal is already '.strtolower($to->label()).'.');
            }

            if (! $from->canMoveTo($to)) {
                throw new FieldRuleException('A deal that is '.strtolower($from->label()).' cannot be moved to '.strtolower($to->label()).'.');
            }

            $reason = null;
            $probability = $fresh->probability;

            if ($to === DealStage::Lost) {
                $reason = $lostReasonId === null ? null : LostReason::query()->where('status', 'active')->find($lostReasonId);

                if ($reason === null) {
                    throw ValidationException::withMessages(['lost_reason_id' => 'Choose why the deal was lost.']);
                }

                $fresh->forceFill(['lost_reason_id' => $reason->getKey(), 'lost_remarks' => $comment, 'lost_at' => now()]);
                $probability = 0;
            } elseif ($from === DealStage::Lost) {
                if ($comment === null || mb_strlen($comment) < 3) {
                    throw ValidationException::withMessages(['comment' => 'Please say why the deal is being re-opened.']);
                }

                $fresh->forceFill(['lost_reason_id' => null, 'lost_remarks' => null, 'lost_at' => null]);
                $probability = $to->defaultProbability();
            } elseif ($probability === $from->defaultProbability()) {
                $probability = $to->defaultProbability();   // untouched by the salesperson: it follows the stage
            }

            $fresh->forceFill(['stage' => $to->value, 'probability' => $probability, 'updated_by' => $actor->getKey()])->save();
            $this->recordStage($fresh, $from, $to, $actor, $comment, $reason?->getKey());

            $this->followWithLead($fresh, $to, $actor, $comment, $reason?->getKey());

            return $fresh->refresh();
        });
    }

    /**
     * Set a stage only the documents that follow may set — Quotation when a quotation is made, Won when an order is approved,
     * and back to Negotiation when a quotation is withdrawn. Called by the quotation and order services (Phases 11–12), never
     * from a screen. Only an **approved** deal can go forward: nothing is quoted or ordered from a deal nobody signed off.
     *
     * @throws FieldRuleException
     */
    public function setStageBySystem(Deal $deal, DealStage $to, ?User $actor, string $comment): Deal
    {
        return DB::transaction(function () use ($deal, $to, $actor, $comment) {
            $fresh = Deal::query()->lockForUpdate()->findOrFail($deal->getKey());
            $from = $fresh->stage;

            $allowed = match ($to) {
                DealStage::Quotation => in_array($from, [DealStage::Discovery, DealStage::Negotiation], true),
                DealStage::Won => in_array($from, [DealStage::Discovery, DealStage::Negotiation, DealStage::Quotation], true),
                DealStage::Negotiation => $from === DealStage::Quotation,
                default => false,
            };

            if (! $allowed) {
                throw new FieldRuleException('A deal that is '.strtolower($from->label()).' cannot be moved to '.strtolower($to->label()).' by a document.');
            }

            if ($to !== DealStage::Negotiation && ! $fresh->isApproved()) {
                throw new FieldRuleException('The deal has not been approved, so nothing can be quoted or ordered from it yet.');
            }

            $fresh->forceFill(['stage' => $to->value, 'probability' => $to->defaultProbability(), 'updated_by' => $actor?->getKey()])->save();
            $this->recordStage($fresh, $from, $to, $actor, $comment, null);
            $this->followWithLead($fresh, $to, $actor, $comment, null);

            return $fresh->refresh();
        });
    }

    /** The lead follows its deal: Deal while it is worked, Quotation, Won — and Lost with it. */
    private function followWithLead(Deal $deal, DealStage $to, ?User $actor, ?string $comment, ?int $lostReasonId): void
    {
        $leadStage = match ($to) {
            DealStage::Discovery, DealStage::Negotiation => LeadStage::Deal,
            DealStage::Quotation => LeadStage::Quotation,
            DealStage::Won => LeadStage::Won,
            DealStage::Lost => LeadStage::Lost,
        };

        $note = match ($to) {
            DealStage::Lost => "Deal {$deal->deal_number} was lost".($comment === null ? '' : ": {$comment}"),
            DealStage::Discovery, DealStage::Negotiation => "Deal {$deal->deal_number} is open again".($comment === null ? '' : ": {$comment}"),
            default => "Deal {$deal->deal_number} is {$to->label()}".($comment === null ? '' : ": {$comment}"),
        };

        $this->leads->setStageBySystem($deal->lead()->firstOrFail(), $leadStage, $actor, $note, $lostReasonId);
    }

    // ---- ownership ----------------------------------------------------------------------------------------------

    /**
     * Hand an open deal to somebody else. The deal and its lead change hands together through LeadTransferService — the same
     * checks (an active user who works in the branch, the person themselves or someone they manage), the same history, the
     * same notice to the new owner — and what is planned for the lead goes with it.
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function reassign(Deal $deal, int $toUserId, string $reason, User $actor): Deal
    {
        return DB::transaction(function () use ($deal, $toUserId, $reason, $actor) {
            $fresh = Deal::query()->lockForUpdate()->findOrFail($deal->getKey());

            if (! $fresh->isOpen()) {
                throw new FieldRuleException('A deal that is '.strtolower($fresh->stage->label()).' no longer changes hands.');
            }

            if ((int) $fresh->assigned_to === $toUserId) {
                throw ValidationException::withMessages(['to_user_id' => 'The deal is already with that person.']);
            }

            $this->transfers->transfer([(int) $fresh->lead_id], $toUserId, $reason, $actor, allowConverted: true);

            $fresh->forceFill(['assigned_to' => $toUserId, 'sales_team_id' => $this->leads->teamOf($toUserId), 'updated_by' => $actor->getKey()])->save();

            activity('deals')->causedBy($actor)->performedOn($fresh)->event('reassigned')
                ->withProperties(['to_user_id' => $toUserId, 'reason' => trim($reason)])->log('reassigned');

            return $fresh->refresh();
        });
    }

    // ---- validation ---------------------------------------------------------------------------------------------

    /**
     * Normalise and check what was sent. The lead is the deal's source (always known); $editing is the deal being changed.
     *
     * @param  array<string, mixed>  $input
     * @return array{deal: array<string, mixed>, items: list<array<string, mixed>>, total: string}
     *
     * @throws ValidationException
     */
    public function validate(array $input, ?Deal $editing, Lead $lead): array
    {
        $deal = $this->normaliseDeal((array) ($input['deal'] ?? []));
        $items = $this->normaliseLines((array) ($input['items'] ?? []));
        $data = ['deal' => $deal, 'items' => $items];

        // The date a deal is expected to close is never in the past when it is made, nor before it was made afterwards.
        $earliest = $editing === null ? now()->toDateString() : $editing->deal_date->toDateString();

        $rules = [
            'deal.customer_ledger_id' => ['nullable', 'integer'],
            'deal.expected_close_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$earliest, 'before_or_equal:'.now()->addYears(5)->toDateString()],
            'deal.probability' => ['nullable', 'integer', 'between:1,99'],
            'deal.remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
        ];

        foreach ($items as $i => $row) {
            $rules["items.$i.id"] = ['nullable', 'integer'];
            $rules["items.$i.lead_interest_id"] = ['nullable', 'integer'];
            $rules["items.$i.item_id"] = ['required', 'integer'];
            $rules["items.$i.unit_id"] = ['nullable', 'integer'];
            $rules["items.$i.quantity"] = ['required', 'bail', 'regex:/^\d{1,12}(\.\d{1,3})?$/', fn (string $a, mixed $v, \Closure $fail) => bccomp((string) $v, '0', 3) > 0 ? null : $fail('The quantity must be more than zero.')];
            $rules["items.$i.expected_rate"] = ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/'];
        }

        $validator = Validator::make($data, $rules, [
            'deal.expected_close_date.required' => 'Enter the date the deal is expected to close.',
            'deal.expected_close_date.after_or_equal' => $editing === null ? 'The expected close date cannot be in the past.' : 'The expected close date cannot be before the deal was made.',
            'deal.expected_close_date.date_format' => 'Enter the expected close date as a valid date.',
            'deal.probability.between' => 'The probability of an open deal is between 1 and 99 per cent: mark the deal won or lost instead.',
            'items.required' => 'Add at least one item line.',
            'items.min' => 'Add at least one item line.',
            'items.max' => 'A deal can have at most '.self::MAX_LINES.' item lines.',
            'items.*.item_id.required' => 'Choose the item.',
            'items.*.quantity.required' => 'Enter the quantity.',
            'items.*.quantity.regex' => 'Enter the quantity as a number with at most 3 decimals.',
            'items.*.expected_rate.required' => 'Enter the rate expected.',
            'items.*.expected_rate.regex' => 'Enter the rate as a number with at most 2 decimals, e.g. 1250.50.',
        ], $this->attributeNames($items));

        $total = '0.00';

        $validator->after(function (\Illuminate\Validation\Validator $v) use (&$data, $editing, $lead, &$total) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $data['deal']['customer_ledger_id'] = $this->checkCustomer($v, $data['deal']['customer_ledger_id'], $editing, $lead);
            $data['items'] = $this->checkLines($v, $data['items'], $editing, $lead);

            if ($v->errors()->isEmpty()) {
                foreach ($data['items'] as $row) {
                    $total = bcadd($total, $row['amount'], 2);
                }

                if (bccomp($total, self::MAX_AMOUNT, 2) > 0) {
                    $v->errors()->add('items', 'The lines add up to more than the system can hold. Check the quantities and rates.');
                }
            }
        });

        $validator->validate();

        $data['deal']['probability'] = $data['deal']['probability'] === null ? null : (int) $data['deal']['probability'];

        return ['deal' => $data['deal'], 'items' => $data['items'], 'total' => $total];
    }

    /**
     * Who the deal is for. An inquiry's deal is for the inquiry's customer; otherwise a customer may be linked (approved and
     * active), or one that is already linked kept even if it has been switched off since.
     */
    private function checkCustomer(\Illuminate\Validation\Validator $v, ?string $submitted, ?Deal $editing, Lead $lead): ?int
    {
        if ($lead->isExisting()) {
            return (int) $lead->customer_ledger_id;   // fixed: whatever was sent
        }

        if ($submitted === null) {
            return null;
        }

        $id = (int) $submitted;

        if ($this->ledgers->usable($id, LedgerType::Customer) === null && (int) $editing?->customer_ledger_id !== $id) {
            $v->errors()->add('deal.customer_ledger_id', 'Choose a customer that is approved and active.');
        }

        return $id;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>> the lines, completed: ids as integers, a unit, the quantity and rate to their decimals and the amount
     */
    private function checkLines(\Illuminate\Validation\Validator $v, array $rows, ?Deal $editing, Lead $lead): array
    {
        $current = $editing === null ? collect() : $editing->items()->get()->keyBy('id');
        $interests = $editing === null ? $lead->interests()->get()->keyBy('id') : collect();
        $seenLines = [];
        $seenInterests = [];

        foreach ($rows as $i => $row) {
            $line = null;
            $interest = null;

            if ($row['id'] !== null) {
                $line = $current->get((int) $row['id']);

                if ($line === null || isset($seenLines[(int) $row['id']])) {
                    $v->errors()->add("items.$i.id", 'This row does not belong to the deal.');

                    continue;
                }

                $seenLines[(int) $row['id']] = true;
                $rows[$i]['lead_interest_id'] = $line->lead_interest_id;   // the link back to the lead is kept, whatever was sent
            } elseif ($editing === null && $row['lead_interest_id'] !== null) {
                $interest = $interests->get((int) $row['lead_interest_id']);

                if ($interest === null || isset($seenInterests[(int) $row['lead_interest_id']])) {
                    $v->errors()->add("items.$i.lead_interest_id", 'This row does not belong to the lead.');

                    continue;
                }

                $seenInterests[(int) $row['lead_interest_id']] = true;
                $rows[$i]['lead_interest_id'] = $interest->getKey();
            } else {
                $rows[$i]['lead_interest_id'] = null;   // a line added to the deal; it comes from no line of the lead
            }

            $itemId = (int) $row['item_id'];

            // The item a lead asked for stays the item: swapping it for another is a different line, not an adjustment.
            if ($interest !== null && $interest->item_id !== null && (int) $interest->item_id !== $itemId) {
                $v->errors()->add("items.$i.item_id", 'The lead asked for a different item here. Keep it, or edit the lead first.');

                continue;
            }

            // approved and active — or the item already on this line, which may have been switched off since
            $item = $this->items->usable($itemId) ?? ((int) $line?->item_id === $itemId ? Item::query()->with(['baseUnit', 'uoms.unit'])->find($itemId) : null);

            if ($item === null) {
                $v->errors()->add("items.$i.item_id", 'Choose an approved, active item.');

                continue;
            }

            $unitId = $row['unit_id'] !== null ? (int) $row['unit_id'] : (int) ($interest->unit_id ?? $item->base_unit_id);
            $sold = $unitId === (int) $item->base_unit_id || $item->uoms->contains(fn ($uom) => (int) $uom->unit_id === $unitId);
            $unit = $sold ? Unit::query()->find($unitId) : null;

            if ($unit === null) {
                $v->errors()->add("items.$i.unit_id", "{$item->name} is not sold in that unit.");

                continue;
            }

            $quantity = bcadd((string) $row['quantity'], '0', 3);

            if (! $this->uoms->accepts($unit, $quantity)) {
                $v->errors()->add("items.$i.quantity", "{$unit->name} cannot be split: enter a whole number.");

                continue;
            }

            $rate = bcadd((string) $row['expected_rate'], '0', 2);
            // quantity × rate to paise, rounded half up: adding half a paisa to the exact product before cutting it to two places
            $amount = bcadd(bcmul($quantity, $rate, 5), '0.005', 2);

            if (bccomp($amount, self::MAX_AMOUNT, 2) > 0) {
                $v->errors()->add("items.$i.quantity", 'This line comes to more than the system can hold. Check the quantity and the rate.');

                continue;
            }

            $rows[$i] = [
                'id' => $line?->getKey(), 'lead_interest_id' => $rows[$i]['lead_interest_id'] === null ? null : (int) $rows[$i]['lead_interest_id'],
                'item_id' => $itemId, 'unit_id' => $unitId, 'quantity' => $quantity, 'expected_rate' => $rate, 'amount' => $amount,
            ];
        }

        // Every line the lead listed has to come across.
        if ($editing === null && $v->errors()->isEmpty()) {
            foreach ($interests as $id => $interest) {
                if (! isset($seenInterests[$id])) {
                    $v->errors()->add('items', 'Every item the lead listed has to come across to the deal; “'.$interest->label().'” is missing. To leave it out, edit the lead first.');
                }
            }
        }

        return $rows;
    }

    // ---- persistence --------------------------------------------------------------------------------------------

    /** @param  list<array<string, mixed>>  $rows */
    private function syncLines(Deal $deal, array $rows, User $actor): void
    {
        $existing = $deal->items()->get()->keyBy('id');
        $kept = [];

        foreach ($rows as $n => $row) {
            $model = $row['id'] !== null ? $existing->get((int) $row['id']) : null;
            $model ??= new DealItem;

            $model->forceFill([
                'line_no' => $n + 1, 'lead_interest_id' => $row['lead_interest_id'], 'item_id' => $row['item_id'], 'unit_id' => $row['unit_id'],
                'quantity' => $row['quantity'], 'expected_rate' => $row['expected_rate'], 'amount' => $row['amount'], 'updated_by' => $actor->getKey(),
            ]);

            if (! $model->exists) {
                $model->forceFill(['company_id' => (int) $deal->company_id, 'deal_id' => $deal->getKey(), 'created_by' => $actor->getKey()]);
            }

            $model->save();
            $kept[] = $model->getKey();
        }

        foreach ($existing as $id => $line) {
            if (! in_array($id, $kept, true)) {
                $line->forceDelete();
            }
        }
    }

    private function recordStage(Deal $deal, ?DealStage $from, DealStage $to, ?User $actor, ?string $comment, ?int $lostReasonId): void
    {
        $entry = new DealStageEntry;
        $entry->forceFill([
            'company_id' => (int) $deal->company_id, 'deal_id' => $deal->getKey(), 'from_stage' => $from?->value, 'to_stage' => $to->value,
            'lost_reason_id' => $lostReasonId, 'comment' => $comment, 'actor_id' => $actor?->getKey(), 'created_at' => now(),
        ])->save();
    }

    // ---- normalising --------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $d
     * @return array{customer_ledger_id: string|null, expected_close_date: string|null, probability: string|null, remarks: string|null}
     */
    private function normaliseDeal(array $d): array
    {
        return [
            'customer_ledger_id' => $this->text($d['customer_ledger_id'] ?? null),
            'expected_close_date' => $this->text($d['expected_close_date'] ?? null),
            'probability' => $this->text($d['probability'] ?? null),
            'remarks' => ($r = trim((string) ($d['remarks'] ?? ''))) === '' ? null : $r,
        ];
    }

    /**
     * @param  array<int|string, mixed>  $rows
     * @return list<array<string, string|null>>
     */
    private function normaliseLines(array $rows): array
    {
        $lines = [];

        foreach (array_values($rows) as $row) {
            $row = (array) $row;
            $quantity = $this->text(str_replace(',', '', (string) ($row['quantity'] ?? '')));
            $rate = $this->text(str_replace(',', '', (string) ($row['expected_rate'] ?? '')));
            $item = $this->text($row['item_id'] ?? null);
            $id = $this->text($row['id'] ?? null);
            $interest = $this->text($row['lead_interest_id'] ?? null);

            // an untouched blank row of the form is not a line
            if ($item === null && $quantity === null && $rate === null && $id === null && $interest === null) {
                continue;
            }

            $lines[] = ['id' => $id, 'lead_interest_id' => $interest, 'item_id' => $item, 'unit_id' => $this->text($row['unit_id'] ?? null), 'quantity' => $quantity, 'expected_rate' => $rate];
        }

        return $lines;
    }

    /**
     * @param  list<array<string, string|null>>  $items
     * @return array<string, string>
     */
    private function attributeNames(array $items): array
    {
        $names = ['deal.expected_close_date' => 'expected close date', 'deal.probability' => 'probability', 'deal.remarks' => 'remarks'];

        foreach (array_keys($items) as $i) {
            $n = $i + 1;

            foreach (['item_id' => 'item', 'unit_id' => 'unit', 'quantity' => 'quantity', 'expected_rate' => 'rate'] as $field => $label) {
                $names["items.$i.$field"] = "line $n $label";
            }
        }

        return $names;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) ($value ?? '')));

        return $text === '' ? null : $text;
    }
}

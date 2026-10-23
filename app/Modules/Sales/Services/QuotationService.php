<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Enums\FollowupType;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\DealAccess;
use App\Modules\CRM\Services\DealService;
use App\Modules\CRM\Services\FollowupService;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Masters\Services\RateService;
use App\Modules\Sales\Enums\QuotationState;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\QuotationDispatch;
use App\Modules\Sales\Models\QuotationItem;
use App\Modules\Sales\Models\QuotationStateEntry;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Services\NumberSeriesService;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Makes and moves quotations. The single gate for quotation data — the screens and the API come through here — so the rules are the
 * same whichever way. It does not decide who may (QuotationPolicy does), but it checks on the server what a policy cannot know:
 * the branch against the *actor*, whether the source is still open and approved, and what state the revision is in.
 *
 *  - **Making** (`create`): from an **approved, open deal** — or straight from an **approved, open inquiry** that has not been
 *    converted. It takes the branch's QT number first (so concurrent saves queue instead of deadlocking), prices every line
 *    through QuotationPricer and saves a draft, Rev 0. A deal needs no quotation at all: a sales order may be made from an
 *    approved deal without one (Phase 12).
 *  - **Changing** (`update`): only a draft that is not waiting for approval; everything is re-checked and re-priced as on creation.
 *  - **Revising** (`revise`): from the latest revision once it has been sent (or declined, or has expired) — a new draft row with
 *    the next revision number and the same lines and prices; the old one is marked superseded and never changes again.
 *  - **Sending** (`send`): only an approved draft that is the latest revision and still valid, by e-mail (the PDF attached, through
 *    the company's configured mail transport) or recorded as handed over another way; either way it is written to the dispatch
 *    log, and a failed e-mail puts the quotation back to draft rather than pretend. The deal (or inquiry) moves to Quotation.
 *  - **The customer's answer** (`accept`, `decline`), **withdrawing** (`cancel`) and **running out** (QuotationExpiry): each is a
 *    state change with a history entry; the deal falls back to Negotiation when no quotation of it is left standing.
 *
 * Input for create/update: see QuotationPricer.
 */
final class QuotationService
{
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly NumberSeriesService $numbers,
        private readonly QuotationPricer $pricer,
        private readonly RateService $rates,
        private readonly DealService $deals,
        private readonly DealAccess $dealAccess,
        private readonly LeadService $leads,
        private readonly LeadAccess $leadAccess,
        private readonly FollowupService $followups,
        private readonly SettingsService $settings,
        private readonly QuotationDelivery $delivery,
    ) {}

    // ---- reading ------------------------------------------------------------------------------------------------

    /**
     * The lines a new quotation starts with, from its source — one per line of the deal (or of the inquiry), at the quantity asked.
     *
     * @return list<array<string, mixed>>
     */
    public function startingLines(Lead $lead, ?Deal $deal): array
    {
        if ($deal !== null) {
            return $deal->items()->with(['item:id,code,name'])->get()->map(fn ($line) => [
                'deal_item_id' => $line->getKey(), 'lead_interest_id' => null, 'item_id' => $line->item_id, 'item_label' => $line->item === null ? '' : trim($line->item->code.' · '.$line->item->name),
                'unit_id' => $line->unit_id, 'quantity' => (string) $line->quantity,
            ])->all();
        }

        return $lead->interests()->with(['item:id,code,name'])->get()->map(fn ($row) => [
            'deal_item_id' => null, 'lead_interest_id' => $row->getKey(), 'item_id' => $row->item_id, 'item_label' => $row->item === null ? '' : trim($row->item->code.' · '.$row->item->name),
            'unit_id' => $row->unit_id, 'quantity' => $row->quantity === null ? null : (string) $row->quantity,
        ])->all();
    }

    // ---- making -------------------------------------------------------------------------------------------------

    /**
     * Make Rev 0 of a new quotation from a deal or an inquiry.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException when something typed is not acceptable (including a line with no rate on the master)
     * @throws FieldRuleException when the source cannot be quoted from
     */
    public function create(Deal|Lead $source, array $input, User $actor): Quotation
    {
        $deal = $source instanceof Deal ? $source : null;
        $lead = $source instanceof Deal ? $source->lead()->firstOrFail() : $source;
        $locationId = (int) $lead->location_id;

        return DB::transaction(function () use ($deal, $lead, $input, $actor, $locationId) {
            // The number first, before anything is locked or written: making quotations queues on the branch's series.
            $number = $this->numbers->next(DocumentType::Quotation, $locationId, now());

            $freshLead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());
            $freshDeal = $deal === null ? null : Deal::query()->lockForUpdate()->findOrFail($deal->getKey());
            $this->assertQuotable($freshLead, $freshDeal, $actor);

            $data = $this->pricer->price($input, null, $freshLead, $freshDeal, $actor, $locationId);

            $quotation = new Quotation;
            $quotation->fill(['valid_until' => $data['quotation']['valid_until'], 'terms' => $data['quotation']['terms'], 'remarks' => $data['quotation']['remarks']]);
            $quotation->forceFill([
                'company_id' => (int) $freshLead->company_id, 'location_id' => $locationId, 'quotation_number' => $number, 'revision_no' => 0,
                'lead_id' => $freshLead->getKey(), 'deal_id' => $freshDeal?->getKey(),
                'customer_ledger_id' => $freshDeal->customer_ledger_id ?? $freshLead->customer_ledger_id,
                'state' => QuotationState::Draft->value, 'quotation_date' => now()->toDateString(),
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ]);
            $this->applyHeader($quotation, $data);
            $quotation->save();

            $this->syncLines($quotation, $data['lines'], $actor);
            $this->recordState($quotation, null, QuotationState::Draft, $actor, $freshDeal === null ? "Made from inquiry {$freshLead->lead_number}" : "Made from deal {$freshDeal->deal_number}");

            return $quotation->refresh();
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Change a draft: the customer's details, the price list, the validity, the terms and the lines.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function update(Quotation $quotation, array $input, User $actor): Quotation
    {
        return DB::transaction(function () use ($quotation, $input, $actor) {
            $fresh = Quotation::query()->lockForUpdate()->findOrFail($quotation->getKey());

            if (! $fresh->isContentEditable()) {
                throw new FieldRuleException($this->lockedBecause($fresh));
            }

            $lead = Lead::query()->findOrFail($fresh->lead_id);
            $deal = $fresh->deal_id === null ? null : Deal::query()->findOrFail($fresh->deal_id);
            $this->assertSourceOpen($lead, $deal);

            $data = $this->pricer->price($input, $fresh, $lead, $deal, $actor, (int) $fresh->location_id);

            $fresh->fill(['valid_until' => $data['quotation']['valid_until'], 'terms' => $data['quotation']['terms'], 'remarks' => $data['quotation']['remarks']]);
            $fresh->forceFill(['updated_by' => $actor->getKey()]);
            $this->applyHeader($fresh, $data);
            $fresh->save();

            $this->syncLines($fresh, $data['lines'], $actor);

            return $fresh->refresh();
        });
    }

    private function lockedBecause(Quotation $q): string
    {
        return match (true) {
            ! $q->isDraft() => 'This revision has been '.strtolower($q->state->label()).' and can no longer be changed. Make a new revision to change the offer.',
            $q->isPendingApproval() => 'This quotation is waiting for approval, so it is locked.',
            $q->isApproved() => 'This quotation is approved, so it is locked. Re-open it, with a reason, to change it.',
            default => 'This quotation cannot be changed.',
        };
    }

    // ---- revising -----------------------------------------------------------------------------------------------

    /**
     * Make the next revision of a quotation: a new draft with the same customer, terms, lines and prices, to be changed and approved
     * again. The revision it was made from is superseded — it stays exactly as the customer saw it.
     *
     * @throws FieldRuleException
     */
    public function revise(Quotation $quotation, User $actor, ?string $reason = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $actor, $reason) {
            $old = Quotation::query()->lockForUpdate()->findOrFail($quotation->getKey());

            if (! $old->isLatestRevision()) {
                $newer = Quotation::query()->where('quotation_number', $old->quotation_number)->orderByDesc('revision_no')->first();

                throw new FieldRuleException('Revision '.($newer->revision_no ?? '?').' has already been made from this quotation. Work on that one.');
            }

            if (! $old->state->canBeRevised()) {
                throw new FieldRuleException('A quotation that is '.strtolower($old->state->label()).' cannot be revised'.($old->isDraft() ? ': change the draft itself.' : '.'));
            }

            $lead = Lead::query()->findOrFail($old->lead_id);
            $deal = $old->deal_id === null ? null : Deal::query()->findOrFail($old->deal_id);
            $this->assertSourceOpen($lead, $deal);
            $this->assertBranch($old, $actor);

            $last = (int) Quotation::query()->where('quotation_number', $old->quotation_number)->lockForUpdate()->orderByDesc('revision_no')->value('revision_no');

            $days = max(1, (int) $this->settings->get('quotation.validity_days', (int) $old->company_id));
            $validUntil = $old->valid_until->lt(now()->startOfDay()) ? now()->addDays($days) : $old->valid_until;

            $copy = [
                'company_id', 'location_id', 'quotation_number', 'lead_id', 'deal_id', 'customer_ledger_id', 'price_date', 'price_list_id', 'party_name', 'contact_person',
                'mobile', 'email', 'bill_line1', 'bill_line2', 'bill_city', 'bill_pin_code', 'customer_gstin', 'place_of_supply_state_id', 'supply_type',
                'supplier_gstin', 'supplier_state_code', 'terms', 'remarks', 'gross_total', 'discount_total', 'taxable_total', 'cgst_total', 'sgst_total', 'igst_total',
                'round_off', 'grand_total',
            ];

            $new = new Quotation;
            $new->forceFill(array_intersect_key($old->getAttributes(), array_flip($copy)));
            $new->forceFill([
                'revision_no' => $last + 1, 'previous_id' => $old->getKey(), 'state' => QuotationState::Draft->value, 'quotation_date' => now()->toDateString(),
                'valid_until' => $validUntil->toDateString(), 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ]);

            try {
                $new->save();
            } catch (UniqueConstraintViolationException) {
                // the lock above makes this unreachable in practice; the unique key is what makes it impossible
                throw new FieldRuleException('Somebody has just made the next revision of this quotation. Work on that one.');
            }

            foreach ($old->items()->get() as $line) {
                $copyLine = new QuotationItem;
                $copyLine->forceFill(array_diff_key($line->getAttributes(), array_flip(['id', 'created_at', 'updated_at', 'deleted_at'])));
                $copyLine->forceFill(['quotation_id' => $new->getKey(), 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()])->save();
            }

            $this->recordState($new, null, QuotationState::Draft, $actor, "Revision {$new->revision_no}, made from revision {$old->revision_no}".($reason === null || trim($reason) === '' ? '' : ': '.trim($reason)));

            $from = $old->state;
            $old->forceFill(['state' => QuotationState::Superseded->value, 'superseded_at' => now(), 'updated_by' => $actor->getKey()])->save();
            $this->recordState($old, $from, QuotationState::Superseded, $actor, "Replaced by revision {$new->revision_no}");

            $this->syncSourceStage($old, $actor, "Quotation {$old->quotation_number} is being revised");

            activity('quotations')->causedBy($actor)->performedOn($new)->event('revised')
                ->withProperties(['from_revision' => $old->revision_no, 'revision' => $new->revision_no, 'reason' => $reason])->log('revised');

            return $new->refresh();
        }, self::DEADLOCK_ATTEMPTS);
    }

    // ---- sending ------------------------------------------------------------------------------------------------

    /**
     * Send an approved draft to the customer. Input: ['channel' => 'email'|'manual', 'to', 'cc', 'subject', 'message'].
     *
     * The revision is claimed first (draft → sent, in one locked step, so two people cannot both send it), then the e-mail goes out;
     * if the mail transport refuses it the revision goes back to draft with the failure on the dispatch log, so the screen never says
     * "sent" for a quotation the customer did not get.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function send(Quotation $quotation, array $input, User $actor): Quotation
    {
        $channel = (string) ($input['channel'] ?? 'email');
        $mail = $channel === 'email' ? $this->delivery->validate($input, $quotation) : null;

        if (! in_array($channel, ['email', 'manual'], true)) {
            throw ValidationException::withMessages(['channel' => 'Choose how the quotation is being sent.']);
        }

        // 1. claim it
        $pdf = null;
        $sentAt = now();

        DB::transaction(function () use ($quotation, $actor, $channel, $sentAt, &$pdf) {
            $fresh = Quotation::query()->lockForUpdate()->findOrFail($quotation->getKey());
            $this->assertSendable($fresh, $actor);

            if ($channel === 'email') {
                $pdf = $this->delivery->pdf($fresh);   // a failure here leaves the quotation untouched
            }

            $fresh->forceFill(['state' => QuotationState::Sent->value, 'sent_at' => $sentAt, 'updated_by' => $actor->getKey()])->save();
            $this->recordState($fresh, QuotationState::Draft, QuotationState::Sent, $actor, $channel === 'email' ? 'Sent by e-mail' : 'Recorded as handed over to the customer');

            if ($channel === 'manual') {
                $this->logDispatch($fresh, 'manual', null, null, null, 'sent', null, null, $actor);
            }
        }, self::DEADLOCK_ATTEMPTS);

        $fresh = Quotation::query()->findOrFail($quotation->getKey());

        // 2. send it
        if ($channel === 'email') {
            try {
                $this->delivery->email($fresh, (string) $pdf, $mail ?? [], $actor);
            } catch (Throwable $e) {
                $this->undoClaim($fresh, $actor, $mail ?? [], (string) $pdf, $e);

                throw new FieldRuleException('The e-mail could not be sent, so the quotation is still a draft. The mail server said: '.$this->delivery->safeMessage($e));
            }

            $this->logDispatch($fresh, 'email', $mail['to'] ?? null, $mail['cc'] ?? null, $mail['subject'] ?? null, 'sent', null, hash('sha256', (string) $pdf), $actor);
        }

        // 3. what follows from it being out: the deal's stage, and a follow-up to ask what the customer thinks
        try {
            $this->syncSourceStage($fresh, $actor, "Quotation {$fresh->displayNumber()} sent");
            $this->planFollowup($fresh, $actor);
        } catch (FieldRuleException|ValidationException $e) {
            Log::warning('Quotation sent, but its follow-on steps were refused.', ['quotation' => $fresh->getKey(), 'message' => $e->getMessage()]);
        }

        activity('quotations')->causedBy($actor)->performedOn($fresh)->event('sent')->withProperties(['channel' => $channel, 'to' => $mail['to'] ?? null])->log('sent');

        return $fresh->refresh();
    }

    /** @param  array<string, mixed>  $mail */
    private function undoClaim(Quotation $quotation, User $actor, array $mail, string $pdf, Throwable $e): void
    {
        DB::transaction(function () use ($quotation, $actor, $mail, $pdf, $e) {
            $fresh = Quotation::query()->lockForUpdate()->findOrFail($quotation->getKey());
            $this->logDispatch($fresh, 'email', $mail['to'] ?? null, $mail['cc'] ?? null, $mail['subject'] ?? null, 'failed', $this->delivery->safeMessage($e), hash('sha256', $pdf), $actor);

            if ($fresh->state === QuotationState::Sent) {
                $fresh->forceFill(['state' => QuotationState::Draft->value, 'sent_at' => null, 'updated_by' => $actor->getKey()])->save();
                $this->recordState($fresh, QuotationState::Sent, QuotationState::Draft, $actor, 'The e-mail could not be delivered, so the quotation is still a draft');
            }
        });
    }

    /** @throws FieldRuleException */
    private function assertSendable(Quotation $quotation, User $actor): void
    {
        $this->assertBranch($quotation, $actor);

        if (! $quotation->isDraft()) {
            throw new FieldRuleException('This revision has already been '.strtolower($quotation->state->label()).'.');
        }

        if (! $quotation->isLatestRevision()) {
            throw new FieldRuleException('A newer revision has replaced this one.');
        }

        if (! $quotation->isApproved()) {
            throw new FieldRuleException($quotation->isPendingApproval() ? 'This quotation is still waiting for approval.' : 'This quotation has to be approved before it can be sent.');
        }

        if ($quotation->isPastValidity()) {
            throw new FieldRuleException('The valid-until date, '.$quotation->valid_until->format('d-m-Y').', has passed. Re-open the quotation, change the date and have it approved again.');
        }

        if ($quotation->items()->doesntExist()) {
            throw new FieldRuleException('The quotation has no lines.');
        }

        $deal = $quotation->deal_id === null ? null : Deal::query()->findOrFail($quotation->deal_id);
        $this->assertSourceOpen(Lead::query()->findOrFail($quotation->lead_id), $deal);

        if ($deal !== null && ! $deal->isApproved()) {
            throw new FieldRuleException('The deal was re-opened for changes and is not approved at the moment: it has to be approved again before this quotation goes out.');
        }
    }

    private function planFollowup(Quotation $quotation, User $actor): void
    {
        $days = (int) $this->settings->get('quotation.followup_days', (int) $quotation->company_id);

        if ($days <= 0) {
            return;
        }

        $lead = Lead::query()->find($quotation->lead_id);

        if ($lead === null || ! $lead->isOpen() || $lead->assigned_to === null) {
            return;
        }

        $when = now()->addDays($days)->setTime(11, 0);

        $this->followups->schedule($lead, ['plan' => [
            'scheduled_at' => $when->format('Y-m-d H:i'), 'type' => FollowupType::Call->value, 'assigned_to' => (string) $lead->assigned_to,
            'notes' => "Ask what the customer thinks of quotation {$quotation->displayNumber()} (valid until {$quotation->valid_until->format('d-m-Y')}).",
        ]], $actor);
    }

    // ---- the customer's answer, withdrawing ---------------------------------------------------------------------

    /** @throws FieldRuleException */
    public function accept(Quotation $quotation, ?string $note, User $actor): Quotation
    {
        return $this->respond($quotation, QuotationState::Accepted, $note, $actor);
    }

    /**
     * @throws FieldRuleException
     * @throws ValidationException
     */
    public function decline(Quotation $quotation, ?string $reason, User $actor): Quotation
    {
        if ($this->text($reason) === null) {
            throw ValidationException::withMessages(['reason' => 'Please say why the customer declined.']);
        }

        return $this->respond($quotation, QuotationState::Declined, $reason, $actor);
    }

    private function respond(Quotation $quotation, QuotationState $to, ?string $note, User $actor): Quotation
    {
        $note = $this->text($note);

        if ($note !== null && mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['reason' => 'Keep it to 500 characters.']);
        }

        return DB::transaction(function () use ($quotation, $to, $note, $actor) {
            $fresh = Quotation::query()->lockForUpdate()->findOrFail($quotation->getKey());
            $this->assertBranch($fresh, $actor);

            if ($fresh->state !== QuotationState::Sent) {
                throw new FieldRuleException('Only a quotation that has been sent can be answered; this one is '.strtolower($fresh->state->label()).'.');
            }

            if (! $fresh->isLatestRevision()) {
                throw new FieldRuleException('A newer revision has replaced this one.');
            }

            if ($to === QuotationState::Accepted && $fresh->isPastValidity()) {
                throw new FieldRuleException('This quotation was valid until '.$fresh->valid_until->format('d-m-Y').' and has run out. Make a new revision to offer it again.');
            }

            $columns = $to === QuotationState::Accepted
                ? ['accepted_at' => now(), 'accepted_note' => $note]
                : ['declined_at' => now(), 'declined_reason' => $note];

            $fresh->forceFill(['state' => $to->value, 'updated_by' => $actor->getKey(), ...$columns])->save();
            $this->recordState($fresh, QuotationState::Sent, $to, $actor, $note);
            $this->syncSourceStage($fresh, $actor, "Quotation {$fresh->displayNumber()} ".strtolower($to->label()));

            return $fresh->refresh();
        });
    }

    /**
     * Withdraw a quotation that has not ended. A reason is required.
     *
     * @throws FieldRuleException
     * @throws ValidationException
     */
    public function cancel(Quotation $quotation, string $reason, User $actor): Quotation
    {
        $reason = $this->text($reason);

        if ($reason === null || mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Please say why the quotation is being withdrawn (3 to 500 characters).']);
        }

        return DB::transaction(function () use ($quotation, $reason, $actor) {
            $fresh = Quotation::query()->lockForUpdate()->findOrFail($quotation->getKey());
            $this->assertBranch($fresh, $actor);

            if (! $fresh->isLatestRevision() || ! in_array($fresh->state, [QuotationState::Draft, QuotationState::Sent, QuotationState::Accepted], true)) {
                throw new FieldRuleException('A quotation that is '.strtolower($fresh->state->label()).' cannot be withdrawn.');
            }

            if ($fresh->isPendingApproval()) {
                throw new FieldRuleException('This quotation is waiting for approval: wait for the decision before withdrawing it.');
            }

            $from = $fresh->state;
            $fresh->forceFill(['state' => QuotationState::Cancelled->value, 'cancelled_at' => now(), 'cancel_reason' => $reason, 'updated_by' => $actor->getKey()])->save();
            $this->recordState($fresh, $from, QuotationState::Cancelled, $actor, $reason);
            $this->syncSourceStage($fresh, $actor, "Quotation {$fresh->displayNumber()} withdrawn");

            return $fresh->refresh();
        });
    }

    // ---- shared with the scheduler ------------------------------------------------------------------------------

    /**
     * Move a sent revision to Expired (called by QuotationExpiry once its valid-until date is behind us).
     */
    public function expire(Quotation $quotation): ?Quotation
    {
        return DB::transaction(function () use ($quotation) {
            $fresh = Quotation::query()->lockForUpdate()->find($quotation->getKey());

            if ($fresh === null || $fresh->state !== QuotationState::Sent || ! $fresh->isPastValidity()) {
                return null;
            }

            $fresh->forceFill(['state' => QuotationState::Expired->value, 'expired_at' => now()])->save();
            $this->recordState($fresh, QuotationState::Sent, QuotationState::Expired, null, 'Valid until '.$fresh->valid_until->format('d-m-Y').' and not accepted');

            try {
                $this->syncSourceStage($fresh, null, "Quotation {$fresh->displayNumber()} expired");
            } catch (FieldRuleException $e) {
                Log::warning('A quotation expired, but its deal could not follow.', ['quotation' => $fresh->getKey(), 'message' => $e->getMessage()]);
            }

            return $fresh->refresh();
        });
    }

    // ---- the deal (or inquiry) follows its quotations ----------------------------------------------------------

    /**
     * A deal whose quotation is out is in Quotation; once none is left standing (sent or accepted) it is back in Negotiation. An inquiry
     * quoted directly follows the same way through its own stage (Qualified ⇄ Quotation).
     *
     * @throws FieldRuleException
     */
    private function syncSourceStage(Quotation $quotation, ?User $actor, string $comment): void
    {
        $standing = Quotation::query()->where('lead_id', $quotation->lead_id)->whereIn('state', [QuotationState::Sent->value, QuotationState::Accepted->value])->exists();

        if ($quotation->deal_id !== null) {
            $deal = Deal::query()->lockForUpdate()->find($quotation->deal_id);

            if ($deal === null) {
                return;
            }

            if ($standing && in_array($deal->stage, [DealStage::Discovery, DealStage::Negotiation], true) && $deal->isApproved()) {
                $this->deals->setStageBySystem($deal, DealStage::Quotation, $actor, $comment);
            } elseif (! $standing && $deal->stage === DealStage::Quotation) {
                $this->deals->setStageBySystem($deal, DealStage::Negotiation, $actor, $comment);
            }

            return;
        }

        $lead = Lead::query()->lockForUpdate()->find($quotation->lead_id);

        if ($lead === null) {
            return;
        }

        if ($standing && $lead->stage === LeadStage::Qualified) {
            $this->leads->setStageBySystem($lead, LeadStage::Quotation, $actor, $comment);
        } elseif (! $standing && $lead->stage === LeadStage::Quotation) {
            $this->leads->setStageBySystem($lead, LeadStage::Qualified, $actor, $comment);
        }
    }

    // ---- checks -------------------------------------------------------------------------------------------------

    /** @throws FieldRuleException */
    private function assertQuotable(Lead $lead, ?Deal $deal, User $actor): void
    {
        $reach = $this->tenant->accessibleLocationIdsFor($actor, (int) $lead->company_id);

        if ($reach !== null && ! in_array((int) $lead->location_id, $reach, true)) {
            throw new FieldRuleException('This is in a branch you do not work in.');
        }

        if ($deal !== null) {
            if (! $this->dealAccess->works($actor, $deal)) {
                throw new FieldRuleException('This deal is not one of yours to quote.');
            }

            if (! $deal->isApproved()) {
                throw new FieldRuleException('The deal has not been approved, so nothing can be quoted from it yet.');
            }

            if (! $deal->isOpen()) {
                throw new FieldRuleException('A deal that is '.strtolower($deal->stage->label()).' cannot be quoted.');
            }

            return;
        }

        if (! $this->leadAccess->works($actor, $lead)) {
            throw new FieldRuleException('This inquiry is not one of yours to quote.');
        }

        if (! $lead->isExisting()) {
            throw new FieldRuleException('A new lead has to be qualified and converted to a deal before it is quoted.');
        }

        if (! $lead->isApproved()) {
            throw new FieldRuleException('The inquiry has not been approved, so nothing can be quoted from it yet.');
        }

        if (! $lead->isOpen()) {
            throw new FieldRuleException('An inquiry that is '.strtolower($lead->stage->label()).' cannot be quoted.');
        }

        $made = $this->deals->dealOf($lead, lock: true);

        if ($made !== null) {
            throw new FieldRuleException("This inquiry has been converted to deal {$made->deal_number}: make the quotation from the deal.");
        }

        if ($lead->assigned_to === null) {
            throw new FieldRuleException('Give the inquiry an owner before quoting it.');
        }
    }

    /** @throws FieldRuleException */
    private function assertSourceOpen(Lead $lead, ?Deal $deal): void
    {
        if ($deal !== null ? ! $deal->isOpen() : ! $lead->isOpen()) {
            throw new FieldRuleException($deal !== null
                ? 'The deal is '.strtolower($deal->stage->label()).', so its quotations can no longer be changed or sent.'
                : 'The inquiry is '.strtolower($lead->stage->label()).', so its quotations can no longer be changed or sent.');
        }
    }

    /** The branch is asked about the *actor* explicitly, so the answer does not depend on who (if anyone) is signed in around this call. @throws FieldRuleException */
    private function assertBranch(Quotation $quotation, User $actor): void
    {
        $reach = $this->tenant->accessibleLocationIdsFor($actor, (int) $quotation->company_id);

        if ($reach !== null && ! in_array((int) $quotation->location_id, $reach, true)) {
            throw new FieldRuleException('This quotation is in a branch you do not work in.');
        }
    }

    // ---- persistence --------------------------------------------------------------------------------------------

    /** @param  array{quotation: array<string, mixed>, lines: list<array<string, mixed>>, totals: array<string, string>}  $data */
    private function applyHeader(Quotation $quotation, array $data): void
    {
        $h = $data['quotation'];
        $t = $data['totals'];

        $quotation->forceFill([
            'price_list_id' => $h['price_list_id'], 'price_date' => $h['price_date'],
            'party_name' => $h['party_name'], 'contact_person' => $h['contact_person'], 'mobile' => $h['mobile'], 'email' => $h['email'],
            'bill_line1' => $h['bill_line1'], 'bill_line2' => $h['bill_line2'], 'bill_city' => $h['bill_city'], 'bill_pin_code' => $h['bill_pin_code'],
            'customer_gstin' => $h['customer_gstin'], 'place_of_supply_state_id' => $h['place_of_supply_state_id'], 'supply_type' => $h['supply_type'],
            'supplier_state_code' => $h['supplier_state_code'], 'supplier_gstin' => $h['supplier_gstin'],
            'gross_total' => $t['gross'], 'discount_total' => $t['discount'], 'taxable_total' => $t['taxable'], 'cgst_total' => $t['cgst'],
            'sgst_total' => $t['sgst'], 'igst_total' => $t['igst'], 'round_off' => $t['round_off'], 'grand_total' => $t['grand_total'],
        ]);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function syncLines(Quotation $quotation, array $lines, User $actor): void
    {
        $existing = $quotation->items()->get()->keyBy('id');
        $kept = [];

        foreach ($lines as $n => $row) {
            $model = $row['id'] !== null ? $existing->get((int) $row['id']) : null;
            $model ??= new QuotationItem;
            $f = $row['figures'];

            $model->forceFill([
                'line_no' => $n + 1, 'deal_item_id' => $row['deal_item_id'], 'lead_interest_id' => $row['lead_interest_id'],
                'item_id' => $row['item_id'], 'unit_id' => $row['unit_id'], 'item_name' => $row['item_name'], 'hsn_code' => $row['hsn_code'],
                'quantity' => $row['quantity'], 'rate_id' => $row['rate_id'], 'rate_source' => $row['rate_source'], 'list_rate' => $row['list_rate'],
                'rate' => $row['rate'], 'rate_overridden' => $row['rate_overridden'], 'override_reason' => $row['override_reason'],
                'discount_percent' => $row['discount_percent'], 'gross_amount' => $f['gross'], 'discount_amount' => $f['discount'],
                'taxable_amount' => $f['taxable'], 'gst_rate' => $row['gst_rate'], 'cgst_amount' => $f['cgst'], 'sgst_amount' => $f['sgst'],
                'igst_amount' => $f['igst'], 'line_total' => $f['total'], 'updated_by' => $actor->getKey(),
            ]);

            if (! $model->exists) {
                $model->forceFill(['company_id' => (int) $quotation->company_id, 'quotation_id' => $quotation->getKey(), 'created_by' => $actor->getKey()]);
            }

            $model->save();
            $kept[] = $model->getKey();

            // A rate somebody changed is recorded on the rate master's audit trail, against this quotation.
            if ($row['audit'] !== null) {
                $this->rates->recordOverride($row['audit']['quote'], $row['audit']['rate'], $row['audit']['reason'], $actor, $quotation);
            }
        }

        foreach ($existing as $id => $line) {
            if (! in_array($id, $kept, true)) {
                $line->forceDelete();
            }
        }
    }

    private function recordState(Quotation $quotation, ?QuotationState $from, QuotationState $to, ?User $actor, ?string $comment): void
    {
        $entry = new QuotationStateEntry;
        $entry->forceFill([
            'company_id' => (int) $quotation->company_id, 'quotation_id' => $quotation->getKey(), 'from_state' => $from?->value, 'to_state' => $to->value,
            'comment' => $comment === null ? null : mb_substr($comment, 0, 1000), 'actor_id' => $actor?->getKey(), 'created_at' => now(),
        ])->save();
    }

    private function logDispatch(Quotation $quotation, string $channel, ?string $to, ?string $cc, ?string $subject, string $status, ?string $error, ?string $hash, ?User $actor): void
    {
        $row = new QuotationDispatch;
        $row->forceFill([
            'company_id' => (int) $quotation->company_id, 'quotation_id' => $quotation->getKey(), 'channel' => $channel, 'to_email' => $to, 'cc_email' => $cc,
            'subject' => $subject === null ? null : mb_substr($subject, 0, 200), 'status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 500),
            'pdf_sha256' => $hash, 'actor_id' => $actor?->getKey(), 'created_at' => now(),
        ])->save();
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) ($value ?? '')));

        return $text === '' ? null : $text;
    }
}

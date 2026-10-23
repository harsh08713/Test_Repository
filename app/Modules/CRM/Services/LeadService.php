<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Enums\FollowupStatus;
use App\Modules\CRM\Enums\LeadChannel;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Enums\LeadType;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadInterest;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\LeadStageEntry;
use App\Modules\CRM\Models\LeadTransfer;
use App\Modules\CRM\Models\LostReason;
use App\Modules\CRM\Models\SalesTeamMember;
use App\Modules\CRM\Notifications\LeadsAssignedNotification;
use App\Modules\CRM\Support\FieldInput;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\State;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Masters\Services\LedgerLookup;
use App\Modules\Masters\Services\PinCodeLookup;
use App\Modules\Masters\Services\UomConverter;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\Scopes\LocationScope;
use App\Modules\Shared\Rules\IndianMobile;
use App\Modules\Shared\Rules\IndianPinCode;
use App\Modules\Shared\Services\NumberSeriesService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Creates and changes leads. The single gate for lead data — the form, the spreadsheet import and (later) the mobile API
 * and the marketplace integrations all come through here — so the rules are the same whichever way a lead arrives.
 * It does not decide who may (LeadPolicy does). A lead saved from the field goes through FieldLeadService first, which
 * checks the position against today's route plan and only then comes here.
 *
 *  - A lead belongs to a branch the person can use, and is numbered from that branch's series in the same transaction
 *    that saves it. The branch never changes afterwards.
 *  - The mobile number is stored as its ten digits. **Duplicates are warned about, not blocked**: if an *open* lead in the
 *    company (any branch) already has the number, the save is refused until the person says "this is a separate inquiry".
 *  - A PIN code that is in the geography master fills the state, district and block where the answer is unambiguous, and
 *    is refused if it contradicts a district or state that was chosen.
 *  - Someone who may not hand out leads (`lead.assign`) always becomes the owner of what they enter; someone who may can
 *    give it to themselves, to a person they manage, or leave it unassigned. After that, a lead changes hands only
 *    through LeadTransferService.
 *  - Stages move by LeadStage's rules; "lost" needs a reason, re-opening needs a comment, and Deal / Quotation / Won are
 *    never set by hand (DealService sets them, through setStageBySystem()). Every move is written to the stage history.
 *  - **An inquiry from an existing customer** is a lead that names a customer (`lead.customer_ledger_id`): an approved,
 *    active customer ledger of the company, taken once when the inquiry is entered and never changed after. The customer's
 *    name, contact, mobile, e-mail and billing address are copied onto the lead as they are then (nothing typed for them is
 *    used), so the lead reads like any other. Such a customer may ask again and again, so there is no duplicate warning and
 *    no mobile number is needed; every line must name an approved item, and may carry the rate the customer expects. The
 *    expected value is the sum of the priced lines, worked out here. Once it is in approval or approved it is locked.
 *
 * Input: ['lead' => [location_id, lead_date, lead_source_id, customer_ledger_id, name, contact_person, mobile, phone, email,
 *                    address_line1, address_line2, city, state_id, district_id, block_id, pin_code, requirement,
 *                    expected_value, assigned_to, remarks, acknowledge_duplicate],
 *         'interests' => [[id?, item_id, description, quantity, unit_id, expected_rate], …]]
 */
final class LeadService
{
    public const MAX_INTERESTS = 30;

    /**
     * Entering a lead takes the branch's number-series lock first and nothing before it, so two entries queue rather
     * than deadlock. Should the database still choose one as a deadlock victim, entering it again is safe: nothing of
     * the failed attempt was kept, not even its number. (Only possible when this is the outermost transaction.)
     */
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SalesScope $scope,
        private readonly SalesTeamService $teams,
        private readonly NumberSeriesService $numbers,
        private readonly ItemLookup $items,
        private readonly PinCodeLookup $pins,
        private readonly LedgerLookup $ledgers,
        private readonly UomConverter $uoms,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array{latitude: float, longitude: float}|null  $position  where a field entry was made; only FieldLeadService passes it, after the geofence said yes
     * @param  bool  $incomplete  the sender gave less than a person must (a marketplace lead with no mobile number, or no real name): it stays incomplete until someone fills it in
     *
     * @throws ValidationException
     */
    public function create(array $input, User $actor, LeadChannel $channel = LeadChannel::Manual, ?int $importBatchId = null, ?array $position = null, bool $incomplete = false): Lead
    {
        $data = $this->validate($input, null, $actor, $channel);

        $lead = DB::transaction(function () use ($data, $actor, $channel, $importBatchId, $position, $incomplete) {
            $fields = $data['lead'];

            $lead = new Lead;
            $lead->fill($this->fillable($fields));
            $lead->forceFill([
                'company_id' => (int) $this->tenant->activeCompanyId(),
                'location_id' => $fields['location_id'],
                'lead_number' => $this->numbers->next(DocumentType::Lead, (int) $fields['location_id'], Carbon::parse($fields['lead_date'])),
                'lead_source_id' => $fields['lead_source_id'],
                'lead_type' => $fields['customer_ledger_id'] === null ? LeadType::New->value : LeadType::Existing->value,
                'customer_ledger_id' => $fields['customer_ledger_id'],
                'state_id' => $fields['state_id'], 'district_id' => $fields['district_id'], 'block_id' => $fields['block_id'],
                'assigned_to' => $fields['assigned_to'],
                'sales_team_id' => $fields['assigned_to'] === null ? null : $this->teamOf((int) $fields['assigned_to']),
                'stage' => LeadStage::New->value,
                'entry_channel' => $channel->value,
                'import_batch_id' => $importBatchId,
                'latitude' => $position === null ? null : FieldInput::coordinate($position['latitude']),
                'longitude' => $position === null ? null : FieldInput::coordinate($position['longitude']),
                'is_complete' => $this->isComplete($fields) && ! $incomplete,
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ])->save();

            $this->syncInterests($lead, $data['interests'], $actor);
            $this->recordStage($lead, null, LeadStage::New, $actor, null, null);

            if ($fields['assigned_to'] !== null) {
                $this->recordTransfer($lead, null, (int) $fields['assigned_to'], null, $lead->sales_team_id, 'Assigned when the lead was entered', $actor);
            }

            return $lead->refresh();
        }, self::DEADLOCK_ATTEMPTS);

        // Tell the owner, unless they entered it themselves (after the commit: no notice for a lead that was rolled back).
        if ($lead->assigned_to !== null && (int) $lead->assigned_to !== (int) $actor->getKey() && $channel !== LeadChannel::Import) {
            $owner = User::query()->find($lead->assigned_to);
            $owner?->notify(new LeadsAssignedNotification(1, $lead->title(), $actor->name, route('crm.leads.show', $lead, false)));
        }

        return $lead;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function update(Lead $lead, array $input, User $actor): Lead
    {
        if (! in_array($lead->stage, [LeadStage::New, LeadStage::Contacted, LeadStage::Qualified], true)) {
            throw new FieldRuleException('A lead that is '.strtolower($lead->stage->label()).' can no longer be edited.');
        }

        $this->assertUnlocked($lead);

        $data = $this->validate($input, $lead, $actor, $lead->entry_channel);

        return DB::transaction(function () use ($data, $lead, $actor) {
            $fields = $data['lead'];

            $lead->fill($this->fillable($fields));
            $lead->forceFill([
                'lead_source_id' => $fields['lead_source_id'],
                'state_id' => $fields['state_id'], 'district_id' => $fields['district_id'], 'block_id' => $fields['block_id'],
                'is_complete' => $this->isComplete($fields),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->syncInterests($lead, $data['interests'], $actor);

            return $lead->refresh();
        });
    }

    /**
     * An inquiry in approval or approved is what somebody signed off on: it changes only after it is re-opened. (Also asked by the API
     * before it asks the policy, so that a locked inquiry is answered with its reason, not a bare refusal.)
     *
     * @throws FieldRuleException
     */
    public function assertUnlocked(Lead $lead): void
    {
        if (! $lead->isEditableByWorkflow()) {
            throw new FieldRuleException('This inquiry is '.($lead->isPendingApproval() ? 'waiting for approval' : 'approved').', so it is locked. '
                .($lead->isPendingApproval() ? 'Ask for it to be returned to change it.' : 'Re-open it, with a reason, to change it.'));
        }
    }

    /**
     * Move a lead to another stage.
     *
     * @throws FieldRuleException when the move is not one a person can make
     * @throws ValidationException when a reason or comment that the move needs is missing
     */
    public function changeStage(Lead $lead, LeadStage $to, User $actor, ?string $comment = null, ?int $lostReasonId = null): Lead
    {
        $comment = $comment === null ? null : trim($comment);
        $comment = $comment === '' ? null : $comment;

        if ($comment !== null && mb_strlen($comment) > 1000) {
            throw ValidationException::withMessages(['comment' => 'The comment may not be longer than 1000 characters.']);
        }

        return DB::transaction(function () use ($lead, $to, $actor, $comment, $lostReasonId) {
            $fresh = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());
            $from = $fresh->stage;

            // A lead whose deal was lost is lost with it, and comes back only when the deal is re-opened.
            if ($from === LeadStage::Lost && $fresh->deal()->exists()) {
                throw new FieldRuleException('This lead’s deal was lost, so the lead follows it. Re-open the deal to bring the lead back.');
            }

            if (! $from->canMoveTo($to)) {
                throw new FieldRuleException($from === $to
                    ? 'The lead is already '.strtolower($to->label()).'.'
                    : 'A lead that is '.strtolower($from->label()).' cannot be moved to '.strtolower($to->label()).'.');
            }

            if ($to === LeadStage::Qualified && ! $fresh->is_complete) {
                throw new FieldRuleException('Complete the lead (name and mobile number) before qualifying it.');
            }

            $reason = null;

            if ($to === LeadStage::Lost) {
                $reason = $lostReasonId === null ? null : LostReason::query()->where('status', 'active')->find($lostReasonId);

                if ($reason === null) {
                    throw ValidationException::withMessages(['lost_reason_id' => 'Choose why the lead was lost.']);
                }

                // Nothing more is planned for a lost lead: call its pending follow-ups off (they stay on record).
                Followup::query()->where('subject_type', $fresh->getMorphClass())->where('subject_id', $fresh->getKey())
                    ->where('status', FollowupStatus::Pending->value)
                    ->update(['status' => FollowupStatus::Cancelled->value, 'updated_by' => $actor->getKey(), 'updated_at' => now()]);

                $fresh->forceFill(['lost_reason_id' => $reason->getKey(), 'lost_remarks' => $comment, 'lost_at' => now(), 'next_followup_at' => null]);
            }

            if ($from === LeadStage::Lost) {
                if ($comment === null || mb_strlen($comment) < 3) {
                    throw ValidationException::withMessages(['comment' => 'Please say why the lead is being re-opened.']);
                }

                $fresh->forceFill(['lost_reason_id' => null, 'lost_remarks' => null, 'lost_at' => null]);
            }

            $fresh->forceFill(['stage' => $to->value, 'updated_by' => $actor->getKey()])->save();
            $this->recordStage($fresh, $from, $to, $actor, $comment, $reason?->getKey());

            return $fresh->refresh();
        });
    }

    /**
     * Remove a lead that should not be there (entered by mistake, a test). It is a soft delete: the lead, its history and
     * its number stay in the database and in the audit log, and the number is never used again. What was still planned
     * for it is called off, so nobody is reminded about a lead that is gone.
     *
     * @throws FieldRuleException
     */
    public function delete(Lead $lead, User $actor): void
    {
        DB::transaction(function () use ($lead, $actor) {
            $fresh = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            if ($fresh->stage->isSetBySystem() || $fresh->deal()->exists()) {
                throw new FieldRuleException('A lead that has been converted to a deal cannot be deleted.');
            }

            if (! $fresh->isEditableByWorkflow()) {
                throw new FieldRuleException('An inquiry that is waiting for approval or approved cannot be deleted. Mark it as lost instead.');
            }

            Followup::query()->where('subject_type', $fresh->getMorphClass())->where('subject_id', $fresh->getKey())
                ->where('status', FollowupStatus::Pending->value)
                ->update(['status' => FollowupStatus::Cancelled->value, 'updated_by' => $actor->getKey(), 'updated_at' => now()]);

            $fresh->forceFill(['next_followup_at' => null, 'updated_by' => $actor->getKey()])->save();
            $fresh->delete();
        });
    }

    /**
     * Other leads in the company — in any branch — with the same mobile number, newest first. Used for the duplicate
     * warning; it deliberately looks beyond what the person may otherwise see, and returns only what the warning shows.
     *
     * @return Collection<int, Lead>
     */
    public function duplicates(?string $mobile, ?int $ignoreId = null): Collection
    {
        $mobile = $mobile === null ? null : IndianMobile::normalise($mobile);

        if ($mobile === null) {
            return new Collection;
        }

        return Lead::query()
            ->withoutGlobalScope(LocationScope::class)
            // the branch's name is part of the warning even when it is a branch the person does not work in
            ->with(['assignee:id,name', 'location' => fn ($q) => $q->withoutGlobalScope(LocationScope::class)->select(['id', 'name'])])
            ->where('mobile', $mobile)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'company_id', 'location_id', 'lead_number', 'name', 'stage', 'assigned_to', 'created_by', 'lead_date', 'mobile']);
    }

    /** Keep the lead's "next follow-up" in step with its planned follow-ups (no audit entry: it is derived). */
    public function refreshNextFollowup(Lead $lead): void
    {
        $next = Followup::query()->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->getKey())
            ->where('status', FollowupStatus::Pending->value)->min('scheduled_at');

        Lead::query()->whereKey($lead->getKey())->update(['next_followup_at' => $next]);
        $lead->setRawAttributes([...$lead->getAttributes(), 'next_followup_at' => $next], true);
    }

    /** The team a person is on today, if any. */
    public function teamOf(int $userId): ?int
    {
        $teamId = SalesTeamMember::query()->where('user_id', $userId)->whereNull('left_on')->value('sales_team_id');

        return $teamId === null ? null : (int) $teamId;
    }

    public function recordTransfer(Lead $lead, ?int $fromUserId, int $toUserId, ?int $fromTeamId, ?int $toTeamId, string $reason, User $actor): LeadTransfer
    {
        $transfer = new LeadTransfer;
        $transfer->forceFill([
            'company_id' => (int) $lead->company_id, 'lead_id' => $lead->getKey(),
            'from_user_id' => $fromUserId, 'to_user_id' => $toUserId, 'from_team_id' => $fromTeamId, 'to_team_id' => $toTeamId,
            'reason' => $reason, 'transferred_by' => $actor->getKey(), 'created_at' => now(),
        ])->save();

        return $transfer;
    }

    // ---- validation -------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @return array{lead: array<string, mixed>, interests: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function validate(array $input, ?Lead $editing, User $actor, LeadChannel $channel): array
    {
        $data = $this->normalise($input);
        $today = now()->toDateString();

        // What kind of lead this is is fixed when it is entered: an inquiry names its customer, and never changes it.
        $existing = $editing !== null ? $editing->isExisting() : $data['lead']['customer_ledger_id'] !== null;

        if ($existing) {
            $data['lead'] = $this->customerFields($data['lead'], $editing);
        }

        // A marketplace or website lead may arrive without a number; a person entering or importing one must give it.
        // (A customer's inquiry takes the contact from the ledger, which may have none.)
        $mobileRequired = ! $existing && in_array($channel, [LeadChannel::Manual, LeadChannel::Import, LeadChannel::Field], true);

        $rules = [
            'lead.location_id' => [$editing === null ? 'required' : 'nullable', 'integer'],
            'lead.lead_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.$today],
            'lead.lead_source_id' => ['required', 'integer'],
            'lead.name' => ['required', 'string', 'max:200'],
            'lead.contact_person' => ['nullable', 'string', 'max:150'],
            'lead.mobile' => [$mobileRequired ? 'required' : 'nullable', new IndianMobile],
            'lead.phone' => ['nullable', 'string', 'regex:/^[0-9+\-() ]{5,20}$/'],
            'lead.email' => ['nullable', 'string', 'email', 'max:150'],
            'lead.address_line1' => ['nullable', 'string', 'max:200'],
            'lead.address_line2' => ['nullable', 'string', 'max:200'],
            'lead.city' => ['nullable', 'string', 'max:100'],
            'lead.pin_code' => ['nullable', new IndianPinCode],
            'lead.state_id' => ['nullable', 'integer'],
            'lead.district_id' => ['nullable', 'integer'],
            'lead.block_id' => ['nullable', 'integer'],
            'lead.requirement' => ['nullable', 'string', 'max:2000'],
            'lead.expected_value' => ['nullable', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'lead.assigned_to' => ['nullable', 'integer'],
            'lead.remarks' => ['nullable', 'string', 'max:1000'],
            'interests' => ['array', 'max:'.self::MAX_INTERESTS],
        ];

        foreach ($data['interests'] as $i => $row) {
            $rules["interests.$i.id"] = ['nullable', 'integer'];
            $rules["interests.$i.item_id"] = [$existing ? 'required' : 'nullable', 'integer'];
            $rules["interests.$i.description"] = ['nullable', 'string', 'max:200'];
            $rules["interests.$i.quantity"] = [$existing ? 'required' : 'nullable', 'bail', 'regex:/^\d{1,12}(\.\d{1,3})?$/', fn (string $a, mixed $v, \Closure $fail) => bccomp((string) $v, '0', 3) > 0 ? null : $fail('The quantity must be more than zero.')];
            $rules["interests.$i.unit_id"] = ['nullable', 'integer'];
            $rules["interests.$i.expected_rate"] = ['nullable', 'regex:/^\d{1,13}(\.\d{1,2})?$/'];
        }

        $validator = Validator::make($data, $rules, [
            'lead.lead_date.before_or_equal' => 'The lead date cannot be in the future.',
            'lead.lead_date.date_format' => 'Enter the lead date as a valid date.',
            'lead.phone.regex' => 'Enter the other phone number using digits, spaces, + and - only.',
            'lead.expected_value.regex' => 'Enter the expected value as a number with at most 2 decimals, e.g. 250000.',
            'lead.mobile.required' => 'A mobile number is needed: it is how duplicates are spotted.',
            'interests.max' => 'A lead can list at most '.self::MAX_INTERESTS.' items of interest.',
            'interests.*.quantity.regex' => 'Enter the quantity as a number with at most 3 decimals.',
            'interests.*.quantity.required' => 'Enter how many the customer wants.',
            'interests.*.item_id.required' => 'Choose the item the customer asked for.',
            'interests.*.expected_rate.regex' => 'Enter the rate as a number with at most 2 decimals, e.g. 1250.50.',
        ], [
            'lead.location_id' => 'branch', 'lead.lead_source_id' => 'source', 'lead.name' => 'name', 'lead.mobile' => 'mobile number',
            'lead.email' => 'e-mail address', 'lead.pin_code' => 'PIN code', 'lead.lead_date' => 'lead date', 'lead.assigned_to' => 'owner',
        ]);

        $validator->after(function (\Illuminate\Validation\Validator $v) use (&$data, $editing, $actor, $existing) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $data['lead'] = $this->crossChecks($v, $data['lead'], $editing, $actor, $existing);
            $data['interests'] = $this->checkInterests($v, $data['interests'], $editing, $existing);

            // An inquiry's value is what its lines come to, never what was typed.
            if ($existing && $v->errors()->isEmpty()) {
                $data['lead']['expected_value'] = $this->linesValue($data['interests']);
            }
        });

        $validator->validate();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $lead
     * @return array<string, mixed> the fields, completed (branch, owner, geography, mobile as ten digits)
     */
    private function crossChecks(\Illuminate\Validation\Validator $v, array $lead, ?Lead $editing, User $actor, bool $existing = false): array
    {
        $errors = $v->errors();
        $lead['mobile'] = $lead['mobile'] === null ? null : IndianMobile::normalise($lead['mobile']);

        // ---- branch: chosen once, when the lead is entered ----
        if ($editing !== null) {
            $lead['location_id'] = (int) $editing->location_id;
        } else {
            // The branch must be one the *person entering the lead* works in. That is asked about them explicitly rather
            // than left to the ambient branch scope, because an import runs in a job where nobody is signed in.
            $branch = Location::query()->find((int) $lead['location_id']);
            $reach = $this->tenant->accessibleLocationIdsFor($actor, (int) $this->tenant->activeCompanyId());

            if ($branch === null || $branch->status !== 'active' || ($reach !== null && ! in_array((int) $branch->getKey(), $reach, true))) {
                $errors->add('lead.location_id', 'Choose one of your active branches.');
            }

            $lead['location_id'] = (int) $lead['location_id'];
        }

        // ---- source ----
        $source = LeadSource::query()->find((int) $lead['lead_source_id']);

        if ($source === null || ($source->status !== 'active' && (int) $editing?->lead_source_id !== (int) $source->getKey())) {
            $errors->add('lead.lead_source_id', 'Choose where the lead came from.');
        }

        $lead['lead_source_id'] = (int) $lead['lead_source_id'];

        // A customer's address comes from the ledger as it is: it is not checked against the PIN code master again here.
        if (! $existing) {
            $lead = $this->checkGeography($v, $lead);
        }

        $lead = $this->checkOwner($v, $lead, $editing, $actor);

        // ---- duplicates: warn, and ask — but a customer is allowed to ask again ----
        if (! $existing && $lead['mobile'] !== null && ! $lead['acknowledge_duplicate']) {
            $open = $this->duplicates($lead['mobile'], $editing?->getKey())->filter(fn (Lead $other) => $other->isOpen());

            if ($open->isNotEmpty()) {
                $first = $open->first();
                $who = $first->assignee->name ?? 'nobody yet';
                $more = $open->count() > 1 ? ' and '.($open->count() - 1).' more' : '';

                $errors->add('lead.mobile', "An open lead already has this mobile number: {$first->lead_number} ({$first->stage->label()}, with {$who}){$more}. If this is a separate inquiry, tick “save as a separate lead”.");
            }
        }

        return $lead;
    }

    /**
     * @param  array<string, mixed>  $lead
     * @return array<string, mixed>
     */
    private function checkGeography(\Illuminate\Validation\Validator $v, array $lead): array
    {
        $errors = $v->errors();

        $block = $lead['block_id'] === null ? null : Block::query()->find((int) $lead['block_id']);
        $district = $lead['district_id'] === null ? null : District::query()->find((int) $lead['district_id']);
        $state = $lead['state_id'] === null ? null : State::query()->find((int) $lead['state_id']);

        if ($lead['block_id'] !== null && $block === null) {
            $errors->add('lead.block_id', 'Choose a block from the list.');
        }
        if ($lead['district_id'] !== null && $district === null) {
            $errors->add('lead.district_id', 'Choose a district from the list.');
        }
        if ($lead['state_id'] !== null && $state === null) {
            $errors->add('lead.state_id', 'Choose a state from the list.');
        }

        if ($errors->isNotEmpty()) {
            return $lead;
        }

        // The narrower choice fixes the wider one: a block names its district, a district its state.
        if ($block !== null) {
            if ($district !== null && (int) $block->district_id !== (int) $district->getKey()) {
                $errors->add('lead.block_id', "{$block->name} is not a block of {$district->name}.");
            }
            $district ??= District::query()->find($block->district_id);
        }

        if ($district !== null) {
            if ($state !== null && (int) $district->state_id !== (int) $state->getKey()) {
                $errors->add('lead.district_id', "{$district->name} is not a district of {$state->name}.");
            }
            $state ??= State::query()->find($district->state_id);
        }

        // A PIN code the master knows fills what was left blank, and may not contradict what was chosen.
        if ($lead['pin_code'] !== null && $errors->isEmpty()) {
            $found = $this->pins->resolve($lead['pin_code']);

            if ($found->found) {
                if ($found->state !== null && $state !== null && (int) $found->state->getKey() !== (int) $state->getKey()) {
                    $errors->add('lead.pin_code', "PIN {$lead['pin_code']} is in {$found->state->name}, not {$state->name}.");
                } elseif ($found->district !== null && $district !== null && (int) $found->district->getKey() !== (int) $district->getKey()) {
                    $errors->add('lead.pin_code', "PIN {$lead['pin_code']} is in {$found->district->name}, not {$district->name}.");
                } else {
                    $state ??= $found->state;
                    $district ??= $found->district;
                    $block ??= ($found->block !== null && ($district === null || (int) $found->block->district_id === (int) $district->getKey())) ? $found->block : null;
                }
            }
        }

        $lead['state_id'] = $state?->getKey();
        $lead['district_id'] = $district?->getKey();
        $lead['block_id'] = $block?->getKey();

        return $lead;
    }

    /**
     * @param  array<string, mixed>  $lead
     * @return array<string, mixed>
     */
    private function checkOwner(\Illuminate\Validation\Validator $v, array $lead, ?Lead $editing, User $actor): array
    {
        // Ownership is set when the lead is entered and afterwards changes only through a transfer (which keeps the history).
        if ($editing !== null) {
            $lead['assigned_to'] = $editing->assigned_to;

            return $lead;
        }

        if (! $actor->can('lead.assign')) {
            $lead['assigned_to'] = (int) $actor->getKey();   // whatever was sent: someone who cannot hand leads out owns what they enter
        } elseif ($lead['assigned_to'] !== null) {
            $ownerId = (int) $lead['assigned_to'];

            if (! array_key_exists($ownerId, $this->teams->staff())) {
                $v->errors()->add('lead.assigned_to', 'Choose an active user of this company.');

                return $lead;
            }

            if ($ownerId !== (int) $actor->getKey() && ! $this->scope->manages($actor, $ownerId)) {
                $v->errors()->add('lead.assigned_to', 'You can give leads only to yourself or to people on the teams you manage.');

                return $lead;
            }

            $lead['assigned_to'] = $ownerId;
        }

        if ($lead['assigned_to'] !== null && $v->errors()->isEmpty()) {
            $their = $this->tenant->accessibleLocationIdsFor(User::query()->findOrFail($lead['assigned_to']), (int) $this->tenant->activeCompanyId());

            if ($their !== null && ! in_array((int) $lead['location_id'], $their, true)) {
                $v->errors()->add('lead.assigned_to', 'That person does not work in the lead’s branch.');
            }
        }

        return $lead;
    }

    /**
     * @param  list<array<string, mixed>>  $interests
     * @return list<array<string, mixed>>
     */
    private function checkInterests(\Illuminate\Validation\Validator $v, array $interests, ?Lead $editing, bool $inquiry = false): array
    {
        $existing = $editing === null ? collect() : $editing->interests()->get()->keyBy('id');
        $seen = [];

        foreach ($interests as $i => $row) {
            $current = null;

            if ($row['id'] !== null) {
                $current = $existing->get((int) $row['id']);

                if ($current === null || isset($seen[(int) $row['id']])) {
                    $v->errors()->add("interests.$i.id", 'This row does not belong to the lead.');
                }
                $seen[(int) $row['id']] = true;
            }

            if ($row['item_id'] === null && $row['description'] === null) {
                $v->errors()->add("interests.$i.description", 'Choose an item, or describe what they are interested in.');

                continue;
            }

            // A customer's inquiry is made of items from the master: a description in words has nothing to approve or price.
            if ($inquiry && $row['item_id'] === null) {
                $v->errors()->add("interests.$i.item_id", 'Choose the item the customer asked for from the item master.');

                continue;
            }

            $item = null;

            if ($row['item_id'] !== null) {
                // approved and active — or the one already on this row, which may have been switched off since
                $item = $this->items->usable((int) $row['item_id']);

                if ($item === null && (int) $current?->item_id !== (int) $row['item_id']) {
                    $v->errors()->add("interests.$i.item_id", 'Choose an approved, active item, or describe it in words instead.');

                    continue;
                }
            }

            if ($row['unit_id'] !== null) {
                $unitId = (int) $row['unit_id'];
                $allowed = $item === null
                    ? Unit::query()->whereKey($unitId)->exists()
                    : $unitId === (int) $item->base_unit_id || $item->uoms->contains(fn ($uom) => (int) $uom->unit_id === $unitId);

                if (! $allowed) {
                    $v->errors()->add("interests.$i.unit_id", $item === null ? 'Choose a unit from the list.' : "{$item->name} is not sold in that unit.");
                }
            } elseif ($item !== null) {
                $interests[$i]['unit_id'] = (int) $item->base_unit_id;   // no unit given: the item's own
            }

            $interests[$i]['item_id'] = $row['item_id'] === null ? null : (int) $row['item_id'];
            $interests[$i]['quantity'] = $row['quantity'] === null ? null : bcadd((string) $row['quantity'], '0', 3);

            if ($inquiry) {
                // A unit that cannot be split (Nos, Box…) is asked for in whole numbers only.
                $unit = $interests[$i]['unit_id'] === null ? null : Unit::query()->find((int) $interests[$i]['unit_id']);

                if ($unit !== null && $interests[$i]['quantity'] !== null && ! $this->uoms->accepts($unit, $interests[$i]['quantity'])) {
                    $v->errors()->add("interests.$i.quantity", "{$unit->name} cannot be split: enter a whole number.");
                }

                $interests[$i]['expected_rate'] = $row['expected_rate'] === null ? null : bcadd((string) $row['expected_rate'], '0', 2);
            } else {
                $interests[$i]['expected_rate'] = null;   // a new lead's items of interest are never priced
            }
        }

        return $interests;
    }

    /**
     * What an inquiry's lines come to: quantity × expected rate for each line that has a rate, each rounded half up to
     * paise, then added. Null when no line has a rate yet.
     *
     * @param  list<array<string, mixed>>  $interests
     */
    private function linesValue(array $interests): ?string
    {
        $total = null;

        foreach ($interests as $row) {
            if ($row['expected_rate'] === null || $row['quantity'] === null) {
                continue;
            }

            $amount = bcadd(bcmul((string) $row['quantity'], (string) $row['expected_rate'], 6), '0.005', 2);
            $total = bcadd($total ?? '0', $amount, 2);
        }

        return $total;
    }

    /**
     * The fields an inquiry takes from its customer — or, once entered, keeps as they were: the customer is chosen when the
     * inquiry is entered and never changes, so editing it only touches what the person may change (date, source, lines…).
     * What was typed for the customer's own fields is ignored either way.
     *
     * @param  array<string, mixed>  $lead
     * @return array<string, mixed>
     *
     * @throws ValidationException when the customer is not one a transaction may use
     */
    private function customerFields(array $lead, ?Lead $editing): array
    {
        if ($editing !== null) {
            return [...$lead,
                'customer_ledger_id' => (int) $editing->customer_ledger_id,
                'name' => $editing->name, 'contact_person' => $editing->contact_person, 'mobile' => $editing->mobile, 'phone' => $editing->phone,
                'email' => $editing->email, 'address_line1' => $editing->address_line1, 'address_line2' => $editing->address_line2,
                'city' => $editing->city, 'state_id' => $editing->state_id, 'district_id' => $editing->district_id, 'block_id' => $editing->block_id,
                'pin_code' => $editing->pin_code,
            ];
        }

        $customer = $this->ledgers->usable((int) $lead['customer_ledger_id'], LedgerType::Customer);

        if ($customer === null) {
            throw ValidationException::withMessages(['lead.customer_ledger_id' => 'Choose a customer that is approved and active.']);
        }

        $customer->loadMissing(['addresses', 'contacts']);

        $contact = $customer->contacts->firstWhere('is_primary', true) ?? $customer->contacts->first();
        $address = $customer->addresses->firstWhere('is_default_billing', true) ?? $customer->addresses->firstWhere('is_billing', true) ?? $customer->addresses->first();
        $phone = $customer->phone ?? $contact?->phone;
        $contactMobile = $contact?->mobile;

        return [...$lead,
            'customer_ledger_id' => (int) $customer->getKey(),
            'name' => $customer->name,
            'contact_person' => $contact?->name,
            'mobile' => $contactMobile === null ? null : IndianMobile::normalise($contactMobile),
            'phone' => $phone !== null && preg_match('/^[0-9+\-() ]{5,20}$/', $phone) === 1 ? $phone : null,
            'email' => $customer->email ?? $contact?->email,
            'address_line1' => $address?->line1, 'address_line2' => $address?->line2, 'city' => $address?->city,
            'state_id' => $address?->state_id, 'district_id' => $address?->district_id, 'block_id' => null,
            'pin_code' => $address?->pin_code,
        ];
    }

    /**
     * Put a lead into a stage that only a conversion may set — Deal, Quotation, Won, or Lost with its deal — and write it to the
     * stage history. The caller (DealService, later the quotation and order services) has already decided the move is allowed;
     * nobody calls this from a screen. Returns the lead as it now stands.
     *
     * @param  int|null  $lostReasonId  the reason when the move is to Lost
     */
    public function setStageBySystem(Lead $lead, LeadStage $to, ?User $actor, string $comment, ?int $lostReasonId = null): Lead
    {
        return DB::transaction(function () use ($lead, $to, $actor, $comment, $lostReasonId) {
            $fresh = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());
            $from = $fresh->stage;

            if ($from === $to) {
                return $fresh;
            }

            if ($to === LeadStage::Lost) {
                // Nothing more is planned for a lead whose deal was lost: its pending follow-ups are called off (they stay on record).
                Followup::query()->where('subject_type', $fresh->getMorphClass())->where('subject_id', $fresh->getKey())
                    ->where('status', FollowupStatus::Pending->value)
                    ->update(['status' => FollowupStatus::Cancelled->value, 'updated_by' => $actor?->getKey(), 'updated_at' => now()]);

                $fresh->forceFill(['lost_reason_id' => $lostReasonId, 'lost_remarks' => $comment, 'lost_at' => now(), 'next_followup_at' => null]);
            } elseif ($from === LeadStage::Lost) {
                $fresh->forceFill(['lost_reason_id' => null, 'lost_remarks' => null, 'lost_at' => null]);
            }

            $fresh->forceFill(['stage' => $to->value, 'updated_by' => $actor?->getKey()])->save();
            $this->recordStage($fresh, $from, $to, $actor, $comment, $to === LeadStage::Lost ? $lostReasonId : null);

            return $fresh->refresh();
        });
    }

    // ---- persistence ------------------------------------------------------------------------------------------

    /** @param  list<array<string, mixed>>  $rows */
    private function syncInterests(Lead $lead, array $rows, User $actor): void
    {
        $existing = $lead->interests()->get()->keyBy('id');
        $kept = [];

        foreach ($rows as $row) {
            $model = $row['id'] !== null ? $existing->get((int) $row['id']) : null;
            $model ??= new LeadInterest;

            $model->fill(['description' => $row['description'], 'quantity' => $row['quantity']]);
            $model->forceFill([
                'item_id' => $row['item_id'], 'unit_id' => $row['unit_id'] === null ? null : (int) $row['unit_id'],
                'expected_rate' => $row['expected_rate'] ?? null, 'updated_by' => $actor->getKey(),
            ]);

            if (! $model->exists) {
                $model->forceFill(['company_id' => (int) $lead->company_id, 'lead_id' => $lead->getKey(), 'created_by' => $actor->getKey()]);
            }

            $model->save();
            $kept[] = $model->getKey();
        }

        foreach ($existing as $id => $interest) {
            if (! in_array($id, $kept, true)) {
                $interest->forceDelete();
            }
        }
    }

    private function recordStage(Lead $lead, ?LeadStage $from, LeadStage $to, ?User $actor, ?string $comment, ?int $lostReasonId): void
    {
        $entry = new LeadStageEntry;
        $entry->forceFill([
            'company_id' => (int) $lead->company_id, 'lead_id' => $lead->getKey(), 'from_stage' => $from?->value, 'to_stage' => $to->value,
            'lost_reason_id' => $lostReasonId, 'comment' => $comment, 'actor_id' => $actor?->getKey(), 'created_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function fillable(array $fields): array
    {
        return [
            'lead_date' => $fields['lead_date'], 'name' => $fields['name'], 'contact_person' => $fields['contact_person'],
            'mobile' => $fields['mobile'], 'phone' => $fields['phone'], 'email' => $fields['email'] === null ? null : mb_strtolower($fields['email']),
            'address_line1' => $fields['address_line1'], 'address_line2' => $fields['address_line2'], 'city' => $fields['city'],
            'pin_code' => $fields['pin_code'], 'requirement' => $fields['requirement'],
            'expected_value' => $fields['expected_value'] === null ? null : bcadd((string) $fields['expected_value'], '0', 2),
            'remarks' => $fields['remarks'],
        ];
    }

    /** @param  array<string, mixed>  $fields */
    private function isComplete(array $fields): bool
    {
        // a customer is known by the ledger: an inquiry needs no mobile number
        return $fields['name'] !== null && ($fields['mobile'] !== null || $fields['customer_ledger_id'] !== null);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{lead: array<string, mixed>, interests: list<array<string, mixed>>}
     */
    private function normalise(array $input): array
    {
        $text = fn (mixed $v): ?string => ($v = trim((string) preg_replace('/\s+/u', ' ', (string) ($v ?? '')))) === '' ? null : $v;
        $long = fn (mixed $v): ?string => ($v = trim((string) ($v ?? ''))) === '' ? null : $v;
        $lead = (array) ($input['lead'] ?? []);

        $interests = [];
        foreach (array_values((array) ($input['interests'] ?? [])) as $row) {
            $row = (array) $row;

            // an untouched blank row of the form is not an interest
            if ($text($row['item_id'] ?? null) === null && $text($row['description'] ?? null) === null && $text($row['quantity'] ?? null) === null
                && $text($row['expected_rate'] ?? null) === null && $text($row['id'] ?? null) === null) {
                continue;
            }

            $interests[] = [
                'id' => $text($row['id'] ?? null), 'item_id' => $text($row['item_id'] ?? null), 'description' => $text($row['description'] ?? null),
                'quantity' => $text(str_replace(',', '', (string) ($row['quantity'] ?? ''))), 'unit_id' => $text($row['unit_id'] ?? null),
                'expected_rate' => $text(str_replace(',', '', (string) ($row['expected_rate'] ?? ''))),
            ];
        }

        return [
            'lead' => [
                'location_id' => $text($lead['location_id'] ?? null),
                'lead_date' => $text($lead['lead_date'] ?? null) ?? now()->toDateString(),
                'lead_source_id' => $text($lead['lead_source_id'] ?? null),
                'customer_ledger_id' => $text($lead['customer_ledger_id'] ?? null),
                'name' => $text($lead['name'] ?? null),
                'contact_person' => $text($lead['contact_person'] ?? null),
                'mobile' => $text($lead['mobile'] ?? null),
                'phone' => $text($lead['phone'] ?? null),
                'email' => $text($lead['email'] ?? null),
                'address_line1' => $text($lead['address_line1'] ?? null),
                'address_line2' => $text($lead['address_line2'] ?? null),
                'city' => $text($lead['city'] ?? null),
                'state_id' => $text($lead['state_id'] ?? null),
                'district_id' => $text($lead['district_id'] ?? null),
                'block_id' => $text($lead['block_id'] ?? null),
                'pin_code' => $text($lead['pin_code'] ?? null),
                'requirement' => $long($lead['requirement'] ?? null),
                'expected_value' => $text(str_replace(',', '', (string) ($lead['expected_value'] ?? ''))),
                'assigned_to' => $text($lead['assigned_to'] ?? null),
                'remarks' => $long($lead['remarks'] ?? null),
                'acknowledge_duplicate' => filter_var($lead['acknowledge_duplicate'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ],
            'interests' => $interests,
        ];
    }
}

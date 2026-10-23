<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Enums\LeadChannel;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Enums\LeadType;
use App\Modules\CRM\Policies\LeadPolicy;
use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\State;
use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Concerns\Approvable;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * A lead: somebody who might buy, where they came from, who is working on them and how far it has got.
 *
 * A lead belongs to a branch and to the person it is assigned to. It is numbered from the branch's own series, moves
 * through stages (LeadStage), collects follow-ups, and changes hands only through LeadTransferService, which keeps the
 * whole history. Who may see a lead is its assignee, the people who manage that person, and holders of `sales.scope_all`
 * (see LeadPolicy and SalesScope).
 *
 * A lead is either **new** (a prospect) or an **inquiry from an existing customer** (`lead_type`, see LeadType): the latter
 * points at an approved customer ledger, lists what the customer asked for with the rate they expect, and — alone of the
 * two — goes through the approval workflow. Either converts to a Deal (DealService), which takes the lead to the Deal stage.
 *
 * @property string $lead_number
 * @property LeadType $lead_type
 * @property int|null $customer_ledger_id
 * @property Carbon $lead_date
 * @property int $location_id
 * @property int $lead_source_id
 * @property string $name
 * @property string|null $contact_person
 * @property string|null $mobile
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property int|null $state_id
 * @property int|null $district_id
 * @property int|null $block_id
 * @property string|null $pin_code
 * @property string|null $requirement
 * @property string|null $expected_value
 * @property int|null $assigned_to
 * @property int|null $sales_team_id
 * @property LeadStage $stage
 * @property int|null $lost_reason_id
 * @property string|null $lost_remarks
 * @property Carbon|null $lost_at
 * @property Carbon|null $next_followup_at
 * @property Carbon|null $last_contacted_at
 * @property string|null $latitude
 * @property string|null $longitude
 * @property bool $is_complete
 * @property LeadChannel $entry_channel
 * @property int|null $import_batch_id
 */
#[Fillable([
    'lead_date', 'name', 'contact_person', 'mobile', 'phone', 'email', 'address_line1', 'address_line2', 'city', 'pin_code',
    'requirement', 'expected_value', 'remarks',
])]
#[UsePolicy(LeadPolicy::class)]
class Lead extends TenantModel implements ApprovableDocument
{
    use Approvable, BelongsToLocation, ValidatesParentCompany;

    /** A lead has no active/inactive `status`: its stage says where it stands. */
    protected $attributes = [
        'stage' => 'new',
        'lead_type' => 'new',
        'is_complete' => true,
        'entry_channel' => 'manual',
    ];

    protected function casts(): array
    {
        return [
            'lead_date' => 'date:Y-m-d',
            'expected_value' => 'decimal:2',
            'stage' => LeadStage::class,
            'lead_type' => LeadType::class,
            'entry_channel' => LeadChannel::class,
            'lost_at' => 'datetime',
            'next_followup_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'utm' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_complete' => 'boolean',
        ];
    }

    /** @return BelongsTo<LeadSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    /** @return BelongsTo<Ledger, $this> the approved customer an existing-customer inquiry is from */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'customer_ledger_id');
    }

    /** @return HasOne<Deal, $this> the deal this lead was converted to, if it has been */
    public function deal(): HasOne
    {
        return $this->hasOne(Deal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<SalesTeam, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(SalesTeam::class, 'sales_team_id');
    }

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** @return BelongsTo<Block, $this> */
    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    /** @return BelongsTo<LostReason, $this> */
    public function lostReason(): BelongsTo
    {
        return $this->belongsTo(LostReason::class);
    }

    /** @return HasMany<LeadInterest, $this> */
    public function interests(): HasMany
    {
        return $this->hasMany(LeadInterest::class)->orderBy('id');
    }

    /** @return MorphMany<Followup, $this> */
    public function followups(): MorphMany
    {
        return $this->morphMany(Followup::class, 'subject')->orderByDesc('scheduled_at')->orderByDesc('id');
    }

    /** @return HasMany<LeadTransfer, $this> */
    public function transfers(): HasMany
    {
        return $this->hasMany(LeadTransfer::class)->orderByDesc('id');
    }

    /** @return HasMany<LeadStageEntry, $this> */
    public function stageHistory(): HasMany
    {
        return $this->hasMany(LeadStageEntry::class)->orderByDesc('id');
    }

    /**
     * Leads still being worked (not Won, not Lost).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn($this->qualifyColumn('stage'), [LeadStage::Won->value, LeadStage::Lost->value]);
    }

    public function isOpen(): bool
    {
        return $this->stage->isOpen();
    }

    /** "LD/AHD/26-27/00012 — Shree Ganesh Traders", for notifications and pickers. */
    public function title(): string
    {
        return "{$this->lead_number} — {$this->name}";
    }

    /** Is the next planned follow-up already late? */
    public function isOverdue(): bool
    {
        return $this->next_followup_at !== null && $this->next_followup_at->isPast() && $this->isOpen();
    }

    /** An inquiry from an existing customer (as opposed to a new lead). */
    public function isExisting(): bool
    {
        return $this->lead_type === LeadType::Existing;
    }

    /** Has it been converted to a deal? (A lead converts once.) */
    public function isConverted(): bool
    {
        return $this->stage->isSetBySystem();
    }

    /**
     * An existing-customer inquiry that has been approved and is still being worked: what a quotation can be made from
     * directly, without a deal in between (Phase 11).
     */
    public function isReadyForQuotation(): bool
    {
        return $this->isExisting() && $this->isApproved() && $this->isOpen();
    }

    public function approvalDocumentType(): DocumentType
    {
        return DocumentType::Lead;
    }

    public function approvalUrl(): ?string
    {
        return route('crm.leads.show', $this);
    }

    public function approvalTitle(): string
    {
        return "Inquiry {$this->lead_number} — {$this->name}";
    }

    /**
     * Only an inquiry from an existing customer goes through approval, and only while it is being worked, with the customer
     * still one a transaction may use and every line complete: an item, a unit, a quantity and the rate expected.
     *
     * @throws ApprovalStateException
     */
    public function beforeSubmitForApproval(): void
    {
        if (! $this->isExisting()) {
            throw ApprovalStateException::notReady('Only an inquiry from an existing customer goes through approval; a new lead is simply worked until it is qualified.');
        }

        if (! in_array($this->stage, [LeadStage::New, LeadStage::Contacted, LeadStage::Qualified], true)) {
            throw ApprovalStateException::notReady('An inquiry that is '.strtolower($this->stage->label()).' can no longer be sent for approval.');
        }

        if ($this->customer === null || ! $this->customer->isUsable()) {
            throw ApprovalStateException::notReady('The customer is no longer approved and active, so the inquiry cannot be sent for approval.');
        }

        $lines = $this->interests()->get();

        if ($lines->isEmpty()) {
            throw ApprovalStateException::notReady('List at least one item the customer asked for before sending the inquiry for approval.');
        }

        foreach ($lines as $i => $line) {
            $n = $i + 1;

            if ($line->item_id === null || $line->unit_id === null || $line->quantity === null || $line->expected_rate === null) {
                throw ApprovalStateException::notReady("Line {$n} needs an item, a unit, a quantity and the rate expected before the inquiry can be sent for approval.");
            }
        }
    }

    protected function companyScopedParents(): array
    {
        return [
            'lead_source_id' => LeadSource::class, 'state_id' => State::class, 'district_id' => District::class, 'block_id' => Block::class,
            'sales_team_id' => SalesTeam::class, 'lost_reason_id' => LostReason::class, 'customer_ledger_id' => Ledger::class,
        ];
    }

    /**
     * What an approver signs off on for an inquiry: whose it is and every line of it. Where it stands (stage, owner, remarks,
     * follow-ups) moves on without a new approval.
     *
     * @return array<string, mixed>
     */
    protected function approvalFingerprintParts(): array
    {
        return [
            'lead' => Arr::only($this->getAttributes(), ['company_id', 'location_id', 'lead_type', 'customer_ledger_id', 'lead_source_id', 'requirement']),
            'interests' => $this->interests()->get()->map(fn (LeadInterest $line) => Arr::only($line->getAttributes(), ['item_id', 'description', 'unit_id', 'quantity', 'expected_rate']))->all(),
        ];
    }

    protected function auditLogName(): string
    {
        return 'leads';
    }
}

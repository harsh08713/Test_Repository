<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Policies\DealPolicy;
use App\Modules\Masters\Models\Ledger;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * A deal: a qualified lead taken up as a piece of business — what is wanted (its lines), for whom, how likely and by when.
 *
 * It is made only by converting a lead (DealService::convert) and keeps its source link for good: `lead_id` is unique, so a
 * lead converts once. It belongs to the lead's branch and its owner, moves through stages (DealStage) with a history, and
 * is an approval document: the customer and the lines are what the approver signs off on — a quotation or an order is made
 * only from an approved deal. The stage, probability, close date and remarks move on without a new approval. The value is
 * always the sum of the lines, worked out on the server.
 *
 * @property string $deal_number
 * @property Carbon $deal_date
 * @property int $location_id
 * @property int $lead_id
 * @property int|null $customer_ledger_id
 * @property string $party_name
 * @property string|null $contact_person
 * @property string|null $mobile
 * @property string|null $email
 * @property Carbon $expected_close_date
 * @property int $probability
 * @property string $expected_value
 * @property DealStage $stage
 * @property int|null $lost_reason_id
 * @property string|null $lost_remarks
 * @property Carbon|null $lost_at
 * @property int $assigned_to
 * @property int|null $sales_team_id
 */
#[Fillable(['expected_close_date', 'probability', 'remarks'])]
#[UsePolicy(DealPolicy::class)]
class Deal extends TenantModel implements ApprovableDocument
{
    use Approvable, BelongsToLocation, ValidatesParentCompany;

    /** A deal has no active/inactive `status`: its stage says where it stands. */
    protected $attributes = [
        'stage' => 'discovery',
        'probability' => 10,
        'expected_value' => '0.00',
    ];

    protected function casts(): array
    {
        return [
            'deal_date' => 'date:Y-m-d',
            'expected_close_date' => 'date:Y-m-d',
            'expected_value' => 'decimal:2',
            'probability' => 'integer',
            'stage' => DealStage::class,
            'lost_at' => 'datetime',
        ];
    }

    public function approvalDocumentType(): DocumentType
    {
        return DocumentType::Deal;
    }

    public function approvalUrl(): ?string
    {
        return route('crm.deals.show', $this);
    }

    public function approvalTitle(): string
    {
        return "Deal {$this->deal_number} — {$this->party_name}";
    }

    /**
     * Only a deal that is still being worked, and whose lines are all complete, can be sent for approval.
     *
     * @throws ApprovalStateException
     */
    public function beforeSubmitForApproval(): void
    {
        if (! $this->stage->isOpen()) {
            throw ApprovalStateException::notReady('A deal that is '.strtolower($this->stage->label()).' cannot be sent for approval.');
        }

        $lines = $this->items()->get();

        if ($lines->isEmpty()) {
            throw ApprovalStateException::notReady('Add at least one item line before sending the deal for approval.');
        }
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<Ledger, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'customer_ledger_id');
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

    /** @return BelongsTo<LostReason, $this> */
    public function lostReason(): BelongsTo
    {
        return $this->belongsTo(LostReason::class);
    }

    /** @return HasMany<DealItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DealItem::class)->orderBy('line_no');
    }

    /** @return HasMany<DealStageEntry, $this> */
    public function stageHistory(): HasMany
    {
        return $this->hasMany(DealStageEntry::class)->orderByDesc('id');
    }

    /**
     * Deals still being worked (not Won, not Lost).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn($this->qualifyColumn('stage'), [DealStage::Won->value, DealStage::Lost->value]);
    }

    public function isOpen(): bool
    {
        return $this->stage->isOpen();
    }

    /**
     * May an order be taken from this deal? It has to be approved and still open. A quotation is *not* required: a deal can be ordered
     * directly, and a quotation is only one way of getting there.
     */
    public function isReadyForOrder(): bool
    {
        return $this->isApproved() && $this->isOpen();
    }

    /** May the customer and the lines still be changed: not in approval or approved, and no quotation or order made from it. */
    public function isContentEditable(): bool
    {
        return $this->isEditableByWorkflow() && in_array($this->stage, [DealStage::Discovery, DealStage::Negotiation], true);
    }

    /** "DL/AHD/26-27/00003 — Shree Ganesh Traders", for notifications and pickers. */
    public function title(): string
    {
        return "{$this->deal_number} — {$this->party_name}";
    }

    /** Is the expected close date already behind us while the deal is still open? */
    public function isLate(): bool
    {
        return $this->isOpen() && $this->expected_close_date->lt(now()->startOfDay());
    }

    /** What the deal is worth weighted by its probability, rounded half up to paise (forecasting, Phase 13). */
    public function weightedValue(): string
    {
        return bcadd(bcdiv(bcmul((string) $this->expected_value, (string) $this->probability, 4), '100', 6), '0.005', 2);
    }

    protected function companyScopedParents(): array
    {
        return [
            'lead_id' => Lead::class, 'customer_ledger_id' => Ledger::class, 'lost_reason_id' => LostReason::class, 'sales_team_id' => SalesTeam::class,
        ];
    }

    /**
     * What an approver signs off on: who the deal is for and every line of it. Where it stands (stage, probability, close
     * date, owner, remarks, why it was lost) moves on without a new approval, so it is not part of the fingerprint.
     *
     * @return array<string, mixed>
     */
    protected function approvalFingerprintParts(): array
    {
        return [
            'deal' => Arr::only($this->getAttributes(), ['company_id', 'location_id', 'lead_id', 'customer_ledger_id', 'party_name', 'contact_person', 'mobile', 'email', 'expected_value']),
            'items' => $this->items()->get()->map(fn (DealItem $line) => Arr::only($line->getAttributes(), ['line_no', 'item_id', 'unit_id', 'quantity', 'expected_rate', 'amount']))->all(),
        ];
    }

    protected function auditLogName(): string
    {
        return 'deals';
    }
}

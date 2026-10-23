<?php

namespace App\Modules\Sales\Models;

use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\PriceList;
use App\Modules\Masters\Models\State;
use App\Modules\Sales\Enums\QuotationState;
use App\Modules\Sales\Policies\QuotationPolicy;
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
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One revision of a quotation: the offer to a customer for the items of a deal (or of an approved inquiry), priced from the rate
 * master, with GST worked out by place of supply, valid until a date, approved inside the company before it is sent.
 *
 * A quotation is a number (QT/AHD/26-27/00001) and a run of revisions, Rev 0, Rev 1…; each revision is a row of its own. Only a
 * **draft** can change — what has been sent is exactly what the customer was shown, so the model refuses any change to the
 * content of a revision that has left draft (only its state, and the dates and notes that go with the state, move on). A change
 * of mind is a new revision. The customer, address, place of supply, terms and every figure are copies taken when the draft was
 * saved; the masters can change afterwards and the document does not.
 *
 * @property string $quotation_number
 * @property int $revision_no
 * @property int|null $previous_id
 * @property int $location_id
 * @property int $lead_id
 * @property int|null $deal_id
 * @property int|null $customer_ledger_id
 * @property QuotationState $state
 * @property Carbon $quotation_date
 * @property Carbon $valid_until
 * @property Carbon $price_date
 * @property int|null $price_list_id
 * @property string $party_name
 * @property string|null $contact_person
 * @property string|null $mobile
 * @property string|null $email
 * @property string|null $bill_line1
 * @property string|null $bill_line2
 * @property string|null $bill_city
 * @property string|null $bill_pin_code
 * @property string|null $customer_gstin
 * @property int $place_of_supply_state_id
 * @property string $supply_type
 * @property string|null $supplier_gstin
 * @property string|null $supplier_state_code
 * @property string|null $terms
 * @property string $gross_total
 * @property string $discount_total
 * @property string $taxable_total
 * @property string $cgst_total
 * @property string $sgst_total
 * @property string $igst_total
 * @property string $round_off
 * @property string $grand_total
 * @property Carbon|null $sent_at
 * @property Carbon|null $accepted_at
 * @property string|null $accepted_note
 * @property Carbon|null $declined_at
 * @property string|null $declined_reason
 * @property Carbon|null $expired_at
 * @property Carbon|null $expiry_warned_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property Carbon|null $superseded_at
 */
#[Fillable(['valid_until', 'terms', 'remarks'])]
#[UsePolicy(QuotationPolicy::class)]
class Quotation extends TenantModel implements ApprovableDocument
{
    use Approvable, BelongsToLocation, ValidatesParentCompany;

    /** What may still be written to a revision that has left draft: where it stands with the customer, never what it says. */
    private const STATE_COLUMNS = [
        'state', 'sent_at', 'accepted_at', 'accepted_note', 'declined_at', 'declined_reason', 'expired_at', 'expiry_warned_at',
        'cancelled_at', 'cancel_reason', 'superseded_at', 'updated_by', 'updated_at',
    ];

    /** A quotation has no active/inactive `status`: its state says where it stands. */
    protected $attributes = [
        'state' => 'draft',
        'revision_no' => 0,
        'gross_total' => '0.00',
        'discount_total' => '0.00',
        'taxable_total' => '0.00',
        'cgst_total' => '0.00',
        'sgst_total' => '0.00',
        'igst_total' => '0.00',
        'round_off' => '0.00',
        'grand_total' => '0.00',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $quotation) {
            $was = (string) $quotation->getRawOriginal('state');

            if ($was !== QuotationState::Draft->value && array_diff(array_keys($quotation->getDirty()), self::STATE_COLUMNS) !== []) {
                throw new LogicException('A quotation that has left draft cannot be changed: make a new revision instead.');
            }
        });

        static::deleting(fn () => throw new LogicException('A quotation is never deleted: cancel it instead.'));
    }

    protected function casts(): array
    {
        return [
            'revision_no' => 'integer',
            'state' => QuotationState::class,
            'quotation_date' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'price_date' => 'date:Y-m-d',
            'gross_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'taxable_total' => 'decimal:2',
            'cgst_total' => 'decimal:2',
            'sgst_total' => 'decimal:2',
            'igst_total' => 'decimal:2',
            'round_off' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'expired_at' => 'datetime',
            'expiry_warned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function approvalDocumentType(): DocumentType
    {
        return DocumentType::Quotation;
    }

    public function approvalUrl(): ?string
    {
        return route('sales.quotations.show', $this);
    }

    public function approvalTitle(): string
    {
        return "Quotation {$this->displayNumber()} — {$this->party_name}";
    }

    /**
     * Only the latest revision, still a draft, with lines and a validity that has not already run out, can be sent for approval.
     *
     * @throws ApprovalStateException
     */
    public function beforeSubmitForApproval(): void
    {
        if ($this->state !== QuotationState::Draft) {
            throw ApprovalStateException::notReady('Only a draft quotation can be sent for approval.');
        }

        if ($this->items()->doesntExist()) {
            throw ApprovalStateException::notReady('Add at least one item line before sending the quotation for approval.');
        }

        if ($this->valid_until->lt(now()->startOfDay())) {
            throw ApprovalStateException::notReady('The valid-until date has already passed: change it before sending the quotation for approval.');
        }
    }

    // ---- relations ----------------------------------------------------------------------------------------------

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<Ledger, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'customer_ledger_id');
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<State, $this> */
    public function placeOfSupply(): BelongsTo
    {
        return $this->belongsTo(State::class, 'place_of_supply_state_id');
    }

    /** @return BelongsTo<self, $this> */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_id');
    }

    /** @return HasMany<QuotationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('line_no');
    }

    /** @return HasMany<QuotationStateEntry, $this> */
    public function stateHistory(): HasMany
    {
        return $this->hasMany(QuotationStateEntry::class)->orderByDesc('id');
    }

    /** @return HasMany<QuotationDispatch, $this> */
    public function dispatches(): HasMany
    {
        return $this->hasMany(QuotationDispatch::class)->orderByDesc('id');
    }

    /**
     * Every revision of this quotation, oldest first.
     *
     * @return Builder<self>
     */
    public function revisions(): Builder
    {
        return self::query()->where('quotation_number', $this->quotation_number)->orderBy('revision_no');
    }

    // ---- scopes -------------------------------------------------------------------------------------------------

    /**
     * Revisions that are the current word on their quotation (not replaced by a newer one).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCurrentRevisions(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('superseded_at'));
    }

    // ---- what it is ---------------------------------------------------------------------------------------------

    /** "QT/AHD/26-27/00001 · Rev 1". */
    public function displayNumber(): string
    {
        return "{$this->quotation_number} · Rev {$this->revision_no}";
    }

    public function isDraft(): bool
    {
        return $this->state === QuotationState::Draft;
    }

    /** The current word on its quotation: no newer revision has replaced it. */
    public function isLatestRevision(): bool
    {
        return $this->superseded_at === null;
    }

    /** May the content be changed: a draft that is not waiting for, or past, approval. */
    public function isContentEditable(): bool
    {
        return $this->isDraft() && $this->isEditableByWorkflow();
    }

    /** Has the validity run out? (A sent quotation is expired by the scheduler once it has; this answers for any day.) */
    public function isPastValidity(?Carbon $on = null): bool
    {
        return $this->valid_until->lt(($on ?? now())->startOfDay());
    }

    public function isInterState(): bool
    {
        return $this->supply_type === 'inter';
    }

    /**
     * The billing address as lines, for the document and the screen.
     *
     * @return list<string>
     */
    public function addressLines(): array
    {
        $place = trim(implode(' ', array_filter([$this->bill_city, $this->bill_pin_code])));

        return array_values(array_filter([$this->bill_line1, $this->bill_line2, $place], fn (?string $line) => $line !== null && trim($line) !== ''));
    }

    protected function companyScopedParents(): array
    {
        return [
            'lead_id' => Lead::class, 'deal_id' => Deal::class, 'customer_ledger_id' => Ledger::class, 'price_list_id' => PriceList::class,
            'previous_id' => self::class,
        ];
    }

    /**
     * What an approver signs off on: who the quotation is to, where the supply goes, the terms, the validity and every line with
     * its price, discount and tax. Where it stands with the customer is not part of it.
     *
     * @return array<string, mixed>
     */
    protected function approvalFingerprintParts(): array
    {
        return [
            'quotation' => Arr::only($this->getAttributes(), [
                'company_id', 'location_id', 'quotation_number', 'revision_no', 'lead_id', 'deal_id', 'customer_ledger_id', 'price_list_id', 'valid_until', 'price_date',
                'party_name', 'contact_person', 'mobile', 'email', 'bill_line1', 'bill_line2', 'bill_city', 'bill_pin_code', 'customer_gstin',
                'place_of_supply_state_id', 'supply_type', 'terms', 'grand_total',
            ]),
            'items' => $this->items()->get()->map(fn (QuotationItem $line) => Arr::only($line->getAttributes(), [
                'line_no', 'item_id', 'unit_id', 'quantity', 'list_rate', 'rate', 'rate_overridden', 'discount_percent', 'gst_rate', 'line_total',
            ]))->all(),
        ];
    }

    protected function auditLogName(): string
    {
        return 'quotations';
    }

    /** The state of a quotation row as the database holds it right now, bypassing every cache (used by the line guards). */
    public static function storedState(int $id): ?string
    {
        $state = DB::table('quotations')->where('id', $id)->value('state');

        return $state === null ? null : (string) $state;
    }
}

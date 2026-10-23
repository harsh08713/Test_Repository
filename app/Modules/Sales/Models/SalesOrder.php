<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\PriceList;
use App\Modules\Masters\Models\State;
use App\Modules\Sales\Enums\CreditResult;
use App\Modules\Sales\Enums\SalesOrderState;
use App\Modules\Sales\Policies\SalesOrderPolicy;
use App\Modules\Sales\Services\CreditCheck;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A sales order: what a customer has ordered, at which prices and with which tax, for delivery by a date — made from an approved
 * quotation, an approved deal, or directly for a regular customer. It is an approval document, and **approving it is what makes the
 * invoice** (InvoiceGenerator, on DocumentApproved).
 *
 * The customer, the addresses, the place of supply, the prices and the tax are copies taken when the order is saved; the masters can change
 * afterwards and the order does not. Once it is waiting for approval, approved, invoiced or cancelled its content cannot change — the model
 * refuses it, not only the service; what may still move is the approval, its state and the dates and notes that go with the state.
 *
 * @property int|null $approved_by who approved it (set by the approval workflow)
 * @property string $so_number
 * @property Carbon $order_date
 * @property Carbon $delivery_date
 * @property string $source_type quotation|deal|direct
 * @property int|null $quotation_id
 * @property int|null $deal_id
 * @property int|null $lead_id
 * @property int $location_id
 * @property SalesOrderState $state
 * @property string $pricing_policy copy|rates
 * @property Carbon $price_date
 * @property int $customer_ledger_id
 * @property int $assigned_to the salesperson credited with the order (targets count it for them)
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
 * @property string|null $ship_line1
 * @property string|null $ship_line2
 * @property string|null $ship_city
 * @property string|null $ship_pin_code
 * @property int $place_of_supply_state_id
 * @property string $supply_type
 * @property string|null $supplier_gstin
 * @property string|null $supplier_state_code
 * @property string|null $customer_po_number
 * @property Carbon|null $customer_po_date
 * @property string|null $terms
 * @property string $gross_total
 * @property string $discount_total
 * @property string $taxable_total
 * @property string $cgst_total
 * @property string $sgst_total
 * @property string $igst_total
 * @property string $round_off
 * @property string $grand_total
 * @property string|null $credit_limit
 * @property string $credit_exposure
 * @property CreditResult $credit_result
 * @property Carbon|null $credit_checked_at
 * @property string|null $credit_override_reason
 * @property int|null $credit_override_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int|null $cancelled_by
 * @property Carbon|null $invoiced_at
 * @property Carbon|null $invoice_attempted_at
 * @property string|null $invoice_error
 */
#[Fillable(['delivery_date', 'customer_po_number', 'customer_po_date', 'terms', 'remarks'])]
#[UsePolicy(SalesOrderPolicy::class)]
class SalesOrder extends TenantModel implements ApprovableDocument
{
    use Approvable, BelongsToLocation, ValidatesParentCompany;

    /** What may still be written to an order that is waiting for approval, approved, invoiced or cancelled. */
    private const MOVING_COLUMNS = [
        'approval_status', 'approved_by', 'approved_at', 'state', 'cancelled_at', 'cancel_reason', 'cancelled_by', 'invoiced_at', 'invoice_attempted_at',
        'invoice_error', 'credit_override_reason', 'credit_override_by', 'credit_result', 'credit_checked_at', 'credit_limit', 'credit_exposure',
        'updated_by', 'updated_at', 'deleted_at',
    ];

    /** An order has no active/inactive `status`: its state says where it stands. */
    protected $attributes = [
        'state' => 'open',
        'pricing_policy' => 'rates',
        'credit_result' => 'not_checked',
        'credit_exposure' => '0.00',
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
        static::updating(function (self $order) {
            $approval = (string) $order->getRawOriginal('approval_status');
            $frozen = in_array($approval, ['pending', 'approved'], true) || (string) $order->getRawOriginal('state') !== SalesOrderState::Open->value;

            if ($frozen && array_diff(array_keys($order->getDirty()), self::MOVING_COLUMNS) !== []) {
                throw new LogicException('A sales order that is waiting for approval, approved, invoiced or cancelled cannot be changed.');
            }
        });

        static::deleting(fn () => throw new LogicException('A sales order is never deleted: cancel it instead.'));
    }

    protected function casts(): array
    {
        return [
            'order_date' => 'date:Y-m-d',
            'delivery_date' => 'date:Y-m-d',
            'price_date' => 'date:Y-m-d',
            'customer_po_date' => 'date:Y-m-d',
            'state' => SalesOrderState::class,
            'credit_result' => CreditResult::class,
            'gross_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'taxable_total' => 'decimal:2',
            'cgst_total' => 'decimal:2',
            'sgst_total' => 'decimal:2',
            'igst_total' => 'decimal:2',
            'round_off' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'credit_exposure' => 'decimal:2',
            'credit_checked_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'invoiced_at' => 'datetime',
            'invoice_attempted_at' => 'datetime',
        ];
    }

    public function approvalDocumentType(): DocumentType
    {
        return DocumentType::SalesOrder;
    }

    public function approvalUrl(): ?string
    {
        return route('sales.orders.show', $this);
    }

    public function approvalTitle(): string
    {
        return "Sales order {$this->so_number} — {$this->party_name}";
    }

    /**
     * Only an open order with lines can be sent for approval, and the credit check has to allow it: it is run again now, so the answer an
     * approver sees is today's.
     *
     * @throws ApprovalStateException
     */
    public function beforeSubmitForApproval(): void
    {
        if ($this->state !== SalesOrderState::Open) {
            throw ApprovalStateException::notReady('A sales order that is '.strtolower($this->state->label()).' cannot be sent for approval.');
        }

        if ($this->items()->doesntExist()) {
            throw ApprovalStateException::notReady('Add at least one item line before sending the order for approval.');
        }

        $result = app(CreditCheck::class)->refresh($this);

        if (! $result->allowsSubmission()) {
            throw ApprovalStateException::notReady('This order takes the customer over their credit limit ('.app(CreditCheck::class)->describe($this).'). Someone allowed to override the limit has to do so, with a reason, first.');
        }
    }

    // ---- relations ----------------------------------------------------------------------------------------------

    /** @return BelongsTo<Quotation, $this> */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
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
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
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

    /** @return HasMany<SalesOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class)->orderBy('line_no');
    }

    /** @return HasMany<SalesOrderStateEntry, $this> */
    public function stateHistory(): HasMany
    {
        return $this->hasMany(SalesOrderStateEntry::class)->orderByDesc('id');
    }

    /** @return HasMany<SalesInvoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class);
    }

    /** @return HasOne<SalesInvoice, $this> */
    public function invoice(): HasOne
    {
        return $this->hasOne(SalesInvoice::class)->where('source_key', SalesInvoice::FULL);
    }

    // ---- scopes -------------------------------------------------------------------------------------------------

    /**
     * Orders that count (approved and not cancelled).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStanding(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('approval_status'), 'approved')->where($this->qualifyColumn('state'), '<>', SalesOrderState::Cancelled->value);
    }

    // ---- what it is ---------------------------------------------------------------------------------------------

    /** May the content still be changed: open, and a draft or a returned one — not waiting, not approved? */
    public function isContentEditable(): bool
    {
        return $this->state === SalesOrderState::Open && $this->isEditableByWorkflow();
    }

    public function isCancelled(): bool
    {
        return $this->state === SalesOrderState::Cancelled;
    }

    public function isInterState(): bool
    {
        return $this->supply_type === 'inter';
    }

    /** @return list<string> */
    public function billingLines(): array
    {
        return $this->addressLines($this->bill_line1, $this->bill_line2, $this->bill_city, $this->bill_pin_code);
    }

    /** @return list<string> */
    public function shippingLines(): array
    {
        return $this->addressLines($this->ship_line1, $this->ship_line2, $this->ship_city, $this->ship_pin_code);
    }

    /** @return list<string> */
    private function addressLines(?string $line1, ?string $line2, ?string $city, ?string $pin): array
    {
        $place = trim(implode(' ', array_filter([$city, $pin])));

        return array_values(array_filter([$line1, $line2, $place], fn (?string $line) => $line !== null && trim($line) !== ''));
    }

    protected function companyScopedParents(): array
    {
        return [
            'quotation_id' => Quotation::class, 'deal_id' => Deal::class, 'lead_id' => Lead::class, 'customer_ledger_id' => Ledger::class, 'price_list_id' => PriceList::class,
        ];
    }

    /**
     * What an approver signs off on: who the order is for and where it goes, the place of supply, the customer's PO, the delivery date,
     * the terms, and every line with its price, discount and tax. The credit check is shown to the approver, not signed.
     *
     * @return array<string, mixed>
     */
    protected function approvalFingerprintParts(): array
    {
        return [
            'order' => Arr::only($this->getAttributes(), [
                'company_id', 'location_id', 'so_number', 'order_date', 'delivery_date', 'source_type', 'quotation_id', 'deal_id', 'customer_ledger_id', 'assigned_to',
                'price_list_id', 'party_name', 'contact_person', 'mobile', 'email', 'bill_line1', 'bill_line2', 'bill_city', 'bill_pin_code', 'customer_gstin',
                'ship_line1', 'ship_line2', 'ship_city', 'ship_pin_code', 'place_of_supply_state_id', 'supply_type', 'customer_po_number', 'customer_po_date',
                'terms', 'grand_total',
            ]),
            'items' => $this->items()->get()->map(fn (SalesOrderItem $line) => Arr::only($line->getAttributes(), [
                'line_no', 'item_id', 'unit_id', 'quantity', 'list_rate', 'rate', 'rate_overridden', 'discount_percent', 'gst_rate', 'line_total',
            ]))->all(),
        ];
    }

    protected function auditLogName(): string
    {
        return 'sales_orders';
    }

    /** The state of an order row as the database holds it right now (used by the line guards). */
    public static function storedGuard(int $id): ?object
    {
        $row = DB::table('sales_orders')->where('id', $id)->first(['state', 'approval_status']);

        return $row === null ? null : (object) (array) $row;
    }
}

<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\PriceList;
use App\Modules\Masters\Models\State;
use App\Modules\Sales\Policies\SalesInvoicePolicy;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A sales invoice: the document made from an approved sales order, linked to it and to its lines, with its own number series and copies of
 * every figure — so it says what the order said when it was approved, whatever happens to the masters. It posts nothing to stock or to
 * accounts (that is not in scope); it is the invoice document and its linkage.
 *
 * `source_key` says which part of the order the invoice covers — `full` in this release; invoicing in parts later gives each part its own
 * key — and `unique(sales_order_id, source_key)` is what makes "one invoice for a fully invoiced order" true however many times, and from
 * however many places, it is asked for. An issued invoice never changes: only its status moves (to cancelled, with the order).
 *
 * @property string $invoice_number
 * @property Carbon $invoice_date
 * @property Carbon $due_date
 * @property int $location_id
 * @property int $sales_order_id
 * @property string $source_key
 * @property string $status issued|cancelled
 * @property Carbon $price_date
 * @property int $customer_ledger_id
 * @property int $assigned_to
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
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 */
#[UsePolicy(SalesInvoicePolicy::class)]
class SalesInvoice extends TenantModel
{
    use BelongsToLocation, ValidatesParentCompany;

    /** The key of the invoice that covers the whole order (the only kind this release makes). */
    public const FULL = 'full';

    public const ISSUED = 'issued';

    public const CANCELLED = 'cancelled';

    /** What may still move on an invoice that has been issued. */
    private const MOVING_COLUMNS = ['status', 'cancelled_at', 'cancel_reason', 'updated_by', 'updated_at', 'deleted_at'];

    /** An invoice has its own `status`; the base model's active/inactive is not used. */
    protected $attributes = [
        'status' => 'issued',
        'source_key' => 'full',
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
        static::updating(function (self $invoice) {
            if (array_diff(array_keys($invoice->getDirty()), self::MOVING_COLUMNS) !== []) {
                throw new LogicException('An issued sales invoice cannot be changed.');
            }
        });

        static::deleting(fn () => throw new LogicException('A sales invoice is never deleted: it is cancelled with its order.'));
    }

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'price_date' => 'date:Y-m-d',
            'customer_po_date' => 'date:Y-m-d',
            'gross_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'taxable_total' => 'decimal:2',
            'cgst_total' => 'decimal:2',
            'sgst_total' => 'decimal:2',
            'igst_total' => 'decimal:2',
            'round_off' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
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

    /** @return HasMany<SalesInvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class)->orderBy('line_no');
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function isInterState(): bool
    {
        return $this->supply_type === 'inter';
    }

    /** @return list<string> */
    public function billingLines(): array
    {
        return $this->lines($this->bill_line1, $this->bill_line2, $this->bill_city, $this->bill_pin_code);
    }

    /** @return list<string> */
    public function shippingLines(): array
    {
        return $this->lines($this->ship_line1, $this->ship_line2, $this->ship_city, $this->ship_pin_code);
    }

    /** @return list<string> */
    private function lines(?string $line1, ?string $line2, ?string $city, ?string $pin): array
    {
        $place = trim(implode(' ', array_filter([$city, $pin])));

        return array_values(array_filter([$line1, $line2, $place], fn (?string $line) => $line !== null && trim($line) !== ''));
    }

    protected function companyScopedParents(): array
    {
        return ['sales_order_id' => SalesOrder::class, 'customer_ledger_id' => Ledger::class, 'price_list_id' => PriceList::class];
    }

    protected function auditLogName(): string
    {
        return 'sales_invoices';
    }
}

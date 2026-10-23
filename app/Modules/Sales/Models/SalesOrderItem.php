<?php

namespace App\Modules\Sales\Models;

use App\Modules\CRM\Models\DealItem;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Rate;
use App\Modules\Masters\Models\Unit;
use App\Modules\Sales\Policies\SalesOrderChildPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of a sales order, with the price and tax it was ordered at. `invoiced_quantity` is how much of it has been invoiced: the first
 * release invoices every line in full, but the column is what invoicing in parts will build on. A line can be added, changed or removed
 * only while its order is an open draft (or returned); `invoiced_quantity` is the one thing the invoice generator moves afterwards.
 *
 * @property int $sales_order_id
 * @property int|null $quotation_item_id
 * @property int|null $deal_item_id
 * @property int $line_no
 * @property int $item_id
 * @property int $unit_id
 * @property string $item_name
 * @property string|null $hsn_code
 * @property string $quantity
 * @property string $invoiced_quantity
 * @property int|null $rate_id
 * @property string $rate_source
 * @property string $list_rate
 * @property string $rate
 * @property bool $rate_overridden
 * @property string|null $override_reason
 * @property string $discount_percent
 * @property string $gross_amount
 * @property string $discount_amount
 * @property string $taxable_amount
 * @property string $gst_rate
 * @property string $cgst_amount
 * @property string $sgst_amount
 * @property string $igst_amount
 * @property string $line_total
 */
#[UsePolicy(SalesOrderChildPolicy::class)]
class SalesOrderItem extends TenantModel
{
    use ValidatesParentCompany;

    protected $attributes = [];

    protected static function booted(): void
    {
        $guard = function (self $line) {
            $order = SalesOrder::storedGuard((int) $line->sales_order_id);

            if ($order === null || $order->state !== 'open' || ! in_array($order->approval_status, ['draft', 'rejected'], true)) {
                throw new LogicException('The lines of a sales order that is waiting for approval, approved, invoiced or cancelled cannot be changed.');
            }
        };

        static::creating($guard);
        static::deleting($guard);
        static::updating(function (self $line) use ($guard) {
            // the invoice generator records how much has been invoiced; nothing else about an approved order's line moves
            if (array_keys($line->getDirty()) === ['invoiced_quantity'] || array_diff(array_keys($line->getDirty()), ['invoiced_quantity', 'updated_by', 'updated_at']) === []) {
                return;
            }

            $guard($line);
        });
    }

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'quantity' => 'decimal:3',
            'invoiced_quantity' => 'decimal:3',
            'list_rate' => 'decimal:4',
            'rate' => 'decimal:4',
            'rate_overridden' => 'boolean',
            'discount_percent' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Rate, $this> */
    public function rateRow(): BelongsTo
    {
        return $this->belongsTo(Rate::class, 'rate_id');
    }

    /** @return BelongsTo<QuotationItem, $this> */
    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }

    /** @return BelongsTo<DealItem, $this> */
    public function dealItem(): BelongsTo
    {
        return $this->belongsTo(DealItem::class);
    }

    protected function companyScopedParents(): array
    {
        return ['sales_order_id' => SalesOrder::class, 'item_id' => Item::class, 'unit_id' => Unit::class];
    }

    protected function auditLogName(): string
    {
        return 'sales_order_items';
    }
}

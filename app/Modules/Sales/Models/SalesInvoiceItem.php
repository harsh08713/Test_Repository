<?php

namespace App\Modules\Sales\Models;

use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Unit;
use App\Modules\Sales\Policies\SalesInvoiceChildPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of a sales invoice: a copy of the order line it was made from (`sales_order_item_id`), with the price and tax it was ordered at.
 * Written once, by the invoice generator; never changed or removed.
 *
 * @property int $sales_invoice_id
 * @property int $sales_order_item_id
 * @property int $line_no
 * @property int $item_id
 * @property int $unit_id
 * @property string $item_name
 * @property string|null $hsn_code
 * @property string $quantity
 * @property string $list_rate
 * @property string $rate
 * @property bool $rate_overridden
 * @property string $discount_percent
 * @property string $gross_amount
 * @property string $discount_amount
 * @property string $taxable_amount
 * @property string $gst_rate
 * @property string $cgst_amount
 * @property string $sgst_amount
 * @property string $igst_amount
 * @property string $line_total
 * @property string|null $unit_cost cost of one unit of the line, copied when the invoice was made; null = the item had no cost then
 * @property string|null $cost_amount quantity × unit_cost
 * @property string|null $cost_source what answered (standard)
 */
#[UsePolicy(SalesInvoiceChildPolicy::class)]
class SalesInvoiceItem extends TenantModel
{
    use ValidatesParentCompany;

    protected $attributes = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The lines of an issued sales invoice cannot be changed.'));
        static::deleting(fn () => throw new LogicException('The lines of an issued sales invoice cannot be removed.'));
    }

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'quantity' => 'decimal:3',
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
            'unit_cost' => 'decimal:4',
            'cost_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<SalesInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    /** @return BelongsTo<SalesOrderItem, $this> */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id');
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

    protected function companyScopedParents(): array
    {
        return ['sales_invoice_id' => SalesInvoice::class, 'sales_order_item_id' => SalesOrderItem::class, 'item_id' => Item::class, 'unit_id' => Unit::class];
    }

    protected function auditLogName(): string
    {
        return 'sales_invoice_items';
    }
}

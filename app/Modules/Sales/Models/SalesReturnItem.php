<?php

namespace App\Modules\Sales\Models;

use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Unit;
use App\Modules\Sales\Policies\SalesReturnChildPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One line of a sales return: part (or all) of an invoice line, at the price and tax that line carried, with its cost copied from the invoice
 * line. Written only through SalesReturnService, and only while the return is a draft or was sent back.
 *
 * @property int $sales_return_id
 * @property int $sales_invoice_item_id
 * @property int $line_no
 * @property int $item_id
 * @property int $unit_id
 * @property string $item_name
 * @property string|null $hsn_code
 * @property string $quantity
 * @property string $rate
 * @property string $discount_percent
 * @property string $gross_amount
 * @property string $discount_amount
 * @property string $taxable_amount
 * @property string $gst_rate
 * @property string $cgst_amount
 * @property string $sgst_amount
 * @property string $igst_amount
 * @property string $line_total
 * @property string|null $unit_cost
 * @property string|null $cost_amount
 */
#[UsePolicy(SalesReturnChildPolicy::class)]
class SalesReturnItem extends TenantModel
{
    use ValidatesParentCompany;

    protected $attributes = [];

    protected static function booted(): void
    {
        $guard = function (self $line) {
            $status = DB::table('sales_returns')->where('id', $line->sales_return_id)->value('approval_status');

            if (! in_array($status, ['draft', 'rejected'], true)) {
                throw new LogicException('The lines of a sales return that is waiting for approval or approved cannot be changed.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'quantity' => 'decimal:3',
            'rate' => 'decimal:4',
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

    /** @return BelongsTo<SalesReturn, $this> */
    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    /** @return BelongsTo<SalesInvoiceItem, $this> */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceItem::class, 'sales_invoice_item_id');
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
        return ['sales_return_id' => SalesReturn::class, 'sales_invoice_item_id' => SalesInvoiceItem::class, 'item_id' => Item::class, 'unit_id' => Unit::class];
    }

    protected function auditLogName(): string
    {
        return 'sales_return_items';
    }
}

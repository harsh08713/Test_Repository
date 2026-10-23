<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Unit;
use App\Modules\Procurement\Policies\PurchaseOrderChildPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One line of a purchase order. Written only through PurchaseOrderService, and only while the order is a draft or was sent back.
 *
 * @property int $purchase_order_id
 * @property int $line_no
 * @property int $item_id
 * @property int $unit_id
 * @property string $item_name
 * @property string $quantity in the line's unit
 * @property string $base_quantity in the item's base unit
 * @property string $rate per line unit
 * @property string $amount
 * @property string $tolerance_percent the item's PO tolerance when the order was made
 */
#[UsePolicy(PurchaseOrderChildPolicy::class)]
class PurchaseOrderItem extends TenantModel
{
    use ValidatesParentCompany;

    protected $attributes = [];

    protected static function booted(): void
    {
        $guard = function (self $line) {
            $status = DB::table('purchase_orders')->where('id', $line->purchase_order_id)->value('approval_status');

            if (! in_array($status, ['draft', 'rejected'], true)) {
                throw new LogicException('The lines of a purchase order that is waiting for approval or approved cannot be changed.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'quantity' => 'decimal:3', 'base_quantity' => 'decimal:3', 'rate' => 'decimal:4', 'amount' => 'decimal:2', 'tolerance_percent' => 'decimal:2'];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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
        return ['purchase_order_id' => PurchaseOrder::class, 'item_id' => Item::class, 'unit_id' => Unit::class];
    }

    protected function auditLogName(): string
    {
        return 'purchase_order_items';
    }
}

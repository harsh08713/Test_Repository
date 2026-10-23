<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Unit;
use App\Modules\Procurement\Policies\GrnChildPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One line of a goods receipt: how much of a purchase-order line arrived, accepted and rejected, and for a tracked item the batch it belongs to.
 * Written only through GrnService, and only while the receipt is a draft or was sent back.
 *
 * @property int $grn_id
 * @property int $purchase_order_item_id
 * @property int $line_no
 * @property int $item_id
 * @property int $unit_id
 * @property string $item_name
 * @property string $accepted_quantity
 * @property string $rejected_quantity
 * @property string $base_accepted
 * @property string $base_received
 * @property string $unit_cost per base unit
 * @property string|null $batch_no
 * @property string|null $lot_no
 * @property Carbon|null $mfg_date
 * @property Carbon|null $expiry_date
 * @property string|null $reject_reason
 */
#[UsePolicy(GrnChildPolicy::class)]
class GrnItem extends TenantModel
{
    use ValidatesParentCompany;

    protected $attributes = [];

    protected static function booted(): void
    {
        $guard = function (self $line) {
            $status = DB::table('grns')->where('id', $line->grn_id)->value('approval_status');

            if (! in_array($status, ['draft', 'rejected'], true)) {
                throw new LogicException('The lines of a goods receipt that is waiting for approval or approved cannot be changed.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'line_no' => 'integer', 'accepted_quantity' => 'decimal:3', 'rejected_quantity' => 'decimal:3', 'base_accepted' => 'decimal:3',
            'base_received' => 'decimal:3', 'unit_cost' => 'decimal:4', 'mfg_date' => 'date:Y-m-d', 'expiry_date' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<Grn, $this> */
    public function grn(): BelongsTo
    {
        return $this->belongsTo(Grn::class);
    }

    /** @return BelongsTo<PurchaseOrderItem, $this> */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
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
        return ['grn_id' => Grn::class, 'purchase_order_item_id' => PurchaseOrderItem::class, 'item_id' => Item::class, 'unit_id' => Unit::class];
    }

    protected function auditLogName(): string
    {
        return 'grn_items';
    }
}

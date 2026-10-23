<?php

namespace App\Modules\Production\Models;

use App\Modules\Masters\Models\Item;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One material an order requires, copied from the bill when the order was made. Replaced only while the order is a draft (the service does it).
 *
 * @property int $production_order_id
 * @property int $line_no
 * @property int $item_id
 * @property string $item_name
 * @property string $required_quantity
 */
class ProductionComponent extends TenantModel
{
    protected $attributes = [];

    protected function casts(): array
    {
        return ['required_quantity' => 'decimal:3'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }
}

<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\ItemChildPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock levels of an item at one branch, in the item's base unit. Invariant: minimum ≤ reorder level ≤ maximum
 * (checked by ItemService; one row per item and branch).
 *
 * @property int $item_id
 * @property int $location_id
 * @property string $minimum_stock
 * @property string $reorder_level
 * @property string $maximum_stock
 */
#[Fillable(['location_id', 'minimum_stock', 'reorder_level', 'maximum_stock'])]
#[UsePolicy(ItemChildPolicy::class)]
class ItemLocationParameter extends TenantModel
{
    use ValidatesParentCompany;

    protected function casts(): array
    {
        return [
            'minimum_stock' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'maximum_stock' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    protected function companyScopedParents(): array
    {
        return ['item_id' => Item::class, 'location_id' => Location::class];
    }

    protected function auditLogName(): string
    {
        return 'item_location_parameters';
    }
}

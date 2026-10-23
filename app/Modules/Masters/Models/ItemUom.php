<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\ItemChildPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "1 Box = 12 Nos": this row says one `unit` equals `factor` of the item's base unit.
 *
 * @property int $item_id
 * @property int $unit_id
 * @property string $factor base units in one of this unit (6 decimals)
 */
#[Fillable(['unit_id', 'factor'])]
#[UsePolicy(ItemChildPolicy::class)]
class ItemUom extends TenantModel
{
    use ValidatesParentCompany;

    protected function casts(): array
    {
        return ['factor' => 'decimal:6'];
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
        return ['item_id' => Item::class, 'unit_id' => Unit::class];
    }

    protected function auditLogName(): string
    {
        return 'item_uoms';
    }
}

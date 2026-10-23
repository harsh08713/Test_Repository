<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\ItemChildPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Another name an item is known by. Look-ups search it; it is unique within the company so a search is never ambiguous.
 *
 * @property int $item_id
 * @property string $alias
 */
#[Fillable(['alias'])]
#[UsePolicy(ItemChildPolicy::class)]
class ItemAlias extends TenantModel
{
    use ValidatesParentCompany;

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    protected function companyScopedParents(): array
    {
        return ['item_id' => Item::class];
    }

    protected function auditLogName(): string
    {
        return 'item_aliases';
    }
}

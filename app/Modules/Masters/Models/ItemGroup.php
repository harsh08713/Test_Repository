<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\ItemGroupPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $parent_id
 * @property string $name
 */
#[Fillable(['code', 'name', 'parent_id', 'status', 'remarks'])]
#[UsePolicy(ItemGroupPolicy::class)]
class ItemGroup extends TenantModel
{
    use ValidatesParentCompany;

    /** @return BelongsTo<ItemGroup, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<ItemGroup, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    protected function companyScopedParents(): array
    {
        return ['parent_id' => self::class];
    }
}

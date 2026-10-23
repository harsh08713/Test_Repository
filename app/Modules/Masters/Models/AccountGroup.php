<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Enums\AccountNature;
use App\Modules\Masters\Policies\AccountGroupPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node of the chart of accounts. Ledgers (Phase 3) hang off these groups; this master is deliberately
 * only the grouping structure — it is not an accounting ledger and posts nothing.
 *
 * @property int|null $parent_id
 * @property string $name
 * @property AccountNature $nature
 * @property bool $is_system standard group seeded for every company: cannot be deleted or moved
 */
#[Fillable(['code', 'name', 'parent_id', 'nature', 'status', 'remarks'])]
#[UsePolicy(AccountGroupPolicy::class)]
class AccountGroup extends TenantModel
{
    use ValidatesParentCompany;

    protected $attributes = [
        'status' => 'active',
        'is_system' => false,
    ];

    protected function casts(): array
    {
        return [
            'nature' => AccountNature::class,
            'is_system' => 'boolean',
        ];
    }

    /** @return BelongsTo<AccountGroup, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<AccountGroup, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    protected function companyScopedParents(): array
    {
        return ['parent_id' => self::class];
    }
}

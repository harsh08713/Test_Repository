<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\GeographyPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Districts have no natural code in the source data, so they are identified by name within a state.
 *
 * @property int $state_id
 * @property string $name
 */
#[Fillable(['state_id', 'name', 'status', 'remarks'])]
#[UsePolicy(GeographyPolicy::class)]
class District extends TenantModel
{
    use ValidatesParentCompany;

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** @return HasMany<Block, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class);
    }

    protected function companyScopedParents(): array
    {
        return ['state_id' => State::class];
    }
}

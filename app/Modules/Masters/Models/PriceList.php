<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\PriceListPolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named set of rates for a kind of customer. A rate that belongs to no price list is the standard rate.
 *
 * @property string $name
 */
#[Fillable(['code', 'name', 'status', 'remarks'])]
#[UsePolicy(PriceListPolicy::class)]
class PriceList extends TenantModel
{
    /** @return HasMany<Rate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(Rate::class);
    }
}

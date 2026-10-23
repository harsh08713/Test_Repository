<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\GeographyPolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $gst_code two-digit GST state code
 * @property string $name
 */
#[Fillable(['code', 'gst_code', 'name', 'status', 'remarks'])]
#[UsePolicy(GeographyPolicy::class)]
class State extends TenantModel
{
    /** @return HasMany<District, $this> */
    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }
}

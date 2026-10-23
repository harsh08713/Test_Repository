<?php

namespace App\Modules\Masters\Models;

use App\Modules\Masters\Policies\UnitPolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

/**
 * Unit of measure.
 *
 * @property string $name
 * @property bool $decimal_allowed whether quantities in this unit may be fractional
 */
#[Fillable(['code', 'name', 'decimal_allowed', 'status', 'remarks'])]
#[UsePolicy(UnitPolicy::class)]
class Unit extends TenantModel
{
    protected $attributes = [
        'status' => 'active',
        'decimal_allowed' => false,
    ];

    protected function casts(): array
    {
        return ['decimal_allowed' => 'boolean'];
    }
}

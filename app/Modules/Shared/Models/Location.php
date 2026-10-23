<?php

namespace App\Modules\Shared\Models;

use App\Modules\Shared\Models\Scopes\LocationScope;
use App\Modules\Shared\Policies\LocationPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

/**
 * A branch / plant / warehouse. Scoped to the active company and to the branches the user is assigned to.
 *
 * @property string $name
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $state_code
 * @property string|null $pin_code
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $latitude
 * @property string|null $longitude
 */
#[Fillable([
    'code', 'name', 'address_line1', 'address_line2', 'city', 'state', 'state_code', 'pin_code',
    'phone', 'email', 'latitude', 'longitude', 'status', 'remarks',
])]
#[UsePolicy(LocationPolicy::class)]
class Location extends TenantModel
{
    protected static function booted(): void
    {
        // A branch is filtered on its own id and can never be NULL.
        static::addGlobalScope(new LocationScope('id', allowNull: false));
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }
}

<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Policies\TerritoryPolicy;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A territory: a named part of the map with one person responsible for it, and what it covers (`TerritoryArea`). It says who *should* work a place;
 * it never moves a lead, a plan or a target by itself.
 *
 * @property string $name
 * @property int|null $location_id
 * @property int|null $owner_user_id
 */
#[Fillable(['code', 'name', 'status', 'remarks'])]
#[UsePolicy(TerritoryPolicy::class)]
class Territory extends TenantModel
{
    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return HasMany<TerritoryArea, $this> */
    public function areas(): HasMany
    {
        return $this->hasMany(TerritoryArea::class)->orderBy('id');
    }

    protected function auditLogName(): string
    {
        return 'territories';
    }
}

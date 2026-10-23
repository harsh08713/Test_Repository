<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Policies\SalesTeamPolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales team: one manager and the salespeople under them. The manager can plan the days and set the targets of the
 * team's current members (see SalesScope); a person is on one team at a time.
 *
 * @property string $name
 * @property int $manager_user_id
 */
#[Fillable(['code', 'name', 'manager_user_id', 'status', 'remarks'])]
#[UsePolicy(SalesTeamPolicy::class)]
class SalesTeam extends TenantModel
{
    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /** @return HasMany<SalesTeamMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(SalesTeamMember::class)->orderBy('joined_on')->orderBy('id');
    }

    /** @return HasMany<SalesTeamMember, $this> */
    public function currentMembers(): HasMany
    {
        return $this->hasMany(SalesTeamMember::class)->whereNull('left_on');
    }

    protected function auditLogName(): string
    {
        return 'sales_teams';
    }
}

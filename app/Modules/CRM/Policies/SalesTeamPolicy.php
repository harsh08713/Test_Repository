<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Teams: the permission matrix says who may work with teams at all; on top of that a team is run by its manager, or by
 * someone who sees everyone (`sales.scope_all`). Managers see and change only their own teams.
 */
class SalesTeamPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'sales_team';
    }

    private function runs(User $user, Model $model): bool
    {
        return $model instanceof SalesTeam && app(SalesScope::class)->runsTeam($user, $model);
    }

    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) && $this->runs($user, $model);
    }

    public function update(User $user, Model $model): bool
    {
        return parent::update($user, $model) && $this->runs($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model) && $this->runs($user, $model);
    }

    /** Put people on the team and take them off. */
    public function manageMembers(User $user, SalesTeam $team): bool
    {
        return $this->allows($user, PermissionAction::Assign) && $this->withinTenant($user, $team) && $this->runs($user, $team) && $team->status === 'active';
    }
}

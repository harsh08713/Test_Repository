<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Modules\CRM\Models\SalesTarget;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Models\SalesTeamMember;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Targets: a person sees their own and their team's; a manager sees and sets those of the people they manage and of
 * their teams; whoever holds `sales.scope_all` sees and sets everyone's. A target whose period is over is history.
 */
class SalesTargetPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'target';
    }

    private function sees(User $user, SalesTarget $target): bool
    {
        $scope = app(SalesScope::class);

        if ($target->user_id !== null) {
            return $scope->sees($user, (int) $target->user_id);
        }

        $team = SalesTeam::query()->find($target->sales_team_id);

        return $team !== null && ($scope->runsTeam($user, $team) || $this->isMember($user, $team));
    }

    private function sets(User $user, SalesTarget $target): bool
    {
        $scope = app(SalesScope::class);

        if ($target->user_id !== null) {
            return $scope->manages($user, (int) $target->user_id);
        }

        $team = SalesTeam::query()->find($target->sales_team_id);

        return $team !== null && $scope->runsTeam($user, $team);
    }

    private function isMember(User $user, SalesTeam $team): bool
    {
        return SalesTeamMember::query()->where('sales_team_id', $team->getKey())->where('user_id', $user->getKey())->whereNull('left_on')->exists();
    }

    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) && $model instanceof SalesTarget && $this->sees($user, $model);
    }

    public function update(User $user, Model $model): bool
    {
        return parent::update($user, $model) && $model instanceof SalesTarget && ! $model->isOver() && $this->sets($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model) && $model instanceof SalesTarget && ! $model->isOver() && $this->sets($user, $model);
    }
}

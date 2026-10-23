<?php

namespace App\Modules\Workspace\Services;

use App\Models\User;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\Workspace\Models\Task;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whose tasks a person sees and works — the one answer used by the screens, the policy, the dashboard and the reports:
 *
 *  - a person sees the tasks given to them and the ones they made (personal tasks, and tasks they gave to somebody else);
 *  - a team manager also sees the tasks of the people on their teams, and works them (reassigns, completes, calls off);
 *  - a holder of `sales.scope_all` sees everyone's.
 *
 * Seeing a task is not the same as being allowed to change it: the policy adds the permission and the task's state.
 */
final class TaskAccess
{
    public function __construct(private readonly SalesScope $scope, private readonly SalesTeamService $teams) {}

    public function sees(User $user, Task $task): bool
    {
        if ((int) $task->created_by === (int) $user->getKey()) {
            return true;
        }

        return $this->scope->sees($user, (int) $task->assigned_to);
    }

    /** May this person change the task: it is theirs, they made it, or they manage the person it is with. */
    public function works(User $user, Task $task): bool
    {
        return (int) $task->assigned_to === (int) $user->getKey()
            || (int) $task->created_by === (int) $user->getKey()
            || $this->scope->manages($user, (int) $task->assigned_to);
    }

    /**
     * Limit a task query to what $user may see.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function restrict(Builder $query, User $user): Builder
    {
        $visible = $this->scope->visibleUserIds($user);

        if ($visible === null) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        return $query->where(fn (Builder $q) => $q->whereIn("{$table}.assigned_to", $visible)->orWhere("{$table}.created_by", $user->getKey()));
    }

    /**
     * Who $user may give a task to: themselves and the people they manage — anyone in the company for a holder of `sales.scope_all`.
     *
     * @return array<int, string> id => name
     */
    public function assignable(User $user): array
    {
        $staff = $this->teams->staff();
        $reach = $this->scope->visibleUserIds($user);

        return $reach === null ? $staff : array_intersect_key($staff, array_flip($reach));
    }
}

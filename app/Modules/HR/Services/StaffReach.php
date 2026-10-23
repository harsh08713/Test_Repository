<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whose leave, punches and corrections a person may see and decide, in one place for every H2 screen (never a role name):
 *   - their own, always;
 *   - everyone who reports to them (directly or further down) if they hold the "view" permission;
 *   - everyone, if they hold the "all" permission (HR).
 * Deciding is the same reach but needs the "approve" permission and is **never** for one's own request: nobody decides for themself.
 */
final class StaffReach
{
    public function __construct(private readonly EmployeeAccess $access) {}

    /** @return list<int> the employee ids this person may see through `$view`, or `[]` when they may see everyone (check `seesAll`) */
    public function visibleIds(User $user, string $view): array
    {
        $own = $this->access->own($user);
        $ids = $own === null ? [] : [$own->id];

        if ($own !== null && $user->can($view)) {
            $ids = [...$ids, ...$this->access->belowIds($own->id)];
        }

        return $ids;
    }

    public function seesAll(User $user, string $all): bool
    {
        return $user->can($all);
    }

    /**
     * Narrow a query on a table with an `employee_id` column to what this person may see.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function restrict(Builder $query, User $user, string $all, string $view): Builder
    {
        if ($this->seesAll($user, $all)) {
            return $query;
        }

        return $query->whereIn($query->getModel()->getTable().'.employee_id', $this->visibleIds($user, $view));
    }

    /**
     * The employee files this person reaches through `$view` (or all of them with `$all`), still working here.
     *
     * @return Builder<Employee>
     */
    public function employees(User $user, string $all, string $view): Builder
    {
        $query = Employee::query()->whereIn('employment_status', ['active', 'notice']);

        return $this->seesAll($user, $all) ? $query : $query->whereIn('employees.id', $this->visibleIds($user, $view));
    }

    public function sees(User $user, Employee $employee, string $all, string $view): bool
    {
        return $this->seesAll($user, $all) || in_array($employee->id, $this->visibleIds($user, $view), true);
    }

    /** May this person decide something of `$employee`? Not their own; within reach; with the approve right (or the all right). */
    public function decides(User $user, Employee $employee, string $all, string $approve): bool
    {
        if ($this->access->isOwn($user, $employee)) {
            return false;
        }

        if ($user->can($all)) {
            return true;
        }

        $own = $this->access->own($user);

        return $own !== null && $user->can($approve) && in_array($employee->id, $this->access->belowIds($own->id), true);
    }
}

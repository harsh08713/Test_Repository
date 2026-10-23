<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whose employee file a person may see. Never a role name:
 *   - `employee.view_all` (HR, management): every employee of the company they work in;
 *   - otherwise: their own file, and the files of everyone who reports to them, directly or further down.
 * Everyone may open **their own** file (the "My profile" page) whatever else they hold. The sensitive fields (date of birth, personal e-mail, address, emergency
 * contacts, papers on file) need `employee.view_sensitive`, except on one's own file.
 */
final class EmployeeAccess
{
    /** @var array<int, Employee|null> */
    private array $own = [];

    /** The employee file linked to this login, in the company being worked in (null when there is none). */
    public function own(User $user): ?Employee
    {
        return $this->own[$user->id] ??= Employee::query()->where('user_id', $user->id)->first();
    }

    /** Forget what was remembered (tests build records between requests). */
    public function flush(): void
    {
        $this->own = [];
    }

    public function seesAll(User $user): bool
    {
        return $user->can('employee.view_all');
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function restrict(Builder $query, User $user): Builder
    {
        if ($this->seesAll($user)) {
            return $query;
        }

        return $query->whereIn($query->getModel()->getTable().'.id', $this->reachIds($user));
    }

    public function sees(User $user, Employee $employee): bool
    {
        return $this->seesAll($user) || in_array($employee->id, $this->reachIds($user), true);
    }

    public function isOwn(User $user, Employee $employee): bool
    {
        return $employee->user_id !== null && $employee->user_id === $user->id;
    }

    public function seesSensitive(User $user, Employee $employee): bool
    {
        return $this->isOwn($user, $employee) || $user->can('employee.view_sensitive');
    }

    /** @return list<int> the person's own file and everyone below it in the reporting line */
    public function reachIds(User $user): array
    {
        $own = $this->own($user);

        return $own === null ? [] : [$own->id, ...$this->belowIds($own->id)];
    }

    /** @return list<int> everyone who reports to $rootId, directly or further down (a cycle cannot loop it: each id is taken once) */
    public function belowIds(int $rootId): array
    {
        $seen = [$rootId => true];
        $below = [];
        $frontier = [$rootId];

        while ($frontier !== []) {
            $next = [];

            foreach (Employee::query()->whereIn('reports_to_id', $frontier)->pluck('id') as $id) {
                if (! isset($seen[$id])) {
                    $seen[$id] = true;
                    $below[] = (int) $id;
                    $next[] = (int) $id;
                }
            }

            $frontier = $next;
        }

        return $below;
    }
}

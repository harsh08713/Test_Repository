<?php

namespace App\Modules\HR\Policies;

use App\Models\User;
use App\Modules\HR\Models\AttendanceRegularisation;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Services\StaffReach;
use Illuminate\Database\Eloquent\Model;

/**
 * A leave request or an attendance regularisation: seen by its owner, by those who see the person (their manager line with the view right, or HR), never made, edited or
 * deleted through a policy — the service does that and checks who may. (Used for both models: they share the person and the branch.)
 */
class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;   // everyone may open their own list; the query narrows it
    }

    public function view(User $user, Model $model): bool
    {
        if (! ($model instanceof LeaveRequest || $model instanceof AttendanceRegularisation)) {
            return false;
        }

        $employee = Employee::query()->find($model->employee_id);

        if ($employee === null) {
            return false;
        }

        [$all, $view] = $model instanceof LeaveRequest ? ['leave.approve_all', 'leave.view'] : ['staff_attendance.approve_all', 'staff_attendance.view'];

        return $employee->user_id === $user->id || app(StaffReach::class)->sees($user, $employee, $all, $view);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}

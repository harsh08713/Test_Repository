<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Notifications\HrNotice;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells people what HR work has landed on them. Always **after** the change has been saved, and a failure to tell (a mail server down) is logged and never undoes or blocks the
 * change. Only active logins are told, and never the person who did the thing.
 */
final class HrNotifier
{
    /** The login of the employee's manager, if they have one. */
    public function managerOf(Employee $employee): ?User
    {
        if ($employee->reports_to_id === null) {
            return null;
        }

        $manager = Employee::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $employee->company_id)->find($employee->reports_to_id);

        return $manager === null || $manager->user_id === null ? null : User::query()->where('is_active', true)->find($manager->user_id);
    }

    /** The login of an employee, if they have one. */
    public function loginOf(Employee $employee): ?User
    {
        return $employee->user_id === null ? null : User::query()->where('is_active', true)->find($employee->user_id);
    }

    public function tell(?User $who, ?User $except, string $kind, string $subject, string $message, string $url): void
    {
        if ($who === null || ($except !== null && $who->id === $except->id)) {
            return;
        }

        try {
            $who->notify(new HrNotice($kind, $subject, $message, $url));
        } catch (Throwable $e) {
            Log::warning('HR notice could not be sent', ['kind' => $kind, 'user' => $who->id, 'error' => $e->getMessage()]);
        }
    }
}

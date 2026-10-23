<?php

namespace App\Modules\HR\Policies;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\EmployeeAccess;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/** An employee file is never deleted (a person who leaves is exited, and the history stays). Viewing follows EmployeeAccess. */
class EmployeePolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'employee';
    }

    public function view(User $user, Model $model): bool
    {
        if (! $model instanceof Employee || ! $this->withinTenant($user, $model)) {
            return false;
        }

        $access = app(EmployeeAccess::class);

        return $access->isOwn($user, $model) || ($this->allows($user, PermissionAction::View) && $access->sees($user, $model));
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}

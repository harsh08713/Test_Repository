<?php

namespace App\Modules\Maintenance\Policies;

use App\Models\User;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/** Preventive schedules: the matrix says who may make and change them. A schedule is never deleted — it is made inactive. */
class MaintenancePlanPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'maintenance_plan';
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}

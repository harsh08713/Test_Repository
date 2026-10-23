<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Modules\CRM\Models\AttendanceRecord;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Attendance: a person marks their own (`attendance.create`) and sees their own; a manager sees the people they manage;
 * `sales.scope_all` sees everyone. Records are append-only, so there is no edit or delete.
 */
class AttendancePolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'attendance';
    }

    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) && $model instanceof AttendanceRecord && app(SalesScope::class)->sees($user, (int) $model->user_id);
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

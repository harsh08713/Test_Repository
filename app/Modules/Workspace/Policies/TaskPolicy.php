<?php

namespace App\Modules\Workspace\Policies;

use App\Models\User;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Services\TaskAccess;
use Illuminate\Database\Eloquent\Model;

/**
 * Tasks: the permission matrix says what a person may do with tasks at all, TaskAccess says which tasks, and the task's state says what is
 * still possible — a task that is done or called off is a record and does not change. A task is called off, never deleted.
 */
class TaskPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'task';
    }

    public function view(User $user, Model $model): bool
    {
        return $model instanceof Task && parent::view($user, $model) && app(TaskAccess::class)->sees($user, $model);
    }

    /** Edit, start, complete, call off. */
    public function update(User $user, Model $model): bool
    {
        return $model instanceof Task && $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $model)
            && $model->isLive() && app(TaskAccess::class)->works($user, $model);
    }

    /** Give it to somebody else (or take it back): needs the assign right as well as the right to work the task. */
    public function assign(User $user, Model $model): bool
    {
        return $this->update($user, $model) && $this->allows($user, PermissionAction::Assign);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}

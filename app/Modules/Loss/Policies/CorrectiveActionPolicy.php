<?php

namespace App\Modules\Loss\Policies;

use App\Models\User;
use App\Modules\Loss\Models\CorrectiveAction;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Corrective and preventive actions. Its owner works it (starts it, says it is done); someone holding `corrective_action.approve` who is not its
 * owner verifies it and records the result — maker–checker on the savings figure. Nothing is deleted.
 */
class CorrectiveActionPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'corrective_action';
    }

    public function view(User $user, Model $model): bool
    {
        return $this->withinTenant($user, $model)
            && ($this->allows($user, PermissionAction::View) || ($model instanceof CorrectiveAction && (int) $model->owner_id === (int) $user->getKey()));
    }

    /** Start it, or say it is done: its owner, or someone who may edit actions. */
    public function work(User $user, CorrectiveAction $action): bool
    {
        return $this->withinTenant($user, $action) && ! $action->isFinished()
            && ((int) $action->owner_id === (int) $user->getKey() || $this->allows($user, PermissionAction::Edit));
    }

    /** Verify it (or send it back, or reject it): the approval right, and not its own owner. */
    public function verify(User $user, CorrectiveAction $action): bool
    {
        return $this->allows($user, PermissionAction::Approve) && $this->withinTenant($user, $action) && (int) $action->owner_id !== (int) $user->getKey()
            && ($action->approver_id === null || (int) $action->approver_id === (int) $user->getKey());
    }

    public function update(User $user, Model $model): bool
    {
        return $model instanceof CorrectiveAction && $this->work($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}

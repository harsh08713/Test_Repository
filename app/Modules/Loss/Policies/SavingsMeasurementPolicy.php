<?php

namespace App\Modules\Loss\Policies;

use App\Models\User;
use App\Modules\Loss\Models\SavingsMeasurement;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Savings measurements. Quality/Loss managers measure (`create` / `edit`), Finance verifies (`approve`) — never the person who measured it and never
 * the owner of the corrective action (maker–checker on the savings figure). A measurement is only edited while it is a draft; nothing is deleted.
 */
class SavingsMeasurementPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'savings_measurement';
    }

    public function update(User $user, Model $model): bool
    {
        return $model instanceof SavingsMeasurement && $model->state === 'draft' && $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $model);
    }

    /** Submit it for Finance: whoever may edit it. */
    public function submit(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    /** Verify or reject it: the approval right, and neither the person who measured it nor the action's owner. */
    public function decide(User $user, SavingsMeasurement $measurement): bool
    {
        return $measurement->state === 'submitted' && $this->allows($user, PermissionAction::Approve) && $this->withinTenant($user, $measurement)
            && (int) $measurement->created_by !== (int) $user->getKey() && (int) $measurement->action->owner_id !== (int) $user->getKey();
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}

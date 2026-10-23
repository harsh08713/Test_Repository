<?php

namespace App\Modules\Quality\Policies;

use App\Models\User;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Inspections: making one and recording results is `inspection.create` / `inspection.edit`; rejecting a failed one is `inspection.decide`; accepting a failed one with a deviation is
 * `inspection.deviate` and never by the person who did the inspection. Nothing is deleted.
 */
class InspectionPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'inspection';
    }

    public function record(User $user, Inspection $inspection): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $inspection) && $inspection->isOpen();
    }

    public function decide(User $user, Inspection $inspection): bool
    {
        return $user->can('inspection.decide') && $this->withinTenant($user, $inspection) && $inspection->state === 'failed';
    }

    public function deviate(User $user, Inspection $inspection): bool
    {
        return $user->can('inspection.deviate') && $this->withinTenant($user, $inspection) && $inspection->state === 'failed' && $inspection->inspector_id !== $user->getKey();
    }

    public function update(User $user, Model $model): bool
    {
        return false;   // an inspection is not edited: its results are recorded and its state moves through the service
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function viewAudit(User $user, Inspection $inspection): bool
    {
        return $user->can('inspection.view_audit') && $this->view($user, $inspection);
    }
}

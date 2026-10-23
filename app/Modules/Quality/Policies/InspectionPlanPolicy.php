<?php

namespace App\Modules\Quality\Policies;

use App\Models\User;
use App\Modules\Quality\Models\InspectionPlan;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/** Inspection plans: the matrix says who may make, change and approve them; a waiting or approved plan is locked. Making one inactive is a right of editing. Nothing is deleted. */
class InspectionPlanPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'inspection_plan';
    }

    public function toggle(User $user, InspectionPlan $plan): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $plan) && ! $plan->isPendingApproval();
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function viewAudit(User $user, InspectionPlan $plan): bool
    {
        return $user->can('inspection_plan.view_audit') && $this->view($user, $plan);
    }
}

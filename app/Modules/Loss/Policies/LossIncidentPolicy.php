<?php

namespace App\Modules\Loss\Policies;

use App\Models\User;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Services\LossWorkflowService;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Loss incidents: the matrix says who may report, work, assign and validate them; the branch scopes say where. Nothing about an incident changes
 * once it has reached a final state (closed, rejected) — it is re-opened through its workflow. Which *moves* a person may make is decided by the
 * workflow's transitions (LossWorkflowService), each naming its permission. Nothing is deleted.
 */
class LossIncidentPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'loss_incident';
    }

    private function open(Model $model): bool
    {
        return $model instanceof LossIncident && ! app(LossWorkflowService::class)->isFinal($model);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $model) && $this->open($model);
    }

    /** Name the investigator and the accountable owner. */
    public function assign(User $user, LossIncident $incident): bool
    {
        return $this->allows($user, PermissionAction::Assign) && $this->withinTenant($user, $incident) && $this->open($incident);
    }

    /** Add or recalculate impact lines, record containment and root causes. */
    public function investigate(User $user, LossIncident $incident): bool
    {
        return $this->update($user, $incident);
    }

    /** Validate or reject an impact line (only someone who did not enter it: LossCalculationService checks the line). */
    public function validateImpact(User $user, LossIncident $incident): bool
    {
        return $this->allows($user, PermissionAction::Approve) && $this->withinTenant($user, $incident);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function viewAudit(User $user, LossIncident $incident): bool
    {
        return $user->can('loss_incident.view_audit') && $this->view($user, $incident);
    }
}

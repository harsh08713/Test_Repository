<?php

namespace App\Modules\Marketing\Policies;

use App\Models\User;
use App\Modules\Marketing\Models\Workflow;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/** Workflows: seen, made and changed by the `workflow` rights; switching one on, off or to dry-run is `configure`; a workflow is never deleted. */
class WorkflowPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'workflow';
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    /** Activate, pause, resume, switch dry-run, retry a failed run. */
    public function operate(User $user, Workflow $workflow): bool
    {
        return $this->allows($user, PermissionAction::Configure) && $this->withinTenant($user, $workflow);
    }
}

<?php

namespace App\Modules\Quality\Policies;

use App\Models\User;
use App\Modules\Quality\Models\NonConformance;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/** Non-conformance reports: made and decided by those holding the matrix rights, closed by `non_conformance.close`. A closed report is a record. Nothing is deleted. */
class NonConformancePolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'non_conformance';
    }

    public function update(User $user, Model $model): bool
    {
        return $model instanceof NonConformance && $model->state !== 'closed' && $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $model);
    }

    public function close(User $user, NonConformance $ncr): bool
    {
        return $user->can('non_conformance.close') && $this->withinTenant($user, $ncr) && $ncr->state === 'decided';
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function viewAudit(User $user, NonConformance $ncr): bool
    {
        return $user->can('non_conformance.view_audit') && $this->view($user, $ncr);
    }
}

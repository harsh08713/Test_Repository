<?php

namespace App\Modules\Production\Policies;

use App\Models\User;
use App\Modules\Production\Models\Bom;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/** Bills of materials: the matrix says who may make, change and approve them; a waiting or approved bill is locked. Making one inactive is a right of editing. Nothing is deleted. */
class BomPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'bom';
    }

    public function toggle(User $user, Bom $bom): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $bom) && ! $bom->isPendingApproval();
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function viewAudit(User $user, Bom $bom): bool
    {
        return $user->can('bom.view_audit') && $this->view($user, $bom);
    }
}

<?php

namespace App\Modules\Procurement\Policies;

use App\Models\User;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/** Goods receipts: the matrix says who may make, change and approve them; a pending or approved one is locked and none is deleted. */
class GrnPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'grn';
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function viewAudit(User $user, Grn $grn): bool
    {
        return $user->can('grn.view_audit') && $this->view($user, $grn);
    }
}

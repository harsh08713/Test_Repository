<?php

namespace App\Modules\Procurement\Policies;

use App\Models\User;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Purchase orders: the matrix says who may make, change and approve them; a pending or approved order is locked. Cancelling is its own right
 * (`purchase_order.edit`) and only while nothing has been received against it that is still standing. Nothing is deleted.
 */
class PurchaseOrderPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'purchase_order';
    }

    public function cancel(User $user, PurchaseOrder $order): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $order) && ! $order->isCancelled() && ! $order->isPendingApproval();
    }

    /** Receive goods against it: it has to be approved and open, and the person has to be able to make a receipt. */
    public function receive(User $user, PurchaseOrder $order): bool
    {
        return $user->can('grn.create') && $this->withinTenant($user, $order) && $order->isReceivable();
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function viewAudit(User $user, PurchaseOrder $order): bool
    {
        return $user->can('purchase_order.view_audit') && $this->view($user, $order);
    }
}

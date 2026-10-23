<?php

namespace App\Modules\Maintenance\Policies;

use App\Models\User;
use App\Modules\Maintenance\Models\MaintenanceOrder;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Work orders: anyone who may create can report a breakdown; planning and changing a work order that has not started is `maintenance_order.edit`; assigning is
 * `maintenance_order.assign`; doing the work (start, tick tasks, issue parts, log hours, complete) is `maintenance_order.execute`, and only on an order that is open and either unassigned, assigned to
 * the person, or the person may assign. Nothing is deleted.
 */
class MaintenanceOrderPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'maintenance_order';
    }

    public function update(User $user, Model $model): bool
    {
        return $model instanceof MaintenanceOrder && $model->state === 'open' && $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $model);
    }

    public function assign(User $user, MaintenanceOrder $order): bool
    {
        return $user->can('maintenance_order.assign') && $this->withinTenant($user, $order) && $order->isOpen();
    }

    public function execute(User $user, MaintenanceOrder $order): bool
    {
        return $user->can('maintenance_order.execute') && $this->withinTenant($user, $order) && $order->isOpen()
            && ($order->assigned_to === null || (int) $order->assigned_to === (int) $user->getKey() || $user->can('maintenance_order.assign'));
    }

    public function link(User $user, MaintenanceOrder $order): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $order) && ! $order->isFinished();
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function viewAudit(User $user, MaintenanceOrder $order): bool
    {
        return $user->can('maintenance_order.view_audit') && $this->view($user, $order);
    }
}

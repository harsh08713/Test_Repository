<?php

namespace App\Modules\Production\Policies;

use App\Models\User;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Production orders: the matrix says who may plan them; releasing, completing and cancelling are `production_order.release`; issuing material and reporting output are
 * `production_order.execute` (the people on the shop floor). A draft can be changed; once released only the entries move. Nothing is deleted.
 */
class ProductionOrderPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'production_order';
    }

    public function update(User $user, Model $model): bool
    {
        return $model instanceof ProductionOrder && $model->state === 'draft' && $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $model);
    }

    public function release(User $user, ProductionOrder $order): bool
    {
        return $user->can('production_order.release') && $this->withinTenant($user, $order);
    }

    public function execute(User $user, ProductionOrder $order): bool
    {
        return $user->can('production_order.execute') && $this->withinTenant($user, $order) && $order->isOpen();
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function viewAudit(User $user, ProductionOrder $order): bool
    {
        return $user->can('production_order.view_audit') && $this->view($user, $order);
    }
}

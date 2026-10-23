<?php

namespace App\Modules\Sales\Policies;

use App\Models\User;
use App\Modules\Sales\Enums\CreditResult;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderAccess;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Sales orders: the permission matrix says what a person may do with orders at all; SalesOrderAccess says *which* — the ones credited to them,
 * their team's, or everyone's — and lets Accounts and Operations see every order and an approver open what they are asked to decide. What may
 * still be done depends on the order: only an open draft (or a returned one) changes, nothing is deleted, and cancelling is its own right
 * (`sales_order.delete`).
 */
class SalesOrderPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'sales_order';
    }

    private function sees(User $user, Model $model): bool
    {
        return $model instanceof SalesOrder && app(SalesOrderAccess::class)->sees($user, $model);
    }

    private function works(User $user, Model $model): bool
    {
        return $model instanceof SalesOrder && app(SalesOrderAccess::class)->works($user, $model);
    }

    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) && $this->sees($user, $model);
    }

    /** Change the order: only an open draft or returned one. */
    public function update(User $user, Model $model): bool
    {
        return $model instanceof SalesOrder && $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $model)
            && $this->works($user, $model) && $model->isContentEditable();
    }

    /** Send a draft or returned order for approval. */
    public function submit(User $user, Model $model): bool
    {
        return $model instanceof SalesOrder && $this->update($user, $model);
    }

    /** Decide a level of the order's approval: the permission, and being able to open the order at all. */
    public function approve(User $user, Model $model): bool
    {
        return $model instanceof SalesOrder && parent::approve($user, $model) && $this->sees($user, $model);
    }

    /** Take an approved order back to editing — possible only while no invoice has been made from it. */
    public function reopenApproval(User $user, SalesOrder $order): bool
    {
        return $order->isApproved() && $order->state->value === 'open' && $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $order) && $this->works($user, $order);
    }

    /** Make the invoice of an approved order that has none yet (the retry for an approval whose invoice could not be made). */
    public function makeInvoice(User $user, SalesOrder $order): bool
    {
        return $order->isApproved() && $order->state->value === 'open' && $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $order) && $this->works($user, $order);
    }

    /** Allow an order that takes the customer over their credit limit, with a reason. */
    public function overrideCredit(User $user, SalesOrder $order): bool
    {
        return $user->can('credit_limit.override') && $this->withinTenant($user, $order) && $this->sees($user, $order)
            && $order->isContentEditable() && $order->credit_result === CreditResult::Blocked;
    }

    /** Withdraw an order (and the invoice made from it). */
    public function cancel(User $user, SalesOrder $order): bool
    {
        return $this->allows($user, PermissionAction::Delete) && $this->withinTenant($user, $order) && $this->works($user, $order)
            && ! $order->isCancelled() && ! $order->isPendingApproval();
    }

    /** Download the order acknowledgment: anyone who may open the order. */
    public function download(User $user, SalesOrder $order): bool
    {
        return $this->view($user, $order);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;   // never deleted: its number stays accounted for; it is cancelled
    }

    public function viewAudit(User $user, SalesOrder $order): bool
    {
        return $user->can('sales_order.view_audit') && $this->view($user, $order);
    }
}

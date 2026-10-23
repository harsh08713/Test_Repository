<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\CRM\Services\ApproverReach;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whose sales orders and invoices a person may see and work — the one rule the policies, the lists and the downloads share.
 *
 * An order belongs to the salesperson credited with it (`assigned_to`, set when it is made and never moved by a later hand-over of the lead:
 * targets count it for them). An invoice belongs to the same person as its order.
 *  - **Work** an order (change it, send it for approval, cancel it): that salesperson, the people who manage them, and holders of
 *    `sales.scope_all` (SalesScope).
 *  - **See** an order or an invoice: everyone who may work it; everyone holding **`sales_order.view_all`** (Accounts and Operations, who
 *    handle every order though they sell none); and, for an order, an approver for one waiting for their decision or one they decided.
 *
 * The company and branch boundaries are not decided here: every query already runs through the tenant scopes.
 */
final class SalesOrderAccess
{
    public function __construct(private readonly SalesScope $scope, private readonly ApproverReach $approvers) {}

    public function works(User $user, SalesOrder $order): bool
    {
        return $this->scope->sees($user, (int) $order->assigned_to);
    }

    public function sees(User $user, SalesOrder $order): bool
    {
        return $user->can('sales_order.view_all') || $this->works($user, $order) || $this->approvers->involves($user, $order, 'sales_order.approve');
    }

    public function seesInvoice(User $user, SalesInvoice $invoice): bool
    {
        return $user->can('sales_order.view_all') || $this->scope->sees($user, (int) $invoice->assigned_to);
    }

    /** A return or credit note is seen by whoever may see its invoice, and by an approver asked to decide it. */
    public function seesReturn(User $user, SalesReturn $return): bool
    {
        return $user->can('sales_order.view_all') || $this->scope->sees($user, (int) $return->assigned_to) || $this->approvers->involves($user, $return, 'sales_return.approve');
    }

    /**
     * Limit a return query to what $user may see.
     *
     * @param  Builder<SalesReturn>  $query
     * @return Builder<SalesReturn>
     */
    public function restrictReturns(Builder $query, User $user): Builder
    {
        $visible = $user->can('sales_order.view_all') ? null : $this->scope->visibleUserIds($user);

        if ($visible === null) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $q) use ($visible, $user, $table) {
            $q->whereIn("{$table}.assigned_to", $visible);
            $this->approvers->widen($q, $user, 'sales_return.approve');
        });
    }

    /**
     * Limit an order query to what $user may see.
     *
     * @param  Builder<SalesOrder>  $query
     * @return Builder<SalesOrder>
     */
    public function restrict(Builder $query, User $user): Builder
    {
        $visible = $user->can('sales_order.view_all') ? null : $this->scope->visibleUserIds($user);

        if ($visible === null) {
            return $query;   // everyone's
        }

        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $q) use ($visible, $user, $table) {
            $q->whereIn("{$table}.assigned_to", $visible);

            // …and the orders it falls to this person to approve
            $this->approvers->widen($q, $user, 'sales_order.approve');
        });
    }

    /**
     * Limit an invoice query to what $user may see.
     *
     * @param  Builder<SalesInvoice>  $query
     * @return Builder<SalesInvoice>
     */
    public function restrictInvoices(Builder $query, User $user): Builder
    {
        $visible = $user->can('sales_order.view_all') ? null : $this->scope->visibleUserIds($user);

        return $visible === null ? $query : $query->whereIn($query->getModel()->getTable().'.assigned_to', $visible);
    }
}

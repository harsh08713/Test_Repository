<?php

namespace App\Modules\Sales\Policies;

use App\Models\User;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesOrderAccess;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Sales returns and credit notes. The matrix says what a person may do with them at all; SalesOrderAccess says *which* — those of invoices
 * credited to them, their team's, or everyone's (Accounts, Operations). A return that is pending or approved is locked, and none is deleted.
 */
class SalesReturnPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'sales_return';
    }

    public function view(User $user, Model $model): bool
    {
        return $model instanceof SalesReturn && parent::view($user, $model) && app(SalesOrderAccess::class)->seesReturn($user, $model);
    }

    /** Make a return against this invoice: the permission, being able to open the invoice, and an invoice that is still issued. */
    public function makeFor(User $user, SalesInvoice $invoice): bool
    {
        return $this->allows($user, PermissionAction::Create) && $this->withinTenant($user, $invoice)
            && app(SalesOrderAccess::class)->seesInvoice($user, $invoice) && $invoice->status === SalesInvoice::ISSUED;
    }

    public function update(User $user, Model $model): bool
    {
        return $model instanceof SalesReturn && parent::update($user, $model) && app(SalesOrderAccess::class)->seesReturn($user, $model);
    }

    public function submit(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    public function approve(User $user, Model $model): bool
    {
        return $model instanceof SalesReturn && parent::approve($user, $model) && app(SalesOrderAccess::class)->seesReturn($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function viewAudit(User $user, SalesReturn $return): bool
    {
        return $user->can('sales_return.view_audit') && $this->view($user, $return);
    }
}

<?php

namespace App\Modules\Sales\Policies;

use App\Models\User;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesOrderAccess;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Sales invoices: made by the system when a sales order is approved, never typed in and never changed, so the only things a person does with
 * one are read it and download its PDF — the invoices of the orders credited to them, their team's, or (Accounts, Operations) everyone's.
 */
class SalesInvoicePolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'sales_invoice';
    }

    public function view(User $user, Model $model): bool
    {
        return $model instanceof SalesInvoice && parent::view($user, $model) && app(SalesOrderAccess::class)->seesInvoice($user, $model);
    }

    public function download(User $user, SalesInvoice $invoice): bool
    {
        return $this->view($user, $invoice);
    }

    public function create(User $user): bool
    {
        return false;   // made by the system from an approved sales order
    }

    public function update(User $user, Model $model): bool
    {
        return false;   // an issued invoice does not change
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function viewAudit(User $user, SalesInvoice $invoice): bool
    {
        return $user->can('sales_invoice.view_audit') && $this->view($user, $invoice);
    }
}

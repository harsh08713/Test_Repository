<?php

namespace App\Modules\Masters\Policies;

use App\Models\User;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may do what to a ledger, on top of the generic permission-matrix rules (permission + tenant + workflow lock).
 *
 * Suppliers are onboarded by the Purchase department, so holding `ledger.create` / `ledger.edit` is enough for a
 * supplier. Customers, banks, cash, expense and general ledgers touch accounting, so they additionally need
 * `ledger.manage_all_types` (Accounts and administrators). Approved ledgers are locked; they can be switched
 * active/inactive, or re-opened (an audited step back to draft) by someone allowed to edit them.
 */
class LedgerPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'ledger';
    }

    private function handles(User $user, LedgerType $type): bool
    {
        return $type === LedgerType::Supplier || $user->can('ledger.manage_all_types');
    }

    /** May this user start a new ledger of this type? */
    public function createOfType(User $user, LedgerType $type): bool
    {
        return $this->create($user) && $this->handles($user, $type);
    }

    public function update(User $user, Model $model): bool
    {
        return $model instanceof Ledger && parent::update($user, $model) && $this->handles($user, $model->ledger_type);
    }

    public function delete(User $user, Model $model): bool
    {
        return $model instanceof Ledger && parent::delete($user, $model) && $this->handles($user, $model->ledger_type);
    }

    public function submit(User $user, Model $model): bool
    {
        return $model instanceof Ledger && parent::submit($user, $model) && $this->handles($user, $model->ledger_type);
    }

    /** Activate / deactivate: allowed on an approved ledger too, because it changes availability, not content. */
    public function changeStatus(User $user, Ledger $ledger): bool
    {
        return $this->allows($user, PermissionAction::Edit)
            && $this->withinTenant($user, $ledger)
            && $this->handles($user, $ledger->ledger_type);
    }

    /** Take an approved ledger back to draft so it can be corrected and approved again. */
    public function reopen(User $user, Ledger $ledger): bool
    {
        return $ledger->isApproved() && $this->changeStatus($user, $ledger);
    }

    /** The full bank account number (everyone else sees it masked). */
    public function viewSensitive(User $user, Ledger $ledger): bool
    {
        return $user->can('ledger.view_sensitive') && $this->view($user, $ledger);
    }

    public function viewAudit(User $user, Ledger $ledger): bool
    {
        return $user->can('ledger.view_audit') && $this->view($user, $ledger);
    }
}

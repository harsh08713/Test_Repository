<?php

namespace App\Modules\Masters\Policies;

use App\Models\User;
use App\Modules\Masters\Models\Rate;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;

/**
 * Who may do what to a rate, on top of the generic permission-matrix rules (permission + tenant and branch +
 * workflow lock). Pending and approved rates are locked. An approved rate can still be switched on or off, ended
 * early (which only shortens it, so nothing unapproved is exposed) or re-opened for correction.
 */
class RatePolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'rate';
    }

    /** Activate / deactivate: allowed on an approved rate too, because it changes availability, not the price. */
    public function changeStatus(User $user, Rate $rate): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $rate);
    }

    /** Stop an approved rate earlier than planned (or give an open-ended one an end). */
    public function endDate(User $user, Rate $rate): bool
    {
        return $rate->isApproved() && $this->changeStatus($user, $rate);
    }

    /** Take an approved rate back to draft so its price or dates can be corrected and approved again. */
    public function reopen(User $user, Rate $rate): bool
    {
        return $rate->isApproved() && $this->changeStatus($user, $rate);
    }

    public function viewAudit(User $user, Rate $rate): bool
    {
        return $user->can('rate.view_audit') && $this->view($user, $rate);
    }
}

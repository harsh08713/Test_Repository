<?php

namespace App\Modules\Sales\Policies;

use App\Models\User;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Services\QuotationAccess;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Quotations: the permission matrix says what a person may do with quotations at all; QuotationAccess says *which* — those of
 * their own leads, their team's, or everyone's — and lets an approver open what they are asked to decide. What may be done also
 * depends on the revision: only a draft changes, only an approved one is sent, only the latest can be revised, and a
 * quotation is never deleted (it is cancelled).
 */
class QuotationPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'quotation';
    }

    private function sees(User $user, Model $model): bool
    {
        return $model instanceof Quotation && app(QuotationAccess::class)->sees($user, $model);
    }

    private function works(User $user, Model $model): bool
    {
        return $model instanceof Quotation && app(QuotationAccess::class)->works($user, $model);
    }

    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) && $this->sees($user, $model);
    }

    /** Change the revision: only a draft that is not in approval. */
    public function update(User $user, Model $model): bool
    {
        return $model instanceof Quotation && $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $model)
            && $this->works($user, $model) && $model->isContentEditable();
    }

    /** Send a draft or returned revision for approval. */
    public function submit(User $user, Model $model): bool
    {
        return $model instanceof Quotation && $this->update($user, $model);
    }

    /** Decide a level of the quotation's approval: the permission, and being able to open the quotation at all. */
    public function approve(User $user, Model $model): bool
    {
        return $model instanceof Quotation && parent::approve($user, $model) && $this->sees($user, $model);
    }

    /** Take an approved, unsent draft back to editing (it needs approving again). */
    public function reopenApproval(User $user, Quotation $quotation): bool
    {
        return $quotation->isDraft() && $quotation->isApproved() && $this->work($user, $quotation);
    }

    /**
     * Work this quotation at all — the permission to edit quotations and the reach over this one — whatever state it is in. The
     * API and the screens ask this first and let QuotationService say, with a reason, what the state forbids.
     */
    public function work(User $user, Quotation $quotation): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $quotation) && $this->works($user, $quotation);
    }

    /** Send the approved draft to the customer, or record that it was handed over. */
    public function send(User $user, Quotation $quotation): bool
    {
        return $this->work($user, $quotation) && $quotation->isDraft() && $quotation->isApproved() && $quotation->isLatestRevision();
    }

    /** Record what the customer answered: accepted or declined, for a sent revision. */
    public function respond(User $user, Quotation $quotation): bool
    {
        return $this->work($user, $quotation) && $quotation->state->value === 'sent';
    }

    /** Make the next revision from the latest one, once it has been sent (or declined or has expired). */
    public function revise(User $user, Quotation $quotation): bool
    {
        return $this->allows($user, PermissionAction::Create) && $this->work($user, $quotation) && $quotation->isLatestRevision() && $quotation->state->canBeRevised();
    }

    /** Withdraw a quotation that has not ended. */
    public function cancel(User $user, Quotation $quotation): bool
    {
        return $this->work($user, $quotation) && $quotation->isLatestRevision() && in_array($quotation->state->value, ['draft', 'sent', 'accepted'], true);
    }

    /** Download the PDF: anyone who may open it. */
    public function download(User $user, Quotation $quotation): bool
    {
        return $this->view($user, $quotation);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;   // never deleted: its number stays accounted for; it is cancelled
    }

    public function viewAudit(User $user, Quotation $quotation): bool
    {
        return $user->can('quotation.view_audit') && $this->view($user, $quotation);
    }
}

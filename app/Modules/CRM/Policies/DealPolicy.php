<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Services\DealAccess;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Deals: the permission matrix says what a person may do with deals at all; DealAccess says *which* deals — their own, their
 * team's, or everyone's — and lets an approver open what they are asked to decide. What may still be changed depends on the
 * deal: its customer and lines are locked once it is in approval or approved, and a quotation or order made from it locks them
 * for good; its stage, probability, close date and remarks move on while the deal is open. A deal is never deleted.
 */
class DealPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'deal';
    }

    private function sees(User $user, Model $model): bool
    {
        return $model instanceof Deal && app(DealAccess::class)->sees($user, $model);
    }

    private function works(User $user, Model $model): bool
    {
        return $model instanceof Deal && app(DealAccess::class)->works($user, $model);
    }

    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) && $this->sees($user, $model);
    }

    /** Change the customer and the lines: only while they are still the person's to change (not in approval, not approved, no quotation made). */
    public function update(User $user, Model $model): bool
    {
        return $model instanceof Deal && $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $model)
            && $this->works($user, $model) && $model->isContentEditable();
    }

    /** Change where the deal stands — close date, probability, remarks — which an approved deal may still do while it is open. */
    public function updateProgress(User $user, Deal $deal): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $deal) && $this->works($user, $deal) && $deal->isOpen();
    }

    /** Move it between Discovery and Negotiation, or mark it lost (DealService decides which moves are possible). */
    public function changeStage(User $user, Deal $deal): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $deal) && $this->works($user, $deal)
            && $deal->isOpen() && ! $deal->stage->isSetBySystem();
    }

    /** Mark an open deal as lost — also while a quotation is out for it. */
    public function markLost(User $user, Deal $deal): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $deal) && $this->works($user, $deal) && $deal->isOpen();
    }

    /** Bring a lost deal back to work. Needs its own permission: a lost deal was a decision. */
    public function reopenLost(User $user, Deal $deal): bool
    {
        return $user->can('deal.reopen') && $this->withinTenant($user, $deal) && $this->works($user, $deal) && $deal->stage === DealStage::Lost;
    }

    /** Take an approved deal back to draft to correct its customer or lines (it is unusable until approved again). */
    public function reopenApproval(User $user, Deal $deal): bool
    {
        return $deal->isApproved() && $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $deal) && $this->works($user, $deal)
            && in_array($deal->stage, [DealStage::Discovery, DealStage::Negotiation], true);
    }

    /** Send a draft or rejected deal for approval. */
    public function submit(User $user, Model $model): bool
    {
        return $model instanceof Deal && $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $model)
            && $this->works($user, $model) && $model->isEditableByWorkflow() && $model->isOpen();
    }

    /** Decide a level of the deal's approval: the permission, and being able to open the deal at all. */
    public function approve(User $user, Model $model): bool
    {
        return $model instanceof Deal && parent::approve($user, $model) && $this->sees($user, $model);
    }

    /**
     * Work this deal at all — the permission to edit deals and the reach over this one — whatever state it is in. The mobile API asks
     * this first and lets DealService say, as a conflict with a reason, what the state forbids; the screens use the narrower abilities
     * above, which also hide what the state forbids.
     */
    public function work(User $user, Deal $deal): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $deal) && $this->works($user, $deal);
    }

    /** Hand the deal to someone else, whatever state it is in (see work()). */
    public function assign(User $user, Deal $deal): bool
    {
        return $this->allows($user, PermissionAction::Assign) && $this->withinTenant($user, $deal) && app(DealAccess::class)->manages($user, $deal);
    }

    /** Hand the deal to someone else: while it is open. */
    public function reassign(User $user, Deal $deal): bool
    {
        return $this->assign($user, $deal) && $deal->isOpen();
    }

    public function delete(User $user, Model $model): bool
    {
        return false;   // a deal that will not happen is marked lost, never deleted: its number and its source lead stay accounted for
    }

    public function viewAudit(User $user, Deal $deal): bool
    {
        return $user->can('deal.view_audit') && $this->view($user, $deal);
    }
}

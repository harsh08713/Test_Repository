<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Leads: the permission matrix says what a person may do with leads at all; LeadAccess says *which* leads — their own,
 * their team's, or everyone's — and tells apart seeing a lead (which a planned follow-up on it is enough for) from
 * working it. A lead that has been converted (Deal and beyond) or lost is no longer edited by hand.
 */
class LeadPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'lead';
    }

    private function sees(User $user, Model $model): bool
    {
        return $model instanceof Lead && app(LeadAccess::class)->sees($user, $model);
    }

    private function works(User $user, Model $model): bool
    {
        return $model instanceof Lead && app(LeadAccess::class)->works($user, $model);
    }

    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) && $this->sees($user, $model);
    }

    /** Change the lead's details: only while it is being worked (New, Contacted or Qualified). */
    public function update(User $user, Model $model): bool
    {
        return $model instanceof Lead && parent::update($user, $model) && $this->works($user, $model)
            && in_array($model->stage, [LeadStage::New, LeadStage::Contacted, LeadStage::Qualified], true);
    }

    /** Move it between stages, mark it lost or re-open it (LeadService decides which moves are possible). */
    public function changeStage(User $user, Lead $lead): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $lead) && $this->works($user, $lead)
            && ! $lead->stage->isSetBySystem();
    }

    /** May this person hand leads over at all? (Which leads, and to whom, LeadTransferService checks one by one.) */
    public function transferAny(User $user): bool
    {
        return $this->allows($user, PermissionAction::Assign);
    }

    /** Hand it to someone else. A lead that has become a deal moves with the deal: hand the deal over instead. */
    public function transfer(User $user, Lead $lead): bool
    {
        return $this->transferAny($user) && $this->withinTenant($user, $lead) && $this->works($user, $lead) && $lead->isOpen()
            && ! $lead->isConverted();
    }

    public function delete(User $user, Model $model): bool
    {
        return $model instanceof Lead && parent::delete($user, $model) && app(LeadAccess::class)->manages($user, $model)
            && ! $model->stage->isSetBySystem() && ! $model->deal()->exists();
    }

    /** Enter an inquiry from an existing customer: leads are the person's to enter, and customers' names are the ledger's to show. */
    public function createInquiry(User $user): bool
    {
        return $this->allows($user, PermissionAction::Create) && $user->can('ledger.view');
    }

    /** Turn a qualified lead into a deal — once; DealService checks the rest on the server. */
    public function convert(User $user, Lead $lead): bool
    {
        return $user->can('deal.create') && $this->withinTenant($user, $lead) && $this->works($user, $lead)
            && $lead->stage === LeadStage::Qualified && ! $lead->deal()->exists();
    }

    /** Send an inquiry from an existing customer for approval. */
    public function submit(User $user, Model $model): bool
    {
        return $model instanceof Lead && $model->isExisting() && parent::submit($user, $model) && $this->works($user, $model)
            && in_array($model->stage, [LeadStage::New, LeadStage::Contacted, LeadStage::Qualified], true);
    }

    /** Decide an inquiry's approval: the permission, and being able to open the inquiry at all. */
    public function approve(User $user, Model $model): bool
    {
        return $model instanceof Lead && $model->isExisting() && parent::approve($user, $model) && $this->sees($user, $model);
    }

    /** Take an approved inquiry back to draft to correct it (it cannot be quoted until approved again). */
    public function reopenApproval(User $user, Lead $lead): bool
    {
        return $lead->isExisting() && $lead->isApproved() && $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $lead)
            && $this->works($user, $lead) && in_array($lead->stage, [LeadStage::New, LeadStage::Contacted, LeadStage::Qualified], true);
    }

    /** Plan or log a follow-up on it. */
    public function followUp(User $user, Lead $lead): bool
    {
        return $user->can('followup.create') && $this->withinTenant($user, $lead) && $this->sees($user, $lead) && $lead->isOpen();
    }

    public function viewAudit(User $user, Lead $lead): bool
    {
        return $user->can('lead.view_audit') && $this->view($user, $lead);
    }
}

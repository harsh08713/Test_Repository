<?php

namespace App\Modules\Marketing\Policies;

use App\Models\User;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Campaigns: the `campaign` rights say what a person may do at all; the approval status says whether the content can still change (never after it was
 * sent for approval) and the run state says what is still possible (a launched campaign cannot be edited or launched again, a finished one does
 * nothing more). Approving is the base policy's `approve`, which the workflow limits to someone other than the maker.
 */
class CampaignPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'campaign';
    }

    /** Change its words, audience and steps: only a campaign that is still a draft, and not yet launched. */
    public function update(User $user, Model $model): bool
    {
        return $model instanceof Campaign && parent::update($user, $model) && $model->isDraft();
    }

    public function delete(User $user, Model $model): bool
    {
        return false;   // a campaign is called off, never deleted
    }

    /** Start sending: an approved campaign that has not been launched. */
    public function launch(User $user, Campaign $campaign): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $campaign) && $campaign->isDraft() && $campaign->isApproved();
    }

    /** Pause, resume or call off a campaign that has been launched. */
    public function control(User $user, Campaign $campaign): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $campaign) && $campaign->isLive();
    }

    /** Take an approved campaign that has not been launched back to a draft, with a reason. */
    public function reopen(User $user, Campaign $campaign): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $campaign) && $campaign->isDraft() && $campaign->isApproved();
    }
}

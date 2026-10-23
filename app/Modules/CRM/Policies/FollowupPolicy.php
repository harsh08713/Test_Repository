<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Follow-ups: a person sees and works their own; a manager those of the people they manage; `sales.scope_all` everyone's.
 * Only a planned follow-up can be completed, moved or called off — one that is done is a record of what happened.
 */
class FollowupPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'followup';
    }

    private function sees(User $user, Model $model): bool
    {
        return $model instanceof Followup && app(SalesScope::class)->sees($user, (int) $model->assigned_to);
    }

    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) && $this->sees($user, $model);
    }

    /** Complete, reschedule or cancel it. */
    public function update(User $user, Model $model): bool
    {
        return $model instanceof Followup && parent::update($user, $model) && $this->sees($user, $model) && $model->isPending();
    }

    public function delete(User $user, Model $model): bool
    {
        return false;   // a follow-up is called off, never deleted
    }
}

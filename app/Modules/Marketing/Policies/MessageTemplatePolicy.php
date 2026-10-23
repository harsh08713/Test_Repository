<?php

namespace App\Modules\Marketing\Policies;

use App\Models\User;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/** Message templates: an approval document governed by the `message_template` rights; pending and approved templates are locked by the base policy. */
class MessageTemplatePolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'message_template';
    }

    /** Take a template out of use or put it back: not a change of its words, so an approved template can be switched. */
    public function switch(User $user, Model $model): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $this->withinTenant($user, $model);
    }
}

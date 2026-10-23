<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Lead sources, follow-up outcomes and lost reasons share one permission form (`crm_master`). The standard rows the
 * system relies on (`is_system`) can be renamed or switched off but never deleted.
 */
class CrmMasterPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'crm_master';
    }

    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model) && ! (bool) $model->getAttribute('is_system');
    }
}

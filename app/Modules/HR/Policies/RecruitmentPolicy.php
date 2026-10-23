<?php

namespace App\Modules\HR\Policies;

use App\Models\User;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/** Openings, candidates and applications: HR only (`recruitment.*`). Nothing is deleted. */
class RecruitmentPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'recruitment';
    }

    public function delete(User $user, Model $model): bool
    {
        return false;   // nothing here is deleted: it is closed, withdrawn or finalised, and the history stays
    }
}

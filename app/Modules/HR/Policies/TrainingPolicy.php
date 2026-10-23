<?php

namespace App\Modules\HR\Policies;

use App\Models\User;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/** Training programs: HR (`training.*`). Nothing is deleted. */
class TrainingPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'training';
    }

    public function delete(User $user, Model $model): bool
    {
        return false;   // nothing here is deleted: it is closed, withdrawn or finalised, and the history stays
    }
}

<?php

namespace App\Modules\HR\Policies;

use App\Models\User;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/** Review cycles: HR (`review_cycle.*`). Nothing is deleted. */
class ReviewCyclePolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'review_cycle';
    }

    public function delete(User $user, Model $model): bool
    {
        return false;   // nothing here is deleted: it is closed, withdrawn or finalised, and the history stays
    }
}

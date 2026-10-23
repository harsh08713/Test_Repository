<?php

namespace App\Modules\Finance\Policies;

use App\Models\User;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/** Budgets: the matrix says who makes and approves them; a pending or approved budget is locked (re-open it to change it). Nothing is deleted once approved. */
class BudgetPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'budget';
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}

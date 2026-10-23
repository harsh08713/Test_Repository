<?php

namespace App\Modules\Masters\Policies;

use App\Models\User;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Addresses, contacts and bank details have no life of their own: they may be seen and changed exactly when
 * the ledger they belong to may.
 */
class LedgerChildPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'ledger';
    }

    public function view(User $user, Model $model): bool
    {
        return $this->parentAllows($user, $model, 'view');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->parentAllows($user, $model, 'update');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->parentAllows($user, $model, 'update');
    }

    private function parentAllows(User $user, Model $model, string $ability): bool
    {
        $ledger = $model->getRelationValue('ledger');

        return $ledger instanceof Model && Gate::forUser($user)->allows($ability, $ledger);
    }
}

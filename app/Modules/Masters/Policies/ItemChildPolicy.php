<?php

namespace App\Modules\Masters\Policies;

use App\Models\User;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Unit conversions, aliases and stock levels follow their item: seen when it may be seen, changed (by the
 * ItemService, never directly) when the item's stage allows it.
 */
class ItemChildPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'item';
    }

    public function view(User $user, Model $model): bool
    {
        $item = $model->getRelationValue('item');

        return $item instanceof Model && Gate::forUser($user)->allows('view', $item);
    }

    public function update(User $user, Model $model): bool
    {
        return false;   // edited only through the stage forms
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}

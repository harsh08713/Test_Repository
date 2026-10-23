<?php

namespace App\Modules\Maintenance\Policies;

use App\Models\User;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/** Assets: the matrix says who may make and change them. An asset is never deleted — it is made inactive. */
class AssetPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'asset';
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}

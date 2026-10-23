<?php

namespace App\Modules\Integrations\Policies;

use App\Models\User;
use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Provider connections: seen with `integration.view`, changed with `integration.edit`, and run (test, pull now, retry, new
 * webhook address) with `integration.configure`. Nobody deletes one — it is switched off.
 */
class IntegrationConnectionPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'integration';
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    /** Test the connection, pull now, ask for a new webhook address. */
    public function configure(User $user, IntegrationConnection $connection): bool
    {
        return $user->can('integration.configure') && $this->withinTenant($user, $connection);
    }
}

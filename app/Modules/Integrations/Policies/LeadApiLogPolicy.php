<?php

namespace App\Modules\Integrations\Policies;

use App\Models\User;
use App\Modules\Integrations\Models\LeadApiLog;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/** The inbound-lead log (what each provider delivered, and what became of it): read with `integration.view`, retried with `integration.configure`. */
class LeadApiLogPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'integration';
    }

    public function create(User $user): bool
    {
        return false;   // written only by the intake service
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    /** Process a failed delivery again. */
    public function retry(User $user, LeadApiLog $log): bool
    {
        return $user->can('integration.configure') && $this->withinTenant($user, $log) && $log->status->value === 'failed';
    }
}

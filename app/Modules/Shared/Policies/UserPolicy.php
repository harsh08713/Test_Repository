<?php

namespace App\Modules\Shared\Policies;

use App\Models\User;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * User administration. Besides the `user.*` permissions, a non-super-admin may only manage
 * users who share one of their companies, and never a Super Admin (privilege-escalation guard).
 */
class UserPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'user';
    }

    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) && $this->manages($user, $model);
    }

    public function update(User $user, Model $model): bool
    {
        return parent::update($user, $model) && $this->manages($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model) && $model->isNot($user) && $this->manages($user, $model);
    }

    private function manages(User $actor, Model $target): bool
    {
        if (! $target instanceof User) {
            return false;
        }

        $tenant = app(TenantContext::class);

        if ($tenant->canAccessEverything($actor)) {
            return true;
        }

        // Only someone with cross-company reach may administer a Super Admin.
        if ($target->isSuperAdmin()) {
            return false;
        }

        $companies = $tenant->accessibleCompanyIds($actor);

        // A user with no company yet (just created) can be completed by whoever created them.
        $targetCompanies = DB::table('company_user')->where('user_id', $target->getKey())->pluck('company_id');

        return $targetCompanies->isEmpty() || $targetCompanies->intersect($companies)->isNotEmpty();
    }
}

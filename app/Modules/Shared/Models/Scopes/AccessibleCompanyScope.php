<?php

namespace App\Modules\Shared\Models\Scopes;

use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * For the Company model itself: every company the user may access (not only the active one), so they can switch.
 *
 * @implements Scope<Model>
 */
final class AccessibleCompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class);

        if ($tenant->isUnrestricted()) {
            return;
        }

        $builder->whereIn($model->qualifyColumn($model->getKeyName()), $tenant->accessibleCompanyIds());
    }
}

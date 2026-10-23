<?php

namespace App\Modules\Shared\Models\Scopes;

use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts a model with a `company_id` column to the user's active company.
 *
 * @implements Scope<Model>
 */
final class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class);

        if ($tenant->isUnrestricted()) {
            return;
        }

        // 0 matches no row: with no resolvable company the scope fails closed.
        $builder->where($model->qualifyColumn('company_id'), $tenant->activeCompanyId() ?? 0);
    }
}

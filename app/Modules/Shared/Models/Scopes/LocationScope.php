<?php

namespace App\Modules\Shared\Models\Scopes;

use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts rows to the branches the user is assigned to.
 *
 * Transactional models use the default `location_id` column and may also carry a NULL branch
 * (a company-wide record). The Location model itself filters on its own `id`.
 *
 * @implements Scope<Model>
 */
final class LocationScope implements Scope
{
    public function __construct(
        private readonly string $column = 'location_id',
        private readonly bool $allowNull = true,
    ) {}

    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class);

        if ($tenant->isUnrestricted()) {
            return;
        }

        $allowed = $tenant->accessibleLocationIds();

        if ($allowed === null) {
            return; // all branches of the company
        }

        $column = $model->qualifyColumn($this->column);

        $builder->where(function (Builder $query) use ($column, $allowed) {
            $query->whereIn($column, $allowed);

            if ($this->allowNull) {
                $query->orWhereNull($column);
            }
        });
    }
}

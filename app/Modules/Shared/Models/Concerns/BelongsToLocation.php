<?php

namespace App\Modules\Shared\Models\Concerns;

use App\Modules\Shared\Exceptions\TenantAccessException;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\Scopes\LocationScope;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Branch ownership. Use together with BelongsToCompany.
 * Reads are limited to the user's branches; writes must target a branch of the record's own
 * company that the user may access.
 *
 * @mixin Model
 */
trait BelongsToLocation
{
    public static function bootBelongsToLocation(): void
    {
        static::addGlobalScope(new LocationScope);

        static::saving(function (Model $model) {
            $tenant = app(TenantContext::class);

            if (! $model->exists && $model->getAttribute('location_id') === null) {
                $model->setAttribute('location_id', $tenant->activeLocationId());
            }

            $locationId = $model->getAttribute('location_id');

            // Nothing to validate for company-wide records or untouched branches.
            if ($locationId === null || ($model->exists && ! $model->isDirty('location_id'))) {
                return;
            }

            $locationId = (int) $locationId;
            $companyId = $model->getAttribute('company_id') ?? $tenant->activeCompanyId();

            $branchCompany = Location::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($locationId)->value('company_id');

            if ($branchCompany === null || $companyId === null || (int) $branchCompany !== (int) $companyId) {
                throw TenantAccessException::mismatch('The branch does not belong to the same company as this record.');
            }

            if (! $tenant->isUnrestricted() && ! $tenant->canAccessLocation($locationId)) {
                throw TenantAccessException::location($locationId);
            }
        });
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}

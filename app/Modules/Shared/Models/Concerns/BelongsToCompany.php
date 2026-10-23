<?php

namespace App\Modules\Shared\Models\Concerns;

use App\Modules\Shared\Exceptions\TenantAccessException;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Scopes\CompanyScope;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant ownership by company.
 *  - reads: global scope limits every query to the user's active company
 *  - writes: company_id is filled from the active company, and a save into a company the
 *    user cannot access (or a move of an existing record to another company) is refused,
 *    so a tampered request can never choose the company.
 *
 * @mixin Model
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        // `saving` fires before `creating`, so defaulting and guarding both live here.
        static::saving(function (Model $model) {
            $tenant = app(TenantContext::class);

            if (! $model->exists && $model->getAttribute('company_id') === null) {
                $model->setAttribute('company_id', $tenant->activeCompanyId());
            }

            if ($tenant->isUnrestricted()) {
                return;
            }

            $companyId = $model->getAttribute('company_id');

            if ($companyId === null || ! $tenant->canAccessCompany((int) $companyId)) {
                throw TenantAccessException::company($companyId === null ? null : (int) $companyId);
            }

            if ($model->exists && $model->isDirty('company_id')) {
                throw TenantAccessException::mismatch('A record cannot be moved to another company.');
            }
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

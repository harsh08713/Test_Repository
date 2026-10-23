<?php

namespace App\Modules\Shared\Jobs\Middleware;

use App\Modules\Shared\Exceptions\TenantAccessException;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Closure;

/**
 * Job middleware that runs the job strictly inside the company (and optionally branch) it was queued for.
 *
 * A queue worker has no signed-in user, so without this a job would see every company's data. With it, the
 * global scopes are restricted to the job's company, and the job fails outright if that company (or branch)
 * no longer exists or the branch belongs to another company. Use through RunsInCompanyContext.
 */
final class InCompanyContext
{
    public function handle(object $job, Closure $next): mixed
    {
        $companyId = $job->companyId ?? null;
        $locationId = $job->locationId ?? null;

        if (! is_int($companyId)) {
            throw new TenantAccessException('A queued job must declare the company it runs for.');
        }

        if (! Company::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($companyId)->exists()) {
            throw TenantAccessException::company($companyId);
        }

        if ($locationId !== null && ! Location::withoutGlobalScopes(Tenancy::SCOPES)
            ->whereKey($locationId)->where('company_id', $companyId)->exists()) {
            throw TenantAccessException::location($locationId);
        }

        return app(TenantContext::class)->withCompany($companyId, fn () => $next($job));
    }
}

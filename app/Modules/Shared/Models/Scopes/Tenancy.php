<?php

namespace App\Modules\Shared\Models\Scopes;

/**
 * The tenant-isolation scopes, as a list for Model::withoutGlobalScopes(Tenancy::SCOPES).
 *
 * Use this instead of a bare withoutGlobalScopes() whenever system code must look across
 * companies/branches: a bare call would also drop the SoftDeletes scope and quietly
 * resurrect deleted branches, companies and records.
 */
final class Tenancy
{
    public const SCOPES = [
        CompanyScope::class,
        AccessibleCompanyScope::class,
        LocationScope::class,
    ];
}

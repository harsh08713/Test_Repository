<?php

namespace App\Modules\Masters\Http\Controllers;

use App\Modules\Shared\Models\Company;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a company logo from private storage. Authorised per request: the company must be one the user can
 * access (route binding is tenant-scoped) and they must be allowed to view companies.
 */
class CompanyLogoController
{
    public function __invoke(Company $company): StreamedResponse
    {
        Gate::authorize('view', $company);

        abort_unless($company->logo_path && Storage::disk('local')->exists($company->logo_path), 404);

        return Storage::disk('local')->response($company->logo_path, null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

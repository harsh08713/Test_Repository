<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Header switcher: change the active company and/or default branch. Access is validated server-side. */
class ContextController
{
    public function __invoke(Request $request, TenantContext $tenant): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['company_id']) && (int) $data['company_id'] !== $tenant->activeCompanyId()) {
            $tenant->switchCompany((int) $data['company_id']);   // 403 via TenantAccessException when not allowed
        }

        if ($request->has('location_id')) {
            $tenant->switchLocation(empty($data['location_id']) ? null : (int) $data['location_id']);
        }

        return back();
    }
}

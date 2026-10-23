<?php

namespace App\Modules\Shared\View\Components;

use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\View\Component;
use Illuminate\View\View;

/** Header control to switch the active company and the default branch for new documents. */
class ContextSwitcher extends Component
{
    public function render(): View
    {
        $tenant = app(TenantContext::class);

        return view('shared.context-switcher', [
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'locations' => Location::query()->orderBy('name')->get(['id', 'name', 'code']),
            'activeCompanyId' => $tenant->activeCompanyId(),
            'activeLocationId' => $tenant->activeLocationId(),
        ]);
    }
}

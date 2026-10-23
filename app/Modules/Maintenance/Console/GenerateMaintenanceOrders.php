<?php

namespace App\Modules\Maintenance\Console;

use App\Modules\Maintenance\Services\MaintenanceOrderService;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Makes the preventive work orders that have come due (once for each due date, never while the schedule has one open). Safe to run twice at once: a unique key stops a second one. */
class GenerateMaintenanceOrders extends Command
{
    protected $signature = 'maintenance:generate-orders';

    protected $description = 'Make the preventive maintenance work orders that have come due';

    public function handle(MaintenanceOrderService $service, TenantContext $tenant): int
    {
        $made = 0;

        foreach (Company::withoutGlobalScopes(Tenancy::SCOPES)->pluck('id') as $companyId) {
            $made += $tenant->withCompany((int) $companyId, fn () => $service->generateDue());
        }

        $this->info('Work orders made: '.$made);

        return self::SUCCESS;
    }
}

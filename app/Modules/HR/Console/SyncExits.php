<?php

namespace App\Modules\HR\Console;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * The last working day has come for everyone on notice: they are marked as having left and their login is switched off. Idempotent (finaliseExit leaves
 * someone who has already left alone), so the scheduler, a retry and a person running it by hand cannot do any of it twice.
 */
class SyncExits extends Command
{
    protected $signature = 'hr:sync-exits';

    protected $description = 'Mark employees whose last working day has passed as having left, and switch their logins off';

    public function handle(EmployeeService $service, TenantContext $tenant): int
    {
        $done = 0;

        foreach (Company::withoutGlobalScopes(Tenancy::SCOPES)->pluck('id') as $companyId) {
            $done += $tenant->withCompany((int) $companyId, function () use ($service) {
                $count = 0;

                foreach (Employee::query()->where('employment_status', 'notice')->whereDate('exit_date', '<=', now()->toDateString())->get() as $employee) {
                    $service->finaliseExit($employee);
                    $count++;
                }

                return $count;
            });
        }

        $this->info('Employees marked as having left: '.$done);

        return self::SUCCESS;
    }
}

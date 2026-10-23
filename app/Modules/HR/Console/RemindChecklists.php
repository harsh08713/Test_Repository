<?php

namespace App\Modules\HR\Console;

use App\Modules\HR\Services\ChecklistReminders;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Reminds people of late onboarding and exit lines (once a week per line). Safe to run twice at once. */
class RemindChecklists extends Command
{
    protected $signature = 'hr:checklist-reminders';

    protected $description = 'Remind people of onboarding and exit checklist items that are late';

    public function handle(ChecklistReminders $reminders, TenantContext $tenant): int
    {
        $count = 0;

        foreach (Company::withoutGlobalScopes(Tenancy::SCOPES)->pluck('id') as $companyId) {
            $count += $tenant->withCompany((int) $companyId, fn () => $reminders->run());
        }

        $this->info('Late items reminded: '.$count);

        return self::SUCCESS;
    }
}

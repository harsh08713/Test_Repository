<?php

namespace App\Modules\Integrations\Console;

use App\Modules\Integrations\Models\LeadApiLog;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * Removes inbound-lead log rows older than the company's retention (`integrations.log_retention_days`, 90 by default) — the
 * stored payloads hold buyers' phone numbers and e-mail addresses, so they are not kept for ever. Leads that were made from them stay.
 * A row that failed is kept for twice as long, so a problem noticed late can still be retried.
 */
class PruneLeadLogs extends Command
{
    protected $signature = 'integrations:prune';

    protected $description = 'Delete inbound-lead log rows past their retention period';

    public function handle(SettingsService $settings): int
    {
        $removed = 0;

        foreach (Company::withoutGlobalScopes(Tenancy::SCOPES)->pluck('id') as $companyId) {
            $days = max(7, (int) $settings->get('integrations.log_retention_days', (int) $companyId));

            $removed += LeadApiLog::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)
                ->where(fn ($q) => $q->where(fn ($w) => $w->where('status', '!=', 'failed')->where('created_at', '<', now()->subDays($days)))
                    ->orWhere(fn ($w) => $w->where('status', 'failed')->where('created_at', '<', now()->subDays($days * 2))))
                ->delete();
        }

        $this->components->info("Removed {$removed} log row(s).");

        return self::SUCCESS;
    }
}

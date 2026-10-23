<?php

namespace App\Modules\Integrations\Console;

use App\Modules\Integrations\Jobs\ProcessLeadApiLog;
use App\Modules\Integrations\Models\LeadApiLog;
use App\Modules\Integrations\Services\LeadIntake;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Console\Command;

/**
 * Picks up deliveries nobody finished: stored but never processed (the queue was down when they arrived), or claimed by a worker
 * that died. Deliveries marked `failed` are **not** touched — those wait for a person (the log screen's Retry), because trying
 * again blindly cannot fix what a person must fix.
 */
class RetryStuckLeads extends Command
{
    protected $signature = 'integrations:retry-stuck';

    protected $description = 'Queue deliveries that were stored but never processed';

    public function handle(): int
    {
        $stuck = LeadApiLog::withoutGlobalScopes(Tenancy::SCOPES)
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('status', 'received')->where('created_at', '<=', now()->subMinutes(5)))
                ->orWhere(fn ($w) => $w->where('status', 'processing')->where('updated_at', '<=', now()->subMinutes(LeadIntake::STALE_MINUTES))))
            ->orderBy('id')->limit(500)->get(['id', 'company_id']);

        foreach ($stuck as $log) {
            ProcessLeadApiLog::dispatch((int) $log->company_id, (int) $log->getKey());
        }

        $this->components->info("Queued {$stuck->count()} delivery(ies).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Modules\Integrations\Jobs;

use App\Modules\Integrations\Exceptions\IntakeProblem;
use App\Modules\Integrations\Models\LeadApiLog;
use App\Modules\Integrations\Services\LeadIntake;
use App\Modules\Shared\Jobs\Concerns\RunsInCompanyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Turns one stored delivery into a lead (or a note on one), inside its company. A trouble that only a person can fix
 * (no branch set, the person the leads are entered as has left) is not retried — the delivery stays in the log as failed, with the
 * reason; anything else — a deadlock, a lock that timed out — is retried with a growing wait, five times, and then also stays failed.
 */
class ProcessLeadApiLog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInCompanyContext;

    public int $tries = 5;

    public function __construct(int $companyId, public readonly int $logId)
    {
        $this->forCompany($companyId);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(LeadIntake $intake): void
    {
        $log = LeadApiLog::query()->find($this->logId);

        if ($log === null) {
            return;
        }

        try {
            $intake->process($log);
        } catch (IntakeProblem) {
            // recorded on the delivery; retrying cannot help until someone acts
        }
    }
}

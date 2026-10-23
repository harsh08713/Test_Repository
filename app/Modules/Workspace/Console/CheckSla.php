<?php

namespace App\Modules\Workspace\Console;

use App\Modules\Shared\Services\Heartbeat;
use App\Modules\Workspace\Services\SlaSweep;
use Illuminate\Console\Command;

/** One SLA pass for every company: start clocks, settle them from the facts, and notice the ones that ran out. Safe to run as often as you like. */
class CheckSla extends Command
{
    protected $signature = 'sla:check';

    protected $description = 'Start, settle and check SLA clocks (first response, follow-ups, tasks); escalate breaches once';

    public function handle(SlaSweep $sweep): int
    {
        $result = $sweep->run();
        app(Heartbeat::class)->beat('sla:check');

        $this->info("SLA clocks started: {$result['started']}; settled: {$result['settled']}; breaches recorded: {$result['breached']}");

        return self::SUCCESS;
    }
}

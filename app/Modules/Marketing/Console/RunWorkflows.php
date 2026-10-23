<?php

namespace App\Modules\Marketing\Console;

use App\Modules\Marketing\Services\WorkflowEngine;
use App\Modules\Shared\Services\Heartbeat;
use Illuminate\Console\Command;

/** Runs the workflows that are on for whatever has happened since the last sweep. Safe to run as often as you like: an event runs a workflow once. */
class RunWorkflows extends Command
{
    protected $signature = 'automation:run';

    protected $description = 'Run the active workflows for new occurrences of their triggers';

    public function handle(WorkflowEngine $engine): int
    {
        $r = $engine->run();
        app(Heartbeat::class)->beat('automation:run');

        $this->info("Workflow runs — done: {$r['ok']}; failed: {$r['failed']}; skipped (conditions or limits): {$r['skipped']}; recorded only (dry run): {$r['dry']}; held for the hourly limit: {$r['limited']}");

        return self::SUCCESS;
    }
}

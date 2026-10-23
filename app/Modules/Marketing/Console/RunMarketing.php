<?php

namespace App\Modules\Marketing\Console;

use App\Modules\Marketing\Services\CampaignRunner;
use App\Modules\Marketing\Services\CommunicationService;
use App\Modules\Shared\Services\Heartbeat;
use Illuminate\Console\Command;

/** Sends what the running campaigns have due, and tries messages that failed again. Safe to run as often as you like: every message has a key. */
class RunMarketing extends Command
{
    protected $signature = 'marketing:run';

    protected $description = 'Send the due steps of running campaigns and retry failed messages';

    public function handle(CampaignRunner $runner, CommunicationService $messages): int
    {
        $result = $runner->run();
        $retried = $messages->retryFailed();
        app(Heartbeat::class)->beat('marketing:run');

        $this->info("Campaign messages made: {$result['sent']}; held back: {$result['held']}; left the sequence: {$result['exited']}; campaigns finished: {$result['completed']}; failed messages tried again: {$retried}");

        return self::SUCCESS;
    }
}

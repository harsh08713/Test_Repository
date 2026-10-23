<?php

namespace App\Modules\Reports\Console;

use App\Modules\Reports\Services\ReportScheduler;
use Illuminate\Console\Command;

/** Makes and sends the scheduled reports that are due. Safe to run twice at once: each schedule is taken with a conditional update. */
class SendScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled';

    protected $description = 'Make and send the scheduled reports that are due';

    public function handle(ReportScheduler $scheduler): int
    {
        $this->info('Scheduled reports started: '.$scheduler->runDue());

        return self::SUCCESS;
    }
}

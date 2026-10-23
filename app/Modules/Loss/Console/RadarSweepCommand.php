<?php

namespace App\Modules\Loss\Console;

use App\Modules\Loss\Radar\RadarService;
use Illuminate\Console\Command;

/** One round of the Profit Leakage Radar for every company that has it on: proposes findings, creates no incident (see RadarService::sweep). */
class RadarSweepCommand extends Command
{
    protected $signature = 'radar:sweep';

    protected $description = 'Look for losses in stock, receipts and scrap and propose findings for review';

    public function handle(RadarService $radar): int
    {
        $r = $radar->sweep();
        $this->info("Companies swept: {$r['companies']}; new findings: {$r['new']}; refreshed: {$r['refreshed']}; no longer applying: {$r['resolved']}");

        return self::SUCCESS;
    }
}

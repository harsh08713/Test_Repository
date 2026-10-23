<?php

namespace App\Modules\Analytics\Console;

use App\Modules\Analytics\Services\KpiSnapshotService;
use Illuminate\Console\Command;

/** Takes today's snapshot of every measurable industry KPI (see KpiSnapshotService). Safe to run again: it rewrites the same rows. */
class SnapshotCommand extends Command
{
    protected $signature = 'analytics:snapshots';

    protected $description = 'Take the day’s industry KPI snapshots';

    public function handle(KpiSnapshotService $snapshots): int
    {
        $this->info('KPI snapshots written: '.$snapshots->run());

        return self::SUCCESS;
    }
}

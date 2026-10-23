<?php

namespace App\Modules\Reports\Console;

use App\Modules\Reports\Services\ReportExporter;
use Illuminate\Console\Command;

/** Deletes the files of exports that have passed their time; the rows stay as a record. */
class ExpireReportExports extends Command
{
    protected $signature = 'reports:expire-exports';

    protected $description = 'Delete report export files that are past their keep-until date';

    public function handle(ReportExporter $exporter): int
    {
        $this->info('Expired '.$exporter->expire().' export file(s).');

        return self::SUCCESS;
    }
}

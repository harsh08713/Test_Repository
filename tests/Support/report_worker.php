<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and, at an agreed instant, runs the scheduled reports that are due — so several workers
 * ask for the same schedule at the same moment.
 *
 *   php tests/Support/report_worker.php run <offsetSeconds> <startAt>
 *
 * `offsetSeconds` is added to the clock the worker passes to the scheduler (each worker has its own time, so that a claim that merely writes the same value again could not pass).
 * Prints "OK <schedules started>" or "ERROR: <class>: <message>".
 */

use App\Modules\Reports\Services\ReportScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$startAt = (float) array_pop($args);
$offset = (int) ($args[1] ?? 0);

try {
    DB::select('select 1');

    while (microtime(true) < $startAt) {
        usleep(200);
    }

    $started = app(ReportScheduler::class)->runDue(CarbonImmutable::now()->addSeconds($offset));
    echo 'OK '.$started;
} catch (Throwable $e) {
    echo 'ERROR: '.$e::class.': '.$e->getMessage();
}

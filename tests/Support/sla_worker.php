<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and, at an agreed instant, runs one SLA sweep or one round of task
 * reminders — so several workers hit the same clocks and tasks at the same moment.
 *
 *   php tests/Support/sla_worker.php sla    <startAt>
 *   php tests/Support/sla_worker.php remind <startAt>
 *
 * Prints one line: "OK started=<n> settled=<n> breached=<n>" for a sweep, "OK <command output>" for reminders, or "ERROR: <class>: <message>".
 */

use App\Modules\Workspace\Services\SlaSweep;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$startAt = (float) ($argv[2] ?? 0);

try {
    // Connect first, so that what follows the wait is the work itself and not the handshake.
    DB::select('select 1');

    while (microtime(true) < $startAt) {
        usleep(200);
    }

    if ($mode === 'sla') {
        // each process has its own clock a few seconds apart: a claim that only looks like one (setting a time that is already there) cannot hide
        $result = app(SlaSweep::class)->run(now()->addSeconds(random_int(1, 40)));

        echo "OK started={$result['started']} settled={$result['settled']} breached={$result['breached']}", PHP_EOL;
    } elseif ($mode === 'remind') {
        Artisan::call('tasks:remind');

        echo 'OK '.str_replace("\n", ' / ', trim(Artisan::output())), PHP_EOL;
    } else {
        throw new InvalidArgumentException("Unknown mode [{$mode}].");
    }
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage(), PHP_EOL;
    exit(1);
}

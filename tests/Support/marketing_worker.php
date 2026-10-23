<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and, at an agreed instant, runs one round of the campaign runner or of the
 * workflow engine — so several workers hit the same members and events at the same moment.
 *
 *   php tests/Support/marketing_worker.php campaign <startAt>
 *   php tests/Support/marketing_worker.php workflow <startAt>
 *
 * Prints one line: "OK sent=<n> …" or "ERROR: <class>: <message>".
 */

use App\Modules\Marketing\Services\CampaignRunner;
use App\Modules\Marketing\Services\WorkflowEngine;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$startAt = (float) ($argv[2] ?? 0);

try {
    DB::select('select 1');

    while (microtime(true) < $startAt) {
        usleep(200);
    }

    if ($mode === 'campaign') {
        $r = app(CampaignRunner::class)->run();

        echo "OK sent={$r['sent']} exited={$r['exited']} held={$r['held']}", PHP_EOL;
    } elseif ($mode === 'workflow') {
        $r = app(WorkflowEngine::class)->run();

        echo "OK ok={$r['ok']} failed={$r['failed']} skipped={$r['skipped']}", PHP_EOL;
    } else {
        throw new InvalidArgumentException("Unknown mode [{$mode}].");
    }
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage(), PHP_EOL;
    exit(1);
}

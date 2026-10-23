<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and submits one rate for approval at an
 * agreed instant, so several workers hit the overlap check at the same moment.
 *
 *   php tests/Support/submit_rate.php <rateId> <userId> <companyId> <startAt>
 *
 * Prints "OK" when the rate was submitted, or "ERROR: <class>: <message>" and exits 1 when it was refused.
 */

use App\Models\User;
use App\Modules\Masters\Models\Rate;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $rateId, $userId, $companyId, $startAt] = $argv + [null, null, null, null, null];

try {
    // Everything is loaded and warm; now wait for the shared start time so the submits really collide.
    while (microtime(true) < (float) $startAt) {
        usleep(200);
    }

    app(TenantContext::class)->withCompany((int) $companyId, function () use ($rateId, $userId) {
        $rate = Rate::query()->findOrFail((int) $rateId);
        app(ApprovalService::class)->submit($rate, User::query()->findOrFail((int) $userId));
    });

    echo 'OK', PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage(), PHP_EOL;
    exit(1);
}

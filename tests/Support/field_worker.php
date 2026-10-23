<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and, at an agreed instant, either sets
 * a sales target or marks attendance — so several workers hit the same rule at the same moment.
 *
 *   php tests/Support/field_worker.php target <companyId> <actorId> <userId> <periodType> <periodStart> <startAt>
 *   php tests/Support/field_worker.php attendance <companyId> <userId> <latitude> <longitude> <startAt>
 *
 * Prints one line: "OK", "REFUSED: <why>" when a rule said no, or "ERROR: <class>: <message>" for anything unexpected.
 */

use App\Models\User;
use App\Modules\CRM\Exceptions\AttendanceRefused;
use App\Modules\CRM\Services\AttendanceService;
use App\Modules\CRM\Services\TargetService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$args = array_slice($argv, 2);
$startAt = (float) array_pop($args);

try {
    // Everything is loaded and warm; now wait for the shared start time so the calls really collide.
    while (microtime(true) < $startAt) {
        usleep(200);
    }

    if ($mode === 'target') {
        [$companyId, $actorId, $userId, $periodType, $periodStart] = $args;

        app(TenantContext::class)->withCompany((int) $companyId, fn () => app(TargetService::class)->save(null, ['target' => [
            'subject' => 'user', 'user_id' => $userId, 'period_type' => $periodType, 'period_start' => $periodStart,
            'target_value' => '1000', 'status' => 'active',
        ]], User::query()->findOrFail((int) $actorId)));
    } elseif ($mode === 'attendance') {
        [$companyId, $userId, $latitude, $longitude] = $args;

        app(TenantContext::class)->withCompany((int) $companyId, fn () => app(AttendanceService::class)->mark(User::query()->findOrFail((int) $userId), $latitude, $longitude, 10, 'mobile'));
    } else {
        throw new InvalidArgumentException("Unknown mode [{$mode}].");
    }

    echo 'OK', PHP_EOL;
} catch (ValidationException $e) {
    echo 'REFUSED: '.collect($e->errors())->flatten()->first(), PHP_EOL;
} catch (AttendanceRefused $e) {
    echo 'REFUSED: '.($e->reason === null ? 'already marked' : $e->reason->value), PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage(), PHP_EOL;
    exit(1);
}

<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and, at an agreed instant, starts an inspection or decides a failed one — so several workers hit the
 * same batch or the same inspection at the same moment.
 *
 *   php tests/Support/quality_worker.php start  <companyId> <actorId> <planId> <locationId> <batchId> <startAt>
 *   php tests/Support/quality_worker.php reject <companyId> <actorId> <inspectionId> <startAt>
 *
 * Prints one line: "OK", "REFUSED: <why>" when a rule said no, or "ERROR: <class>: <message>" for anything unexpected.
 */

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$args = array_slice($argv, 2);
$startAt = (float) array_pop($args);

try {
    DB::select('select 1');

    while (microtime(true) < $startAt) {
        usleep(200);
    }

    $companyId = (int) ($args[0] ?? 0);
    $actor = User::query()->findOrFail((int) ($args[1] ?? 0));

    app(TenantContext::class)->withCompany($companyId, function () use ($mode, $args, $actor) {
        match ($mode) {
            'start' => app(InspectionService::class)->start(['inspection_plan_id' => $args[2], 'location_id' => $args[3], 'batch_id' => $args[4], 'lot_quantity' => '100'], $actor),
            'reject' => app(InspectionService::class)->decide(Inspection::query()->findOrFail((int) $args[2]), 'reject', 'Racing', $actor),
            default => throw new InvalidArgumentException("Unknown mode [{$mode}]."),
        };
    });

    echo 'OK', PHP_EOL;
} catch (ValidationException $e) {
    echo 'REFUSED: '.collect($e->errors())->flatten()->first(), PHP_EOL;
} catch (FieldRuleException $e) {
    echo 'REFUSED: '.$e->getMessage(), PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage(), PHP_EOL;
    exit(1);
}

<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and, at an agreed instant, generates the due preventive work orders or issues a spare part to a work order —
 * so several workers hit the same schedule or the same order at the same moment.
 *
 *   php tests/Support/maintenance_worker.php generate <companyId> <startAt>
 *   php tests/Support/maintenance_worker.php part     <companyId> <actorId> <orderId> <itemId> <quantity> <entryKey> <startAt>
 *
 * Prints one line: "OK <what>", "REFUSED: <why>" when a rule said no, or "ERROR: <class>: <message>" for anything unexpected.
 */

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Maintenance\Models\MaintenanceOrder;
use App\Modules\Maintenance\Services\MaintenanceOrderService;
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

    $result = app(TenantContext::class)->withCompany($companyId, function () use ($mode, $args) {
        return match ($mode) {
            'generate' => 'made '.app(MaintenanceOrderService::class)->generateDue(),
            'part' => (function () use ($args) {
                app(MaintenanceOrderService::class)->issuePart(MaintenanceOrder::query()->findOrFail((int) $args[2]), (int) $args[3], null, $args[4], $args[5], User::query()->findOrFail((int) $args[1]));

                return 'issued';
            })(),
            default => throw new InvalidArgumentException("Unknown mode [{$mode}]."),
        };
    });

    echo 'OK '.$result, PHP_EOL;
} catch (ValidationException $e) {
    echo 'REFUSED: '.collect($e->errors())->flatten()->first(), PHP_EOL;
} catch (FieldRuleException|StockException $e) {
    echo 'REFUSED: '.$e->getMessage(), PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage(), PHP_EOL;
    exit(1);
}

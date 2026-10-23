<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and, at an agreed instant, issues material to a production order — so several workers hit the same
 * order at the same moment.
 *
 *   php tests/Support/production_worker.php issue <companyId> <actorId> <orderId> <componentId> <quantity> <entryKey> <startAt>
 *
 * Prints one line: "OK", "REFUSED: <why>" when a rule said no, or "ERROR: <class>: <message>" for anything unexpected.
 */

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\ProductionOrderService;
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

    if ($mode !== 'issue') {
        throw new InvalidArgumentException("Unknown mode [{$mode}].");
    }

    [$companyId, $actorId, $orderId, $componentId, $quantity, $key] = $args;

    app(TenantContext::class)->withCompany((int) $companyId, function () use ($actorId, $orderId, $componentId, $quantity, $key) {
        app(ProductionOrderService::class)->issue(ProductionOrder::query()->findOrFail((int) $orderId), (int) $componentId, null, $quantity, $key, User::query()->findOrFail((int) $actorId));
    });

    echo 'OK', PHP_EOL;
} catch (ValidationException $e) {
    echo 'REFUSED: '.collect($e->errors())->flatten()->first(), PHP_EOL;
} catch (FieldRuleException|StockException $e) {
    echo 'REFUSED: '.$e->getMessage(), PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage(), PHP_EOL;
    exit(1);
}

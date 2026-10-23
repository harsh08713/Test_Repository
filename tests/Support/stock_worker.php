<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and, at an agreed instant, either posts an approved goods receipt to
 * stock or takes stock out of a branch — so several workers hit the same rule at the same moment.
 *
 *   php tests/Support/stock_worker.php post  <companyId> <grnId> <startAt>
 *   php tests/Support/stock_worker.php take  <companyId> <actorId> <itemId> <locationId> <quantity> <startAt>
 *
 * Prints one line: "OK <what>", "REFUSED: <why>" when a rule said no, or "ERROR: <class>: <message>" for anything unexpected.
 */

use App\Models\User;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Masters\Models\Item;
use App\Modules\Procurement\Services\GrnPoster;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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

    if ($mode === 'post') {
        [$companyId, $grnId] = $args;
        $posted = app(TenantContext::class)->withCompany((int) $companyId, fn () => app(GrnPoster::class)->post((int) $grnId));

        echo 'OK '.($posted ? 'posted' : 'nothing to do'), PHP_EOL;
    } elseif ($mode === 'take') {
        [$companyId, $actorId, $itemId, $locationId, $quantity] = $args;

        $movement = app(TenantContext::class)->withCompany((int) $companyId, function () use ($actorId, $itemId, $locationId, $quantity) {
            $item = Item::withoutGlobalScopes(Tenancy::SCOPES)->findOrFail((int) $itemId);

            return app(StockService::class)->adjustOut($item, (int) $locationId, $quantity, null, 'Racing count difference', User::query()->findOrFail((int) $actorId));
        });

        echo 'OK '.$movement->value, PHP_EOL;
    } else {
        throw new InvalidArgumentException("Unknown mode [{$mode}].");
    }
} catch (StockException $e) {
    echo 'REFUSED: '.$e->getMessage(), PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage(), PHP_EOL;
    exit(1);
}

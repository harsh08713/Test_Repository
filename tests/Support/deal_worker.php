<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and, at an agreed instant, converts one lead into a
 * deal — so several workers hit the same rule at the same moment.
 *
 *   php tests/Support/deal_worker.php convert <companyId> <actorId> <leadId> <itemId> <startAt>
 *
 * Prints one line: "OK <deal number>", "REFUSED: <why>" when a rule said no, or "ERROR: <class>: <message>" for anything unexpected.
 */

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\DealService;
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
    // Connect first, so that what follows the wait is the work itself and not the handshake.
    DB::select('select 1');

    // Everything is loaded and warm; now wait for the shared start time so the calls really collide.
    while (microtime(true) < $startAt) {
        usleep(200);
    }

    if ($mode !== 'convert') {
        throw new InvalidArgumentException("Unknown mode [{$mode}].");
    }

    [$companyId, $actorId, $leadId, $itemId] = $args;

    $deal = app(TenantContext::class)->withCompany((int) $companyId, function () use ($actorId, $leadId, $itemId) {
        $lead = Lead::query()->findOrFail((int) $leadId);

        return app(DealService::class)->convert($lead, [
            'deal' => ['customer_ledger_id' => '', 'expected_close_date' => now()->addDays(30)->toDateString(), 'probability' => '', 'remarks' => ''],
            'items' => [['id' => '', 'lead_interest_id' => '', 'item_id' => $itemId, 'unit_id' => '', 'quantity' => '5', 'expected_rate' => '10']],
        ], User::query()->findOrFail((int) $actorId));
    });

    echo 'OK '.$deal->deal_number, PHP_EOL;
} catch (ValidationException $e) {
    echo 'REFUSED: '.collect($e->errors())->flatten()->first(), PHP_EOL;
} catch (FieldRuleException $e) {
    echo 'REFUSED: '.$e->getMessage(), PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage(), PHP_EOL;
    exit(1);
}

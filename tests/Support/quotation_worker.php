<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and, at an agreed instant, revises one quotation — so
 * several workers hit the same rule at the same moment.
 *
 *   php tests/Support/quotation_worker.php revise <companyId> <actorId> <quotationId> <startAt>
 *
 * Prints one line: "OK <revision number>", "REFUSED: <why>" when a rule said no, or "ERROR: <class>: <message>" for anything unexpected.
 */

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Services\QuotationService;
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

    if ($mode !== 'revise') {
        throw new InvalidArgumentException("Unknown mode [{$mode}].");
    }

    [$companyId, $actorId, $quotationId] = $args;

    $made = app(TenantContext::class)->withCompany((int) $companyId, function () use ($actorId, $quotationId) {
        $quotation = Quotation::query()->findOrFail((int) $quotationId);

        return app(QuotationService::class)->revise($quotation, User::query()->findOrFail((int) $actorId), 'race');
    });

    echo 'OK '.$made->revision_no, PHP_EOL;
} catch (ValidationException $e) {
    echo 'REFUSED: '.collect($e->errors())->flatten()->first(), PHP_EOL;
} catch (FieldRuleException $e) {
    echo 'REFUSED: '.$e->getMessage(), PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage(), PHP_EOL;
    exit(1);
}

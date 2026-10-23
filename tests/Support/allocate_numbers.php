<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and issues document numbers.
 *
 *   php tests/Support/allocate_numbers.php <locationId> <count> <documentType> [nested] [startAt]
 *
 * With "nested", every number is issued inside an outer transaction — the way a document is really saved: the number
 * and the document in one transaction, with nobody to retry it if the database picks it as a deadlock victim.
 * With a start time (microtime) the worker waits for that instant, so several workers issue their first number together.
 *
 * Prints one number per line, or "ERROR: <message>" and exits 1 if allocation failed.
 */

use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Services\NumberSeriesService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $locationId, $count, $type, $mode, $startAt] = $argv + [null, null, null, null, '', '0'];

try {
    $numbers = app(NumberSeriesService::class);
    $date = CarbonImmutable::create(2026, 10, 12);

    while (microtime(true) < (float) $startAt) {
        usleep(200);
    }

    for ($i = 0; $i < (int) $count; $i++) {
        echo $mode === 'nested'
            ? DB::transaction(fn () => $numbers->next(DocumentType::from($type), (int) $locationId, $date))
            : $numbers->next(DocumentType::from($type), (int) $locationId, $date), PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage(), PHP_EOL;
    exit(1);
}

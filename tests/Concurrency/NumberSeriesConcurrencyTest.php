<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Real concurrency: separate PHP processes, each with its own database connection, allocate numbers at the
 * same moment. These tests commit to the test database (no wrapping transaction, which would hide the
 * locking) and clean up after themselves.
 */

/**
 * Start $workers processes that each allocate $each numbers, then collect everything they printed. With $nested every
 * number is issued inside an outer transaction (as a document is saved), and all workers start at the same instant.
 */
function runAllocators(int $locationId, int $workers, int $each, string $type = 'quotation', bool $nested = false): array
{
    $startAt = $nested ? sprintf('%.6F', microtime(true) + 4.0 + $workers * 0.5) : '0';

    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => config('database.connections.mysql.host'),
        'DB_PORT' => (string) config('database.connections.mysql.port'),
        'DB_DATABASE' => config('database.connections.mysql.database'),
        'DB_USERNAME' => config('database.connections.mysql.username'),
        'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ]);

    $running = [];
    for ($i = 0; $i < $workers; $i++) {
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/allocate_numbers.php'), (string) $locationId, (string) $each, $type, $nested ? 'nested' : 'plain', $startAt],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $env,
        );

        $running[] = [$process, $pipes];
    }

    $numbers = [];
    $errors = [];

    foreach ($running as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        foreach (array_filter(explode("\n", str_replace("\r", '', trim($stdout)))) as $line) {
            str_starts_with($line, 'ERROR:') ? $errors[] = $line : $numbers[] = $line;
        }

        if (trim($stderr) !== '') {
            $errors[] = 'stderr: '.trim($stderr);
        }
    }

    return [$numbers, $errors];
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);

    $this->tag = Str::upper(Str::random(5));
    $this->company = makeCompany('CC'.$this->tag);
    $this->location = makeLocation($this->company, 'L'.$this->tag);
});

afterEach(function () {
    DB::table('number_series')->where('company_id', $this->company->id)->delete();
    DB::table('activity_log')->where('company_id', $this->company->id)->delete();
    DB::table('locations')->where('company_id', $this->company->id)->delete();
    DB::table('companies')->where('id', $this->company->id)->delete();
});

it('gives parallel processes unique, gap-free numbers, even when they race to create the series', function () {
    $workers = 6;
    $each = 20;

    // No series row exists yet: all six processes race to issue number 1 of a brand-new series.
    expect(DB::table('number_series')->where('company_id', $this->company->id)->count())->toBe(0);

    [$numbers, $errors] = runAllocators($this->location->id, $workers, $each);

    expect($errors)->toBe([])
        ->and($numbers)->toHaveCount($workers * $each)
        ->and(array_unique($numbers))->toHaveCount($workers * $each);

    $sequence = collect($numbers)->map(fn (string $n) => (int) Str::afterLast($n, '/'))->sort()->values()->all();
    expect($sequence)->toBe(range(1, $workers * $each));        // 1..120, nothing skipped, nothing repeated

    expect($numbers[0])->toStartWith('QT/L'.$this->tag.'/26-27/');
    expect((int) DB::table('number_series')->where('company_id', $this->company->id)->value('last_number'))->toBe($workers * $each)
        ->and(DB::table('number_series')->where('company_id', $this->company->id)->count())->toBe(1);
});

it('issues the first numbers of a series without a deadlock when each is issued inside its document’s own transaction', function () {
    // This is how numbers are really issued: inside the transaction that saves the document, where a deadlock cannot
    // be retried by the numbering — the database has already thrown the whole transaction away. Six processes race to
    // issue number 1 of a series that does not exist yet (the first documents of a branch, or of a financial year).
    foreach (['quotation', 'sales_order', 'lead'] as $type) {
        expect(DB::table('number_series')->where('company_id', $this->company->id)->where('document_type', $type)->count())->toBe(0);

        [$numbers, $errors] = runAllocators($this->location->id, 6, 3, $type, nested: true);

        $sequence = collect($numbers)->map(fn (string $n) => (int) Str::afterLast($n, '/'))->sort()->values()->all();

        expect($errors)->toBe([], "{$type}: ".implode(' | ', $errors))
            ->and($sequence)->toBe(range(1, 18))                    // nothing skipped, nothing repeated, nobody turned away
            ->and(DB::table('number_series')->where('company_id', $this->company->id)->where('document_type', $type)->count())->toBe(1);
    }
});

it('keeps independent counters independent under load', function () {
    [$quotations, $errorsA] = runAllocators($this->location->id, 3, 10, 'quotation');
    [$orders, $errorsB] = runAllocators($this->location->id, 3, 10, 'sales_order');

    expect([...$errorsA, ...$errorsB])->toBe([]);

    $quotationSeq = collect($quotations)->map(fn ($n) => (int) Str::afterLast($n, '/'))->sort()->values()->all();
    $orderSeq = collect($orders)->map(fn ($n) => (int) Str::afterLast($n, '/'))->sort()->values()->all();

    expect($quotationSeq)->toBe(range(1, 30))
        ->and($orderSeq)->toBe(range(1, 30))
        ->and($orders[0])->toStartWith('SO/');
});

it('continues the sequence after a restart of every worker', function () {
    runAllocators($this->location->id, 2, 5);

    [$more, $errors] = runAllocators($this->location->id, 2, 5);

    $sequence = collect($more)->map(fn ($n) => (int) Str::afterLast($n, '/'))->sort()->values()->all();
    expect($errors)->toBe([])
        ->and($sequence)->toBe(range(11, 20));
});

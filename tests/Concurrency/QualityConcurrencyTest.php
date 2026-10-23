<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Real concurrency for the two promises quality depends on, with separate PHP processes acting at the same instant:
 *
 *  - **One open inspection per batch**: six people start an inspection of the same batch together — one starts it, five are told it is already being inspected, and the batch is put on
 *    hold once.
 *  - **A failed inspection is decided once**: six people reject the same failed inspection together — one rejects it and raises the report, five are told it is no longer waiting for a
 *    decision, and there is exactly one non-conformance report.
 *
 * Commits to the test database (no wrapping transaction, which would hide the race) and cleans up after itself.
 */

/** @return list<string> */
function runQualityWorkers(string $mode, array $args, int $count): array
{
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => config('database.default'), 'DB_HOST' => config('database.connections.mysql.host'),
        'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_DATABASE' => config('database.connections.mysql.database'),
        'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    ]);

    $startAt = sprintf('%.6F', microtime(true) + 4.0 + $count * 0.5);
    $running = [];

    foreach (range(1, $count) as $n) {
        $running[] = [proc_open([PHP_BINARY, base_path('tests/Support/quality_worker.php'), $mode, ...$args, $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env), $pipes];
    }

    $lines = [];
    foreach ($running as [$process, $pipes]) {
        $stdout = trim(str_replace("\r", '', stream_get_contents($pipes[1])));
        $stderr = trim(stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $lines[] = $stdout !== '' ? $stdout : 'ERROR: no output'.($stderr !== '' ? " (stderr: {$stderr})" : '');
    }

    return $lines;
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);

    $this->tag = Str::upper(Str::random(5));
    $this->company = makeCompany('TQ'.$this->tag);
    $this->location = makeLocation($this->company, 'Q'.$this->tag);
    $this->actor = makeUser([], [$this->company]);
    $c = $this->company->id;
    $now = now();

    // The smallest world an inspection needs, written straight to the tables.
    $unit = DB::table('units')->insertGetId(['company_id' => $c, 'code' => 'U'.$this->tag, 'name' => 'Unit '.$this->tag, 'decimal_allowed' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $group = DB::table('item_groups')->insertGetId(['company_id' => $c, 'code' => 'G'.$this->tag, 'name' => 'Group '.$this->tag, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $this->itemId = DB::table('items')->insertGetId([
        'company_id' => $c, 'name' => 'Item '.$this->tag, 'item_group_id' => $group, 'base_unit_id' => $unit, 'hsn_code' => '7318', 'gst_rate' => 18, 'tracking' => 'batch',
        'approval_status' => 'approved', 'workflow_stage' => 'approved', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $this->batchId = DB::table('batches')->insertGetId(['company_id' => $c, 'item_id' => $this->itemId, 'batch_no' => 'B'.$this->tag, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $this->planId = DB::table('inspection_plans')->insertGetId([
        'company_id' => $c, 'code' => 'P'.$this->tag, 'name' => 'Plan', 'item_id' => $this->itemId, 'stage' => 'incoming', 'approval_status' => 'approved', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('inspection_plan_parameters')->insert(['company_id' => $c, 'inspection_plan_id' => $this->planId, 'line_no' => 1, 'name' => 'Size', 'kind' => 'measure', 'min_value' => 1, 'max_value' => 2, 'created_at' => $now, 'updated_at' => $now]);
});

afterEach(function () {
    $id = $this->company->id;

    foreach (['non_conformances', 'batch_holds', 'inspection_results', 'inspections', 'inspection_plan_parameters', 'inspection_plans', 'batches', 'items', 'item_groups', 'units', 'activity_log', 'number_series', 'company_user'] as $table) {
        DB::table($table)->where('company_id', $id)->delete();
    }

    DB::table('location_user')->where('user_id', $this->actor->id)->delete();
    User::query()->whereKey($this->actor->id)->update(['default_company_id' => null]);
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->where('id', $this->actor->id)->delete();
});

it('lets only one of several people start an inspection of the same batch at the same moment', function () {
    $lines = runQualityWorkers('start', [(string) $this->company->id, (string) $this->actor->id, (string) $this->planId, (string) $this->location->id, (string) $this->batchId], 6);

    $ok = collect($lines)->filter(fn ($l) => $l === 'OK')->count();
    $refused = collect($lines)->filter(fn ($l) => str_starts_with($l, 'REFUSED:') && str_contains($l, 'already has an inspection'))->count();

    expect($ok)->toBe(1, implode(' | ', $lines))->and($refused)->toBe(5, implode(' | ', $lines))
        ->and(DB::table('inspections')->where('batch_id', $this->batchId)->count())->toBe(1)
        ->and(DB::table('batch_holds')->where('batch_id', $this->batchId)->where('action', 'hold')->count())->toBe(1)
        ->and(DB::table('batches')->where('id', $this->batchId)->value('status'))->toBe('quarantine');
});

it('decides a failed inspection once however many people press reject at the same moment', function () {
    $now = now();
    $inspectionId = DB::table('inspections')->insertGetId([
        'company_id' => $this->company->id, 'location_id' => $this->location->id, 'inspection_number' => 'QI/'.$this->tag.'/1', 'inspection_plan_id' => $this->planId, 'item_id' => $this->itemId,
        'item_name' => 'Item', 'stage' => 'incoming', 'batch_id' => $this->batchId, 'held_batch' => true, 'lot_quantity' => 100, 'state' => 'failed', 'inspector_id' => $this->actor->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('inspection_results')->insert(['company_id' => $this->company->id, 'inspection_id' => $inspectionId, 'line_no' => 1, 'name' => 'Size', 'kind' => 'measure', 'min_value' => 1, 'max_value' => 2, 'measured_value' => 3, 'passed' => false, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('batches')->where('id', $this->batchId)->update(['status' => 'quarantine']);

    $lines = runQualityWorkers('reject', [(string) $this->company->id, (string) $this->actor->id, (string) $inspectionId], 6);

    $ok = collect($lines)->filter(fn ($l) => $l === 'OK')->count();
    $refused = collect($lines)->filter(fn ($l) => str_starts_with($l, 'REFUSED:') && str_contains($l, 'failed inspection waiting'))->count();

    expect($ok)->toBe(1, implode(' | ', $lines))->and($refused)->toBe(5, implode(' | ', $lines))
        ->and(DB::table('non_conformances')->where('inspection_id', $inspectionId)->count())->toBe(1)
        ->and(DB::table('inspections')->where('id', $inspectionId)->value('state'))->toBe('rejected');
});

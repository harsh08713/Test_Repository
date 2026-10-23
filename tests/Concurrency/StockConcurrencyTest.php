<?php

use App\Models\User;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Masters\Models\Item;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Real concurrency for the two promises stock depends on, with separate PHP processes (each with its own database connection) acting at the same
 * instant:
 *
 *  - An approved goods receipt is put into stock **once**: five processes posting it at once make one receipt movement. The receipt's row lock and
 *    `posted_at` decide who posts; the poster's look for an existing movement and `unique(company, source, line, type)` are the backstops.
 *  - Stock never goes below zero and never loses an update: five processes each taking 30 out of 100 at once — three succeed, two are told only
 *    10 is left, and the balance equals the sum of the ledger. The balance row's lock is what serialises them.
 *
 * Commits to the test database (no wrapping transaction, which would hide the race) and cleans up after itself.
 */

/** @return list<string> */
function runStockWorkers(string $mode, array $args, int $count): array
{
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
        'MAIL_MAILER' => 'array',
    ]);

    $startAt = sprintf('%.6F', microtime(true) + 4.0 + $count * 0.5);

    $running = [];
    for ($i = 0; $i < $count; $i++) {
        $running[] = [proc_open([PHP_BINARY, base_path('tests/Support/stock_worker.php'), $mode, ...$args, $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env), $pipes];
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
    $this->company = makeCompany('ST'.$this->tag);
    $this->location = makeLocation($this->company, 'S'.$this->tag);
    $this->owner = makeUser([], [$this->company]);
    $c = $this->company->id;
    $now = now();

    // The smallest world a receipt needs, written straight to the tables (no seeded defaults to clean up).
    $unit = DB::table('units')->insertGetId(['company_id' => $c, 'code' => 'U'.$this->tag, 'name' => 'Unit '.$this->tag, 'decimal_allowed' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $group = DB::table('item_groups')->insertGetId(['company_id' => $c, 'code' => 'G'.$this->tag, 'name' => 'Group '.$this->tag, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $this->itemId = DB::table('items')->insertGetId([
        'company_id' => $c, 'name' => 'Item '.$this->tag, 'item_group_id' => $group, 'base_unit_id' => $unit, 'hsn_code' => '7318', 'gst_rate' => 18,
        'approval_status' => 'approved', 'workflow_stage' => 'approved', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $accountGroup = DB::table('account_groups')->insertGetId(['company_id' => $c, 'code' => 'AG'.$this->tag, 'name' => 'Creditors '.$this->tag, 'nature' => 'liability', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $supplier = DB::table('ledgers')->insertGetId([
        'company_id' => $c, 'code' => 'SUP'.$this->tag, 'name' => 'Racing supplier '.$this->tag, 'ledger_type' => 'supplier', 'account_group_id' => $accountGroup,
        'approval_status' => 'approved', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $po = DB::table('purchase_orders')->insertGetId([
        'company_id' => $c, 'location_id' => $this->location->id, 'po_number' => "PO/{$this->tag}/1", 'po_date' => $now->toDateString(), 'expected_date' => $now->toDateString(),
        'supplier_ledger_id' => $supplier, 'supplier_name' => 'Racing supplier', 'state' => 'open', 'total_amount' => 1000, 'approval_status' => 'approved', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $poLine = DB::table('purchase_order_items')->insertGetId([
        'company_id' => $c, 'purchase_order_id' => $po, 'line_no' => 1, 'item_id' => $this->itemId, 'unit_id' => $unit, 'item_name' => 'Item', 'quantity' => 100,
        'base_quantity' => 100, 'rate' => 10, 'amount' => 1000, 'tolerance_percent' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $this->grnId = DB::table('grns')->insertGetId([
        'company_id' => $c, 'location_id' => $this->location->id, 'grn_number' => "GRN/{$this->tag}/1", 'grn_date' => $now->toDateString(), 'purchase_order_id' => $po,
        'supplier_ledger_id' => $supplier, 'supplier_name' => 'Racing supplier', 'approval_status' => 'approved', 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('grn_items')->insert([
        'company_id' => $c, 'grn_id' => $this->grnId, 'purchase_order_item_id' => $poLine, 'line_no' => 1, 'item_id' => $this->itemId, 'unit_id' => $unit, 'item_name' => 'Item',
        'accepted_quantity' => 100, 'rejected_quantity' => 0, 'base_accepted' => 100, 'base_received' => 100, 'unit_cost' => 10, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $this->movements = fn () => DB::table('stock_movements')->where('company_id', $c);
    $this->balance = fn () => DB::table('stock_balances')->where('company_id', $c)->where('item_id', $this->itemId)->first();
});

afterEach(function () {
    $id = $this->company->id;

    foreach (['item_cost_history', 'stock_movements', 'stock_balances', 'batches', 'grn_items', 'grns', 'purchase_order_items', 'purchase_orders', 'ledgers', 'account_groups', 'items', 'item_groups', 'units',
        'activity_log', 'approvals', 'number_series', 'company_user'] as $table) {
        DB::table($table)->where('company_id', $id)->delete();
    }

    DB::table('location_user')->where('user_id', $this->owner->id)->delete();
    User::query()->whereKey($this->owner->id)->update(['default_company_id' => null]);
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->where('id', $this->owner->id)->delete();
});

it('puts an approved goods receipt into stock once when several processes post it at the same moment', function () {
    $lines = runStockWorkers('post', [(string) $this->company->id, (string) $this->grnId], 5);

    $posted = collect($lines)->filter(fn (string $l) => $l === 'OK posted')->count();
    $idle = collect($lines)->filter(fn (string $l) => $l === 'OK nothing to do')->count();

    expect($posted)->toBe(1, implode(' | ', $lines))->and($idle)->toBe(4, implode(' | ', $lines))
        ->and(($this->movements)()->count())->toBe(1)
        ->and((string) ($this->balance)()->quantity)->toBe('100.000')->and((string) ($this->balance)()->value)->toBe('1000.00')
        ->and(DB::table('grns')->where('id', $this->grnId)->value('posted_at'))->not->toBeNull();
});

it('never takes stock below zero or loses an update when several processes take from the same balance at once', function () {
    app(TenantContext::class)->withCompany($this->company->id, fn () => app(StockService::class)
        ->receive(Item::withoutGlobalScopes(Tenancy::SCOPES)->findOrFail($this->itemId), $this->location->id, '100', '10', null, 'race', 1, 1));

    $lines = runStockWorkers('take', [(string) $this->company->id, (string) $this->owner->id, (string) $this->itemId, (string) $this->location->id, '30'], 5);

    $took = collect($lines)->filter(fn (string $l) => str_starts_with($l, 'OK '))->count();
    $told = collect($lines)->filter(fn (string $l) => str_starts_with($l, 'REFUSED:') && str_contains($l, 'on hand'))->count();
    $ledger = ($this->movements)()->selectRaw('sum(quantity) as q, sum(value) as v')->first();

    expect($took)->toBe(3, implode(' | ', $lines))->and($told)->toBe(2, implode(' | ', $lines))
        ->and((string) ($this->balance)()->quantity)->toBe('10.000')->and((string) ($this->balance)()->value)->toBe('100.00')
        ->and(bcadd((string) $ledger->q, '0', 3))->toBe('10.000')->and(bcadd((string) $ledger->v, '0', 2))->toBe('100.00');
});

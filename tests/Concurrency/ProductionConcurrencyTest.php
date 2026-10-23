<?php

use App\Models\User;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Masters\Models\Item;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/*
 * Real concurrency for issuing material to a production order, with separate PHP processes acting at the same instant:
 *
 *  - An order requires 100 of a component and allows 10% over (110). Six processes each try to issue 30 (each with its own entry key): the order row lock makes them take turns, so
 *    **three** succeed (90) and three are told only 20 more may be issued — the order never goes past its allowance and stock matches the entries.
 *  - Six processes send the **same** entry (the same key — a double click, a retried request): one entry is made, one stock movement, however many answered.
 *
 * Commits to the test database (no wrapping transaction, which would hide the race) and cleans up after itself.
 */

/** @return list<string> */
function runProductionWorkers(int $count, int $companyId, int $actorId, int $orderId, int $componentId, string $quantity, callable $key): array
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
        $running[] = [proc_open([PHP_BINARY, base_path('tests/Support/production_worker.php'), 'issue', (string) $companyId, (string) $actorId, (string) $orderId, (string) $componentId, $quantity, $key($n), $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env), $pipes];
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
    $this->company = makeCompany('TP'.$this->tag);
    $this->location = makeLocation($this->company, 'P'.$this->tag);
    $this->actor = makeUser([], [$this->company]);
    $this->actor->givePermissionTo(Permission::findOrCreate('production_order.execute', 'web'));
    $c = $this->company->id;
    $now = now();

    // The smallest world an issue needs, written straight to the tables.
    $unit = DB::table('units')->insertGetId(['company_id' => $c, 'code' => 'U'.$this->tag, 'name' => 'Unit '.$this->tag, 'decimal_allowed' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $group = DB::table('item_groups')->insertGetId(['company_id' => $c, 'code' => 'G'.$this->tag, 'name' => 'Group '.$this->tag, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $item = fn (string $name) => DB::table('items')->insertGetId([
        'company_id' => $c, 'name' => $name.' '.$this->tag, 'item_group_id' => $group, 'base_unit_id' => $unit, 'hsn_code' => '7318', 'gst_rate' => 18,
        'approval_status' => 'approved', 'workflow_stage' => 'approved', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $this->finishedId = $item('Finished');
    $this->componentItemId = $item('Component');
    $bom = DB::table('boms')->insertGetId(['company_id' => $c, 'code' => 'B'.$this->tag, 'item_id' => $this->finishedId, 'output_quantity' => 1, 'approval_status' => 'approved', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $this->orderId = DB::table('production_orders')->insertGetId([
        'company_id' => $c, 'location_id' => $this->location->id, 'order_number' => 'MO/'.$this->tag.'/1', 'bom_id' => $bom, 'item_id' => $this->finishedId, 'item_name' => 'Finished',
        'planned_quantity' => 100, 'planned_start' => $now->toDateString(), 'planned_end' => $now->toDateString(), 'state' => 'released', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $this->componentId = DB::table('production_components')->insertGetId([
        'company_id' => $c, 'production_order_id' => $this->orderId, 'line_no' => 1, 'item_id' => $this->componentItemId, 'item_name' => 'Component', 'required_quantity' => 100, 'created_at' => $now, 'updated_at' => $now,
    ]);

    app(TenantContext::class)->withCompany($c, fn () => app(StockService::class)
        ->receive(Item::withoutGlobalScopes(Tenancy::SCOPES)->findOrFail($this->componentItemId), $this->location->id, '1000', '5', null, 'race', 1, 1));
});

afterEach(function () {
    $id = $this->company->id;

    foreach (['production_issues', 'production_outputs', 'production_components', 'production_orders', 'bom_items', 'boms', 'item_cost_history', 'stock_movements', 'stock_balances', 'batches', 'items', 'item_groups', 'units',
        'activity_log', 'number_series', 'company_user'] as $table) {
        DB::table($table)->where('company_id', $id)->delete();
    }

    DB::table('model_has_permissions')->where('model_id', $this->actor->id)->where('model_type', 'user')->delete();
    DB::table('location_user')->where('user_id', $this->actor->id)->delete();
    User::query()->whereKey($this->actor->id)->update(['default_company_id' => null]);
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->where('id', $this->actor->id)->delete();
});

it('never lets several people issue more than an order allows, however many press at the same moment', function () {
    $lines = runProductionWorkers(6, $this->company->id, $this->actor->id, $this->orderId, $this->componentId, '30', fn (int $n) => 'race-key-'.$n.'-'.$this->tag);

    $ok = collect($lines)->filter(fn ($l) => $l === 'OK')->count();
    $refused = collect($lines)->filter(fn ($l) => str_starts_with($l, 'REFUSED:') && str_contains($l, 'at most 20'))->count();

    expect($ok)->toBe(3, implode(' | ', $lines))->and($refused)->toBe(3, implode(' | ', $lines))
        ->and(DB::table('production_issues')->where('production_order_id', $this->orderId)->count())->toBe(3)
        ->and((string) DB::table('production_issues')->where('production_order_id', $this->orderId)->sum('quantity'))->toBe('90.000')
        ->and((string) DB::table('stock_balances')->where('company_id', $this->company->id)->where('item_id', $this->componentItemId)->value('quantity'))->toBe('910.000')
        ->and(DB::table('stock_movements')->where('company_id', $this->company->id)->where('movement_type', 'issue')->count())->toBe(3);
});

it('makes one entry when the same entry arrives from several processes at once', function () {
    $lines = runProductionWorkers(6, $this->company->id, $this->actor->id, $this->orderId, $this->componentId, '30', fn () => 'same-key-'.$this->tag);

    expect(collect($lines)->reject(fn ($l) => $l === 'OK')->all())->toBe([], implode(' | ', $lines))
        ->and(DB::table('production_issues')->where('production_order_id', $this->orderId)->count())->toBe(1)
        ->and(DB::table('stock_movements')->where('company_id', $this->company->id)->where('movement_type', 'issue')->count())->toBe(1)
        ->and((string) DB::table('stock_balances')->where('company_id', $this->company->id)->where('item_id', $this->componentItemId)->value('quantity'))->toBe('970.000');
});

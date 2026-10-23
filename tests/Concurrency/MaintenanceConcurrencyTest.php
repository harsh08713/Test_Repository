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
 * Real concurrency for maintenance, with separate PHP processes acting at the same instant:
 *
 *  - **One preventive order for each due date**: six runs of the generator start together on a schedule that has come due — exactly one work order is made (the open-order check, and
 *    `unique(schedule, due date)` behind it).
 *  - **One entry is one entry**: six processes send the same spare-part entry (the same key — a double click, a retried request) — one entry, one stock movement, the stock down once.
 *
 * Commits to the test database (no wrapping transaction, which would hide the race) and cleans up after itself.
 */

/** @return list<string> */
function runMaintenanceWorkers(string $mode, array $args, int $count): array
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
        $running[] = [proc_open([PHP_BINARY, base_path('tests/Support/maintenance_worker.php'), $mode, ...$args, $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env), $pipes];
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
    $this->company = makeCompany('TM'.$this->tag);
    $this->location = makeLocation($this->company, 'M'.$this->tag);
    $this->actor = makeUser([], [$this->company]);
    $c = $this->company->id;
    $now = now();

    // The smallest world a work order needs, written straight to the tables.
    $unit = DB::table('units')->insertGetId(['company_id' => $c, 'code' => 'U'.$this->tag, 'name' => 'Unit '.$this->tag, 'decimal_allowed' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $group = DB::table('item_groups')->insertGetId(['company_id' => $c, 'code' => 'G'.$this->tag, 'name' => 'Group '.$this->tag, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $this->itemId = DB::table('items')->insertGetId([
        'company_id' => $c, 'name' => 'Spare '.$this->tag, 'item_group_id' => $group, 'base_unit_id' => $unit, 'hsn_code' => '7318', 'gst_rate' => 18,
        'approval_status' => 'approved', 'workflow_stage' => 'approved', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $this->assetId = DB::table('assets')->insertGetId(['company_id' => $c, 'location_id' => $this->location->id, 'code' => 'A'.$this->tag, 'name' => 'Press', 'category' => 'machine', 'criticality' => 'medium', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $this->planId = DB::table('maintenance_plans')->insertGetId([
        'company_id' => $c, 'code' => 'P'.$this->tag, 'asset_id' => $this->assetId, 'name' => 'Monthly grease', 'every_n' => 1, 'unit' => 'months', 'lead_days' => 3, 'status' => 'active',
        'next_due_on' => $now->copy()->addDays(2)->toDateString(), 'created_at' => $now, 'updated_at' => $now,
    ]);
});

afterEach(function () {
    $id = $this->company->id;

    foreach (['maintenance_labour', 'maintenance_parts', 'maintenance_tasks', 'maintenance_orders', 'maintenance_plans', 'assets', 'item_cost_history', 'stock_movements', 'stock_balances', 'items', 'item_groups', 'units',
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

it('makes one preventive order for a due date however many runs start together', function () {
    $lines = runMaintenanceWorkers('generate', [(string) $this->company->id], 6);

    $made = collect($lines)->sum(fn ($l) => str_starts_with($l, 'OK made ') ? (int) substr($l, 8) : 0);

    expect(collect($lines)->reject(fn ($l) => str_starts_with($l, 'OK made '))->all())->toBe([], implode(' | ', $lines))
        ->and($made)->toBe(1, implode(' | ', $lines))
        ->and(DB::table('maintenance_orders')->where('maintenance_plan_id', $this->planId)->count())->toBe(1);
});

it('makes one entry when the same spare-part entry arrives from several processes at once', function () {
    $c = $this->company->id;
    app(TenantContext::class)->withCompany($c, fn () => app(StockService::class)->receive(Item::withoutGlobalScopes(Tenancy::SCOPES)->findOrFail($this->itemId), $this->location->id, '50', '10', null, 'race', 1, 1));
    $orderId = DB::table('maintenance_orders')->insertGetId([
        'company_id' => $c, 'location_id' => $this->location->id, 'order_number' => 'MW/'.$this->tag.'/1', 'asset_id' => $this->assetId, 'type' => 'corrective', 'priority' => 'normal', 'title' => 'Fix',
        'state' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $lines = runMaintenanceWorkers('part', [(string) $c, (string) $this->actor->id, (string) $orderId, (string) $this->itemId, '3', 'same-key-'.$this->tag], 6);

    expect(collect($lines)->reject(fn ($l) => $l === 'OK issued')->all())->toBe([], implode(' | ', $lines))
        ->and(DB::table('maintenance_parts')->where('maintenance_order_id', $orderId)->count())->toBe(1)
        ->and(DB::table('stock_movements')->where('company_id', $c)->where('movement_type', 'issue')->count())->toBe(1)
        ->and((string) DB::table('stock_balances')->where('company_id', $c)->where('item_id', $this->itemId)->value('quantity'))->toBe('47.000');
});

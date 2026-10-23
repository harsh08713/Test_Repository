<?php

use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\Masters\Models\AccountGroup;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\ItemGroup;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Models\State;
use App\Modules\Masters\Models\Unit;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\QuotationItem;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Real concurrency for the two promises sales orders depend on, with separate PHP processes (each with its own database connection) acting at
 * the same instant:
 *
 *  - A quotation is ordered **once**: the same accepted quotation ordered by five people at once makes one order, burns one number and tells
 *    the other four it has been ordered. Two things make it so, each with its own proof: the quotation's row is locked while it is ordered
 *    (and the order that won is read with a locking read), and `sales_orders.live_quotation_id` is unique (a request that waited for the lock
 *    would otherwise read the orders as they were when its transaction began).
 *  - An approved order is invoiced **once**: five processes asking for the invoice of the same order at once make one invoice with one number,
 *    and give back the numbers they took (the series has no gap). The order's row lock decides who makes it; `unique(sales_order_id,
 *    source_key)` is the backstop.
 *
 * Commits to the test database (no wrapping transaction, which would hide the race) and cleans up after itself.
 */

/**
 * Start `$count` processes, all told to do the same thing at the same moment, and collect the one line each printed.
 *
 * @param  list<string>  $args  what the worker needs after its mode (without the start time)
 * @return list<string>
 */
function runOrderWorkers(string $mode, array $args, int $count): array
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

    // Booting the framework takes a moment in each process: give them all time to be ready before the shot.
    $startAt = sprintf('%.6F', microtime(true) + 4.0 + $count * 0.5);

    $running = [];
    for ($i = 0; $i < $count; $i++) {
        $running[] = [proc_open(
            [PHP_BINARY, base_path('tests/Support/order_worker.php'), $mode, ...$args, $startAt],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $env,
        ), $pipes];
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
    $this->company = makeCompany('SC'.$this->tag, ['state_code' => '24']);
    $this->location = makeLocation($this->company, 'S'.$this->tag, ['state_code' => '24']);
    $this->owner = makeUser([], [$this->company]);
    $c = $this->company->id;

    // The smallest world an order needs (no seeded defaults to clean up): a unit, an item, a customer, a state, a lead and an accepted quotation.
    $unit = (new Unit(['code' => 'U'.$this->tag, 'name' => 'Unit '.$this->tag, 'decimal_allowed' => true]))->forceFill(['company_id' => $c]);
    $unit->save();
    $group = (new ItemGroup(['code' => 'G'.$this->tag, 'name' => 'Group '.$this->tag]))->forceFill(['company_id' => $c]);
    $group->save();
    $this->item = (new Item(['name' => 'Item '.$this->tag, 'po_tolerance_percent' => 0]))->forceFill([
        'company_id' => $c, 'item_group_id' => $group->id, 'base_unit_id' => $unit->id, 'hsn_code' => '7318', 'gst_rate' => '18',
        'approval_status' => ApprovalStatus::Approved, 'workflow_stage' => 'approved',
    ]);
    $this->item->save();
    $this->state = (new State(['code' => 'ST'.$this->tag, 'gst_code' => '24', 'name' => 'State '.$this->tag]))->forceFill(['company_id' => $c]);
    $this->state->save();
    $accountGroup = (new AccountGroup(['code' => 'AG'.$this->tag, 'name' => 'Debtors '.$this->tag, 'nature' => 'asset']))->forceFill(['company_id' => $c]);
    $accountGroup->save();
    $this->customer = (new Ledger(['code' => 'C'.$this->tag, 'name' => 'Racing customer '.$this->tag, 'status' => 'active']))->forceFill([
        'company_id' => $c, 'ledger_type' => 'customer', 'account_group_id' => $accountGroup->id, 'customer_kind' => 'b2c', 'approval_status' => ApprovalStatus::Approved,
    ]);
    $this->customer->save();
    $source = (new LeadSource(['code' => 'S'.$this->tag, 'name' => 'Source '.$this->tag]))->forceFill(['company_id' => $c]);
    $source->save();

    $this->lead = new Lead;
    $this->lead->forceFill([
        'company_id' => $c, 'location_id' => $this->location->id, 'lead_number' => "LD/{$this->tag}/1", 'lead_date' => now()->toDateString(), 'lead_source_id' => $source->id,
        'name' => 'Racing lead', 'mobile' => '9500000001', 'stage' => 'qualified', 'assigned_to' => $this->owner->id, 'created_by' => $this->owner->id,
    ])->save();

    // an accepted, approved quotation with one line, priced and taxed already (what an order copies)
    $this->accepted = function (int $n) use ($c) {
        $q = new Quotation;
        $q->forceFill([
            'company_id' => $c, 'location_id' => $this->location->id, 'quotation_number' => "QT/{$this->tag}/{$n}", 'revision_no' => 0, 'lead_id' => $this->lead->id,
            'customer_ledger_id' => $this->customer->id, 'state' => 'draft', 'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(20)->toDateString(),
            'price_date' => now()->toDateString(), 'party_name' => 'Racing customer', 'place_of_supply_state_id' => $this->state->id, 'supply_type' => 'intra',
            'created_by' => $this->owner->id,
        ])->save();

        (new QuotationItem)->forceFill([
            'company_id' => $c, 'quotation_id' => $q->id, 'line_no' => 1, 'item_id' => $this->item->id, 'unit_id' => $this->item->base_unit_id, 'item_name' => 'Item '.$this->tag,
            'hsn_code' => '7318', 'quantity' => '5', 'rate_source' => 'standard', 'list_rate' => '100', 'rate' => '100', 'gross_amount' => '500', 'taxable_amount' => '500',
            'gst_rate' => '18', 'cgst_amount' => '45', 'sgst_amount' => '45', 'line_total' => '590', 'created_by' => $this->owner->id,
        ])->save();

        DB::table('quotations')->where('id', $q->id)->update(['state' => 'accepted', 'approval_status' => 'approved', 'accepted_at' => now()]);

        return $q;
    };
    $this->ordersOf = fn () => DB::table('sales_orders')->where('company_id', $c);
    $this->lastNumber = fn (string $type) => (int) DB::table('number_series')->where('company_id', $c)->where('document_type', $type)->value('last_number');
});

afterEach(function () {
    $id = $this->company->id;

    foreach (['sales_invoice_items', 'sales_invoices', 'sales_order_history', 'sales_order_items', 'sales_orders', 'quotation_history', 'quotation_items', 'quotations',
        'lead_stage_history', 'lead_interests', 'leads', 'lead_sources', 'ledgers', 'account_groups', 'states', 'item_stage_history', 'items', 'item_groups', 'units',
        'activity_log', 'approvals', 'number_series', 'company_user'] as $table) {
        DB::table($table)->where('company_id', $id)->delete();
    }

    DB::table('location_user')->where('user_id', $this->owner->id)->delete();
    User::query()->whereKey($this->owner->id)->update(['default_company_id' => null]);
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->where('id', $this->owner->id)->delete();
});

it('makes one order, and burns one number, when the same quotation is ordered by several processes at once', function () {
    foreach (range(1, 2) as $round) {
        $q = ($this->accepted)($round);
        $before = ($this->lastNumber)('sales_order');

        $lines = runOrderWorkers('order', [(string) $this->company->id, (string) $this->owner->id, (string) $q->id, (string) $this->customer->id, (string) $this->item->id], 5);

        $made = collect($lines)->filter(fn (string $l) => str_starts_with($l, 'OK '))->count();
        $told = collect($lines)->filter(fn (string $l) => str_starts_with($l, 'REFUSED:') && str_contains($l, 'already been ordered'))->count();

        expect($made)->toBe(1, "round {$round}: ".implode(' | ', $lines))
            ->and($told)->toBe(4, "round {$round}: ".implode(' | ', $lines))
            ->and(DB::table('sales_orders')->where('quotation_id', $q->id)->count())->toBe(1)
            ->and(DB::table('sales_order_items')->whereIn('sales_order_id', DB::table('sales_orders')->where('quotation_id', $q->id)->pluck('id'))->count())->toBe(1)
            ->and(($this->lastNumber)('sales_order'))->toBe($before + 1, "round {$round}: the four that were refused took no number");
    }
});

it('makes one invoice, with one number, when the same approved order is invoiced by several processes at once', function () {
    $q = ($this->accepted)(1);
    $order = app(TenantContext::class)->withCompany($this->company->id, fn () => app(SalesOrderService::class)->createFromQuotation($q, [
        'order' => [
            'delivery_date' => now()->addDays(10)->toDateString(), 'place_of_supply_state_id' => (string) $this->state->id, 'party_name' => 'Racing customer', 'bill_line1' => '1 Race Road', 'reprice' => '',
        ],
        'items' => [['id' => '', 'quotation_item_id' => (string) DB::table('quotation_items')->where('quotation_id', $q->id)->value('id'), 'deal_item_id' => '',
            'item_id' => (string) $this->item->id, 'unit_id' => '', 'quantity' => '5', 'discount_percent' => '0', 'rate_override' => '', 'override_reason' => '']],
        'customer_ledger_id' => (string) $this->customer->id,
    ], User::query()->findOrFail($this->owner->id)));

    DB::table('sales_orders')->where('id', $order->id)->update(['approval_status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->owner->id]);
    expect(($this->lastNumber)('sales_invoice'))->toBe(0);

    $lines = runOrderWorkers('invoice', [(string) $this->company->id, (string) $order->id], 5);

    $numbers = collect($lines)->map(fn (string $l) => substr($l, 3))->unique()->values()->all();

    expect(collect($lines)->every(fn (string $l) => str_starts_with($l, 'OK ')))->toBeTrue(implode(' | ', $lines))
        ->and($numbers)->toHaveCount(1)->and($numbers[0])->toEndWith('/00001')
        ->and(DB::table('sales_invoices')->where('sales_order_id', $order->id)->count())->toBe(1)
        ->and(DB::table('sales_invoice_items')->whereIn('sales_invoice_id', DB::table('sales_invoices')->where('sales_order_id', $order->id)->pluck('id'))->count())->toBe(1)
        ->and(($this->lastNumber)('sales_invoice'))->toBe(1, 'the processes that lost gave their numbers back')
        ->and(DB::table('sales_orders')->where('id', $order->id)->value('state'))->toBe('invoiced')
        ->and(DB::table('sales_order_history')->where('sales_order_id', $order->id)->where('to_state', 'invoiced')->count())->toBe(1);
});

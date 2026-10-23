<?php

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Services\StockQuery;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderItem;
use App\Modules\Procurement\Services\GrnService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Procurement\Services\ReorderSuggestions;
use App\Modules\Reports\Http\Livewire\ScheduledReports;
use App\Modules\Reports\Models\ReportExport;
use App\Modules\Reports\Models\ReportSchedule;
use App\Modules\Reports\Notifications\ScheduledReportNotice;
use App\Modules\Reports\Services\ReportCatalog;
use App\Modules\Reports\Services\ReportScheduler;
use App\Modules\Reports\Support\RelativeRange;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The stock and procurement reports, and reports that are e-mailed on a schedule. The stock reports are checked against StockQuery / PurchaseOrderService / ReorderSuggestions
 * (the places that work the figures out). Hand-calculated: today is 12 Oct 2026; 100 bolts at ₹10 and 50 at ₹16 are 150 at an average of ₹12 = ₹1,800.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();
    Storage::fake('local');

    $this->acme = makeCompany('ACME');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->purchase = makeUser(['Purchase User'], [$this->acme]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->store = makeUser(['Inventory User'], [$this->acme], [$this->ahd]);   // Ahmedabad only
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme]);

    $this->supplier = makeLedger($this->acme, $this->purchase, 'supplier', ['ledger' => ['name' => 'Steel Traders', 'lead_time_days' => '7']], true, $this->accounts);
    $this->bolt = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'MS Hex Bolt M10', 'standard_cost' => '4']], 'approved');
    $this->drug = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Paracetamol 500'], 'uoms' => [], 'inventory' => ['tracking' => 'batch', 'has_expiry' => true]], 'approved');

    $this->do = fn (callable $call) => inCompany($this->acme, $call);
    $this->as = function ($user) {
        $this->actingAs($user);
        Auth::setUser($user);
        app(TenantContext::class)->flush();

        return $user;
    };
    $this->receive = fn ($item, string $qty, string $cost, $location = null, ?Batch $batch = null, int $line = 1) => ($this->do)(
        fn () => app(StockService::class)->receive($item, ($location ?? $this->ahd)->id, $qty, $cost, $batch, 'test', 1, $line)
    );
    $this->batch = fn (string $no, ?string $expiry) => ($this->do)(function () use ($no, $expiry) {
        $b = new Batch;
        $b->forceFill(['company_id' => $this->acme->id, 'item_id' => $this->drug->id, 'batch_no' => $no, 'expiry_date' => $expiry, 'status' => 'active'])->save();

        return $b;
    });
    $this->run = function ($user, string $key, array $filters = []) {
        return inCompany($this->acme, function () use ($user, $key, $filters) {
            ($this->as)($user);
            $report = app(ReportCatalog::class)->find($key);
            $input = ReportInput::resolve($report, $filters, $user);

            return ['report' => $report, 'rows' => array_map(fn ($r) => (object) $r, iterator_to_array($report->rows($input, $user), false)), 'summary' => $report->summary($input, $user)];
        });
    };
    $this->line = fn ($item, string $qty, string $rate) => ['item_id' => (string) $item->id, 'unit_id' => (string) unitId($this->acme, 'NOS'), 'quantity' => $qty, 'rate' => $rate];
    $this->approvedPo = function (array $items) {
        $po = ($this->do)(fn () => app(PurchaseOrderService::class)->create(['location_id' => (string) $this->ahd->id, 'supplier_ledger_id' => (string) $this->supplier->id, 'items' => $items], $this->purchase));

        return approveDocument($this->acme, $po, $this->purchase, $this->accounts);
    };
    $this->lineOf = fn (PurchaseOrder $po) => PurchaseOrderItem::withoutGlobalScopes(Tenancy::SCOPES)->where('purchase_order_id', $po->id)->where('line_no', 1)->firstOrFail();
    $this->stockUp = function () {
        ($this->receive)($this->bolt, '100', '10', null, null, 1);
        ($this->receive)($this->bolt, '50', '16', null, null, 2);
        ($this->receive)($this->bolt, '20', '5', $this->mum, null, 3);
        ($this->receive)($this->drug, '10', '2', null, ($this->batch)('OLD', '2026-10-01'), 4);       // expired 11 days ago
        ($this->receive)($this->drug, '40', '2', null, ($this->batch)('SOON', '2026-10-30'), 5);      // 18 days left
        ($this->receive)($this->drug, '100', '2', null, ($this->batch)('FAR', '2027-06-01'), 6);
    };
});

afterEach(fn () => Carbon::setTestNow());

// ---- stock and procurement reports ---------------------------------------------------------------------------------------------------------

it('lists expired and expiring batches and adds up to the expiry exposure the watch reports', function () {
    ($this->stockUp)();

    $r = ($this->run)($this->admin, 'expiring-batches');   // the default window: 30 days
    $byBatch = collect($r['rows'])->keyBy('batch_no');

    expect($byBatch->keys()->sort()->values()->all())->toBe(['OLD', 'SOON'])
        ->and((int) $byBatch['OLD']->days_left)->toBe(-11)->and((int) $byBatch['SOON']->days_left)->toBe(18)
        ->and($r['summary']['Value at risk (₹)'])->toBe('100.00')   // 10 × 2 + 40 × 2
        ->and($r['summary']['Expiry exposure for this window (₹)'])->toBe(inCompany($this->acme, fn () => app(StockQuery::class)->expiryExposure(30)));

    $expired = ($this->run)($this->admin, 'expiring-batches', ['choice' => 'expired']);
    expect(collect($expired['rows'])->pluck('batch_no')->all())->toBe(['OLD'])->and($expired['summary']['Value at risk (₹)'])->toBe('20.00');

    expect(collect(($this->run)($this->admin, 'expiring-batches', ['choice' => '180'])['rows'])->pluck('batch_no')->sort()->values()->all())->toBe(['OLD', 'SOON']);
    expect(collect(($this->run)($this->admin, 'expiring-batches', ['choice' => '180'])['rows']))->toHaveCount(2);
    expect(collect(($this->run)($this->admin, 'expiring-batches', ['choice' => '90'])['rows']))->toHaveCount(2);
});

it('values the stock by item and branch at the moving average', function () {
    ($this->stockUp)();

    $rows = collect(($this->run)($this->admin, 'stock-valuation')['rows']);
    $bolt = $rows->where('item_name', 'MS Hex Bolt M10')->keyBy('branch');

    expect($rows)->toHaveCount(3)
        ->and((string) $bolt['AHD Branch']->value)->toBe('1800.00')->and(bccomp((string) $bolt['AHD Branch']->average_cost, '12', 4))->toBe(0)
        ->and((string) $bolt['MUM Branch']->value)->toBe('100.00')
        ->and((string) $rows->firstWhere('item_name', 'Paracetamol 500')->value)->toBe('300.00')   // the three batches together: 10 + 40 + 100 at ₹2 (a batch is not a row)
        ->and($rows->sum(fn ($r) => (float) $r->value))->toBe(2200.0);
});

it('keeps stock reports to the branches a person works in and to those who may see stock', function () {
    ($this->stockUp)();

    expect(collect(($this->run)($this->store, 'stock-valuation')['rows'])->pluck('branch')->unique()->all())->toBe(['AHD Branch'])
        ->and(app(ReportCatalog::class)->find('stock-valuation')->canRun($this->bde))->toBeFalse()
        ->and(app(ReportCatalog::class)->find('open-purchase-order-lines')->canRun($this->bde))->toBeFalse()
        ->and(app(ReportCatalog::class)->find('goods-receipt-register')->canRun($this->bde))->toBeFalse();
});

it('lists what is still to come on open purchase orders from the receipts, and the late ones', function () {
    $po = ($this->approvedPo)([($this->line)($this->bolt, '200', '4.5')]);
    $late = ($this->approvedPo)([($this->line)($this->bolt, '30', '4.5')]);
    DB::table('purchase_orders')->where('id', $late->id)->update(['expected_date' => '2026-10-07']);   // five days late

    approveDocument($this->acme, ($this->do)(fn () => app(GrnService::class)->create($po->fresh(), ['lines' => [($this->lineOf)($po)->id => ['accepted' => '150']]], $this->store)), $this->store, $this->accounts);

    $rows = collect(($this->run)($this->admin, 'open-purchase-order-lines')['rows'])->keyBy('po_number');

    expect($rows)->toHaveCount(2)
        ->and($rows[$po->po_number]->received)->toBe('150')->and($rows[$po->po_number]->left)->toBe('50')
        ->and($rows[$late->po_number]->left)->toBe('30')->and((int) $rows[$late->po_number]->days_late)->toBe(5)
        ->and(inCompany($this->acme, fn () => app(PurchaseOrderService::class)->received($po->fresh())[($this->lineOf)($po)->id]))->toBe('150.000');

    expect(collect(($this->run)($this->admin, 'open-purchase-order-lines', ['choice' => 'late'])['rows'])->pluck('po_number')->all())->toBe([$late->po_number]);
});

it('registers goods receipts with accepted and rejected quantities and whether the stock went in', function () {
    $po = ($this->approvedPo)([($this->line)($this->bolt, '200', '4.5')]);
    $draft = ($this->do)(fn () => app(GrnService::class)->create($po->fresh(), ['lines' => [($this->lineOf)($po)->id => ['accepted' => '90', 'rejected' => '10', 'reject_reason' => 'Rusted']]], $this->store));

    $rows = collect(($this->run)($this->admin, 'goods-receipt-register')['rows']);
    expect($rows)->toHaveCount(1)->and($rows[0]->grn_number)->toBe($draft->grn_number)->and($rows[0]->accepted_text)->toBe('90')->and($rows[0]->rejected_text)->toBe('10')->and($rows[0]->posted_at)->toBeNull();

    approveDocument($this->acme, $draft, $this->store, $this->accounts);
    expect(collect(($this->run)($this->admin, 'goods-receipt-register')['rows'])[0]->posted_at)->not->toBeNull()
        ->and(Grn::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(1);
});

it('lists the reorder suggestions that ReorderSuggestions makes', function () {
    DB::table('item_location_parameters')->insert(['company_id' => $this->acme->id, 'item_id' => $this->bolt->id, 'location_id' => $this->ahd->id,
        'minimum_stock' => 20, 'reorder_level' => 50, 'maximum_stock' => 200, 'created_at' => now(), 'updated_at' => now()]);

    $rows = collect(($this->run)($this->admin, 'reorder-list')['rows']);
    $expected = inCompany($this->acme, fn () => app(ReorderSuggestions::class)->forCompany($this->acme->id));

    expect($rows)->toHaveCount(count($expected))->and($rows)->toHaveCount(1)->and($rows[0]->item_name)->toBe('MS Hex Bolt M10');
});

// ---- relative date ranges ----------------------------------------------------------------------------------------------------------------

it('works the date range out on the day it runs', function () {
    $day = CarbonImmutable::parse('2026-10-12');

    expect(RelativeRange::resolve('none', $day))->toBeNull()
        ->and(RelativeRange::resolve('yesterday', $day))->toBe(['from' => '2026-10-11', 'to' => '2026-10-11'])
        ->and(RelativeRange::resolve('last_7_days', $day))->toBe(['from' => '2026-10-05', 'to' => '2026-10-11'])
        ->and(RelativeRange::resolve('this_month', $day))->toBe(['from' => '2026-10-01', 'to' => '2026-10-12'])
        ->and(RelativeRange::resolve('last_month', $day))->toBe(['from' => '2026-09-01', 'to' => '2026-09-30'])
        ->and(RelativeRange::resolve('last_month', CarbonImmutable::parse('2026-03-31')))->toBe(['from' => '2026-02-01', 'to' => '2026-02-28'])
        ->and(RelativeRange::resolve('this_year', $day))->toBe(['from' => '2026-04-01', 'to' => '2026-10-12'])
        ->and(RelativeRange::resolve('last_year', $day))->toBe(['from' => '2025-04-01', 'to' => '2026-03-31'])
        ->and(RelativeRange::resolve('this_year', CarbonImmutable::parse('2026-02-10')))->toBe(['from' => '2025-04-01', 'to' => '2026-02-10']);
});

// ---- scheduled reports ---------------------------------------------------------------------------------------------------------------------

it('works out when a schedule is next due', function () {
    $s = app(ReportScheduler::class);
    $now = CarbonImmutable::parse('2026-10-12 09:30:00');   // a Monday

    expect($s->nextRun('daily', 0, 0, 7, $now)->toDateTimeString())->toBe('2026-10-13 07:00:00')
        ->and($s->nextRun('daily', 0, 0, 18, $now)->toDateTimeString())->toBe('2026-10-12 18:00:00')
        ->and($s->nextRun('weekly', 1, 0, 7, $now)->toDateTimeString())->toBe('2026-10-19 07:00:00')   // Monday 07:00 has gone
        ->and($s->nextRun('weekly', 5, 0, 7, $now)->toDateTimeString())->toBe('2026-10-16 07:00:00')
        ->and($s->nextRun('monthly', 0, 1, 7, $now)->toDateTimeString())->toBe('2026-11-01 07:00:00')
        ->and($s->nextRun('monthly', 0, 20, 7, $now)->toDateTimeString())->toBe('2026-10-20 07:00:00');
});

it('lets a person schedule only a report they may export, with filters they could pick, ten at most', function () {
    $s = app(ReportScheduler::class);
    $make = fn ($user, array $over = []) => ($this->do)(function () use ($s, $user, $over) {
        ($this->as)($user);

        return $s->create(['report' => 'stock-valuation', 'format' => 'xlsx', 'frequency' => 'weekly', 'weekday' => 1, 'send_hour' => 7, ...$over], $user);
    });

    $ok = $make($this->admin);
    expect($ok->next_run_at->toDateTimeString())->toBe('2026-10-19 07:00:00')->and($ok->active)->toBeTrue();

    expect(fn () => $make($this->bde))->toThrow(AuthorizationException::class)
        ->and(fn () => $make($this->admin, ['format' => 'doc']))->toThrow(ValidationException::class)
        ->and(fn () => $make($this->admin, ['frequency' => 'monthly', 'month_day' => 31]))->toThrow(ValidationException::class)
        ->and(fn () => $make($this->admin, ['send_hour' => 24]))->toThrow(ValidationException::class)
        ->and(fn () => $make($this->admin, ['date_range' => 'last_month']))->toThrow(ValidationException::class)   // stock valuation has no date to narrow
        ->and(fn () => $make($this->admin, ['filters' => ['branch' => '99999']]))->toThrow(ValidationException::class);

    foreach (range(2, ReportSchedule::MAX_PER_PERSON) as $n) {
        $make($this->admin);
    }

    expect(fn () => $make($this->admin))->toThrow(ValidationException::class);
});

it('makes and e-mails a due report as its owner, with the date range worked out that day, once', function () {
    ($this->stockUp)();
    $schedule = ($this->do)(function () {
        ($this->as)($this->admin);

        return app(ReportScheduler::class)->create(['report' => 'expiring-batches', 'format' => 'xlsx', 'frequency' => 'daily', 'send_hour' => 7, 'filters' => ['choice' => '30']], $this->admin);
    });
    expect($schedule->next_run_at->toDateTimeString())->toBe('2026-10-13 07:00:00');

    // not due yet
    expect(app(ReportScheduler::class)->runDue(CarbonImmutable::parse('2026-10-12 09:31:00')))->toBe(0);

    // due: the next morning
    $morning = CarbonImmutable::parse('2026-10-13 07:05:00');
    Carbon::setTestNow($morning);
    expect(app(ReportScheduler::class)->runDue($morning))->toBe(1);

    $export = ReportExport::withoutGlobalScopes(Tenancy::SCOPES)->where('schedule_id', $schedule->id)->firstOrFail();
    expect($export->status)->toBe('ready')->and($export->user_id)->toBe($this->admin->id)->and($export->row_count)->toBe(2)
        ->and(Storage::disk('local')->exists($export->file_path))->toBeTrue();
    Notification::assertSentTo($this->admin, ScheduledReportNotice::class, fn ($n) => $n->kind === 'scheduled_report' && $n->path === $export->file_path);

    // the next run is tomorrow, and running again now does nothing
    expect($schedule->fresh()->next_run_at->toDateTimeString())->toBe('2026-10-14 07:00:00')
        ->and(app(ReportScheduler::class)->runDue($morning))->toBe(0)
        ->and(ReportExport::withoutGlobalScopes(Tenancy::SCOPES)->where('schedule_id', $schedule->id)->count())->toBe(1);
});

it('uses the dates of the day it runs, not of the day it was scheduled', function () {
    Queue::fake();
    $schedule = ($this->do)(function () {
        ($this->as)($this->admin);

        return app(ReportScheduler::class)->create(['report' => 'lead-register', 'format' => 'xlsx', 'frequency' => 'monthly', 'month_day' => 1, 'send_hour' => 6, 'date_range' => 'last_month'], $this->admin);
    });

    $first = CarbonImmutable::parse('2026-11-01 06:10:00');
    Carbon::setTestNow($first);
    app(ReportScheduler::class)->runDue($first);

    $export = ReportExport::withoutGlobalScopes(Tenancy::SCOPES)->where('schedule_id', $schedule->id)->firstOrFail();
    expect($export->filters)->toMatchArray(['from' => '2026-10-01', 'to' => '2026-10-31']);
});

it('pauses itself, once, and says why, when its owner can no longer export the report', function () {
    $schedule = ($this->do)(function () {
        ($this->as)($this->admin);

        return app(ReportScheduler::class)->create(['report' => 'stock-valuation', 'format' => 'xlsx', 'frequency' => 'daily', 'send_hour' => 7], $this->admin);
    });
    DB::table('users')->where('id', $this->admin->id)->update(['is_active' => false]);

    $morning = CarbonImmutable::parse('2026-10-13 07:05:00');
    Carbon::setTestNow($morning);
    app(ReportScheduler::class)->runDue($morning);
    $fresh = $schedule->fresh();

    expect($fresh->active)->toBeFalse()->and($fresh->paused_reason)->toContain('no longer')
        ->and(ReportExport::withoutGlobalScopes(Tenancy::SCOPES)->where('schedule_id', $schedule->id)->count())->toBe(0);

    // a later sweep does not tell them again
    app(ReportScheduler::class)->runDue($morning->addDay());
    Notification::assertSentToTimes($this->admin, ScheduledReportNotice::class, 1);
});

it('lets a person pause, resume and remove only their own schedules', function () {
    $schedule = ($this->do)(function () {
        ($this->as)($this->admin);

        return app(ReportScheduler::class)->create(['report' => 'stock-valuation', 'format' => 'pdf', 'frequency' => 'daily', 'send_hour' => 7], $this->admin);
    });
    $s = app(ReportScheduler::class);

    expect(fn () => $s->setActive($schedule, false, $this->accounts))->toThrow(AuthorizationException::class)
        ->and(fn () => $s->remove($schedule, $this->accounts))->toThrow(AuthorizationException::class);

    ($this->do)(fn () => $s->setActive($schedule, false, $this->admin));
    expect($schedule->fresh()->active)->toBeFalse()->and($schedule->fresh()->paused_reason)->toBe('Paused by you.');

    ($this->do)(fn () => $s->setActive($schedule->fresh(), true, $this->admin));
    expect($schedule->fresh()->active)->toBeTrue()->and($schedule->fresh()->paused_reason)->toBeNull();

    ($this->do)(fn () => $s->remove($schedule->fresh(), $this->admin));
    expect(ReportSchedule::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('sends a schedule now without moving its next time, and only for its owner', function () {
    ($this->stockUp)();
    $schedule = ($this->do)(function () {
        ($this->as)($this->admin);

        return app(ReportScheduler::class)->create(['report' => 'stock-valuation', 'format' => 'xlsx', 'frequency' => 'weekly', 'weekday' => 1, 'send_hour' => 7], $this->admin);
    });
    $next = $schedule->next_run_at->toDateTimeString();

    expect(fn () => app(ReportScheduler::class)->sendNow($schedule, $this->accounts))->toThrow(AuthorizationException::class);

    ($this->do)(fn () => app(ReportScheduler::class)->sendNow($schedule, $this->admin));

    expect(ReportExport::withoutGlobalScopes(Tenancy::SCOPES)->where('schedule_id', $schedule->id)->where('status', 'ready')->count())->toBe(1)
        ->and($schedule->fresh()->next_run_at->toDateTimeString())->toBe($next);
    Notification::assertSentTo($this->admin, ScheduledReportNotice::class, fn ($n) => $n->kind === 'scheduled_report');
});

it('shows the scheduled reports screen, saves a schedule from it, and keeps other people’s out of it', function () {
    ($this->as)($this->admin);

    $component = Livewire::actingAs($this->admin)->test(ScheduledReports::class)
        ->call('openForm')
        ->set('form.report', 'stock-valuation')->set('form.frequency', 'monthly')->set('form.month_day', '5')->set('form.send_hour', '8')
        ->call('save')->assertHasNoErrors();

    $mine = ReportSchedule::withoutGlobalScopes(Tenancy::SCOPES)->where('user_id', $this->admin->id)->firstOrFail();
    expect($mine->frequency)->toBe('monthly')->and($mine->month_day)->toBe(5);
    $component->assertSee('Stock valuation');

    Livewire::actingAs($this->accounts)->test(ScheduledReports::class)->assertDontSee('Stock valuation');

    $other = Livewire::actingAs($this->accounts)->test(ScheduledReports::class);
    $other->call('pause', $mine->id)->assertNotFound();
    expect($mine->fresh()->active)->toBeTrue();
});

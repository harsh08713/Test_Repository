<?php

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Finance\Models\CostCenter;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Loss\Services\LossIncidentService;
use App\Modules\Maintenance\Http\Livewire\AssetForm;
use App\Modules\Maintenance\Http\Livewire\AssetList;
use App\Modules\Maintenance\Http\Livewire\AssetShow;
use App\Modules\Maintenance\Http\Livewire\ScheduleForm;
use App\Modules\Maintenance\Http\Livewire\ScheduleList;
use App\Modules\Maintenance\Http\Livewire\WorkOrderForm;
use App\Modules\Maintenance\Http\Livewire\WorkOrderList;
use App\Modules\Maintenance\Http\Livewire\WorkOrderShow;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Models\MaintenanceLabour;
use App\Modules\Maintenance\Models\MaintenanceOrder;
use App\Modules\Maintenance\Models\MaintenancePart;
use App\Modules\Maintenance\Models\MaintenancePlan;
use App\Modules\Maintenance\Services\AssetService;
use App\Modules\Maintenance\Services\MaintenanceFigures;
use App\Modules\Maintenance\Services\MaintenanceOrderService;
use App\Modules\Maintenance\Services\MaintenancePlanService;
use App\Modules\Reports\Services\ReportCatalog;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\IndustryTemplateSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * M1: assets, preventive schedules and maintenance work orders. Today is Monday 12 October 2026, 09:30. A breakdown that stopped the press at 06:00 and had it running again at 08:30
 * is 150 minutes of downtime; a second one of 90 minutes makes the mean time to repair (150 + 90) ÷ 2 = 120 minutes. A schedule "every month" completed on 31 January is next due on 28 February.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->mm = makeUser(['Maintenance Manager'], [$this->acme]);
    $this->tech = makeUser(['Maintenance Technician'], [$this->acme]);
    $this->tech2 = makeUser(['Maintenance Technician'], [$this->acme]);
    $this->mumTech = makeUser(['Maintenance Technician'], [$this->acme], [$this->mum]);   // works in Mumbai only
    $this->pm = makeUser(['Production Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme]);
    $this->outsider = makeUser(['Maintenance Manager'], [$this->globex]);
    $purchase = makeUser(['Purchase User'], [$this->acme]);

    $this->seal = makeItem($this->acme, $purchase, ['item' => ['name' => 'Seal kit'], 'uoms' => []], 'approved');
    $this->draftItem = makeItem($this->acme, $purchase, ['item' => ['name' => 'Not approved yet']]);

    $this->do = fn (callable $call) => inCompany($this->acme, $call);
    $this->as = function ($user) {
        $this->actingAs($user);
        app(TenantContext::class)->flush();

        return $user;
    };
    $this->assetInput = fn (array $over = []) => ['code' => 'PRESS-01', 'name' => 'Hydraulic press', 'location_id' => (string) $this->ahd->id, 'category' => 'machine', 'make_model' => 'HydroMax 200', 'serial_no' => 'HM-7731',
        'installed_on' => '2020-03-01', 'criticality' => 'high', 'cost_center_id' => '', 'remarks' => '', ...$over];
    $this->press = function () {
        static $asset = null;   // one asset per test: the same code cannot be made twice

        return $asset ??= ($this->do)(function () {
            ($this->as)($this->mm);

            return app(AssetService::class)->create(($this->assetInput)(), $this->mm);
        });
    };
    $this->forklift = fn () => ($this->do)(fn () => app(AssetService::class)->create(($this->assetInput)(['code' => 'FORK-02', 'name' => 'Forklift', 'location_id' => (string) $this->mum->id, 'category' => 'vehicle', 'criticality' => 'low']), $this->mm));
    $this->report = fn (array $over = [], $by = null) => ($this->do)(function () use ($over, $by) {
        ($this->as)($by ?? $this->pm);

        return app(MaintenanceOrderService::class)->create(['asset_id' => (string) ($this->press)()->id, 'type' => 'breakdown', 'priority' => 'normal', 'title' => 'Hydraulic oil leaking at the ram', 'stopped_at' => '2026-10-12T06:00', ...$over], $by ?? $this->pm);
    });
    $this->service = fn () => app(MaintenanceOrderService::class);
    $this->stock = fn (string $qty = '20') => ($this->do)(fn () => app(StockService::class)->receive($this->seal, $this->ahd->id, $qty, '50', null, 'test', 1, 1));
    $this->onHand = fn () => (string) DB::table('stock_balances')->where('item_id', $this->seal->id)->where('location_id', $this->ahd->id)->value('quantity');
    $this->key = fn () => bin2hex(random_bytes(8));
});

afterEach(fn () => Carbon::setTestNow());

// ---- assets and schedules ------------------------------------------------------------------------------------------------------------

it('makes an asset at a branch the person works in, with a code that is unique in the company', function () {
    $asset = ($this->press)();

    expect($asset->code)->toBe('PRESS-01')->and($asset->criticality)->toBe('high')->and($asset->installed_on->toDateString())->toBe('2020-03-01')->and($asset->status)->toBe('active');

    $try = fn (array $over, $by = null) => fn () => ($this->do)(function () use ($over, $by) {
        ($this->as)($by ?? $this->mm);

        return app(AssetService::class)->create(($this->assetInput)($over), $by ?? $this->mm);
    });

    expect($try(['code' => 'press-01']))->toThrow(ValidationException::class, 'already an asset')
        ->and($try(['code' => 'x', 'name' => 'Another']))->toThrow(ValidationException::class)
        ->and($try(['code' => 'PRESS-02', 'category' => 'spaceship']))->toThrow(ValidationException::class)
        ->and($try(['code' => 'PRESS-02', 'criticality' => 'huge']))->toThrow(ValidationException::class)
        ->and($try(['code' => 'PRESS-02', 'installed_on' => '2030-01-01']))->toThrow(ValidationException::class)
        ->and($try(['code' => 'PRESS-02', 'location_id' => '999999']))->toThrow(ValidationException::class)
        ->and($try(['code' => 'PRESS-02', 'cost_center_id' => '999999']))->toThrow(ValidationException::class)
        ->and($try(['code' => 'PRESS-02'], $this->mumTech))->toThrow(ValidationException::class, 'branch you work in')   // an Ahmedabad asset from a Mumbai-only person
        ->and(fn () => Asset::withoutGlobalScopes(Tenancy::SCOPES)->find($asset->id)->delete())->toThrow(LogicException::class);
});

it('will not make an asset inactive while it has open work, and puts it back in service', function () {
    $asset = ($this->press)();
    $order = ($this->report)();
    $svc = fn () => app(AssetService::class);

    expect(fn () => ($this->do)(fn () => $svc()->setStatus($asset, false, $this->mm)))->toThrow(FieldRuleException::class, 'open work orders');

    ($this->do)(fn () => app(MaintenanceOrderService::class)->cancel($order, 'Reported by mistake', $this->mm));
    expect(($this->do)(fn () => $svc()->setStatus($asset->fresh(), false, $this->mm))->status)->toBe('inactive')
        ->and(fn () => ($this->report)())->toThrow(ValidationException::class, 'active asset')   // nothing can be reported on it now
        ->and(($this->do)(fn () => $svc()->setStatus($asset->fresh(), true, $this->mm))->status)->toBe('active');
});

it('works out when a schedule next falls due, month ends included', function () {
    $plan = new MaintenancePlan(['every_n' => 1, 'unit' => 'months']);

    expect($plan->dueAfter(Carbon::parse('2027-01-31'))->toDateString())->toBe('2027-02-28')
        ->and($plan->dueAfter(Carbon::parse('2026-10-12'))->toDateString())->toBe('2026-11-12')
        ->and((new MaintenancePlan(['every_n' => 2, 'unit' => 'weeks']))->dueAfter(Carbon::parse('2026-10-12'))->toDateString())->toBe('2026-10-26')
        ->and((new MaintenancePlan(['every_n' => 90, 'unit' => 'days']))->dueAfter(Carbon::parse('2026-10-12'))->toDateString())->toBe('2027-01-10')
        ->and((new MaintenancePlan(['every_n' => 1, 'unit' => 'months']))->everyLabel())->toBe('every month')->and((new MaintenancePlan(['every_n' => 3, 'unit' => 'months']))->everyLabel())->toBe('every 3 months');
});

it('refuses a schedule that is not sensible', function () {
    $input = fn (array $over = []) => ['code' => 'PM-PRESS-01', 'asset_id' => (string) ($this->press)()->id, 'name' => 'Grease the ram', 'checklist' => "Grease points\nCheck the oil level", 'every_n' => '1', 'unit' => 'months',
        'lead_days' => '3', 'estimated_hours' => '2', 'next_due_on' => '2026-10-20', ...$over];
    $try = fn (array $over) => fn () => ($this->do)(function () use ($input, $over) {
        ($this->as)($this->mm);

        return app(MaintenancePlanService::class)->create($input($over), $this->mm);
    });

    expect($try(['code' => 'x']))->toThrow(ValidationException::class)
        ->and($try(['every_n' => '0']))->toThrow(ValidationException::class)
        ->and($try(['unit' => 'years']))->toThrow(ValidationException::class)
        ->and($try(['next_due_on' => '2026-10-01']))->toThrow(ValidationException::class, 'past')
        ->and($try(['lead_days' => '99']))->toThrow(ValidationException::class)
        ->and($try(['estimated_hours' => 'a lot']))->toThrow(ValidationException::class)
        ->and($try(['asset_id' => '999999']))->toThrow(ValidationException::class)
        ->and(MaintenancePlan::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);

    $made = $try([])();
    expect($made->checklist)->toBe("Grease points\nCheck the oil level")->and($try([]))->toThrow(ValidationException::class, 'already a schedule');
});

// ---- work orders -------------------------------------------------------------------------------------------------------------------------

it('reports a breakdown with its number, urgency from the asset and a checklist, and refuses nonsense', function () {
    $order = ($this->report)(['checklist' => "Isolate the press\nReplace the ram seal\nTest at full load"]);

    expect($order->order_number)->toBe('MW/AHD/26-27/00001')->and($order->type)->toBe('breakdown')->and($order->state)->toBe('open')->and($order->priority)->toBe('urgent')   // a high-criticality asset
        ->and($order->stopped_at->format('Y-m-d H:i'))->toBe('2026-10-12 06:00')->and($order->reported_by)->toBe($this->pm->id)->and($order->tasks)->toHaveCount(3);

    $try = fn (array $over, $by = null) => fn () => ($this->report)($over, $by);

    expect($try(['title' => 'x']))->toThrow(ValidationException::class)
        ->and($try(['type' => 'party']))->toThrow(ValidationException::class)
        ->and($try(['priority' => 'whenever']))->toThrow(ValidationException::class)
        ->and($try(['stopped_at' => '2026-10-13T08:00']))->toThrow(ValidationException::class, 'not in the future')
        ->and($try(['stopped_at' => '2026-06-01T08:00']))->toThrow(ValidationException::class)
        ->and($try(['asset_id' => '999999']))->toThrow(ValidationException::class)
        ->and($try(['assigned_to' => (string) $this->tech->id]))->toThrow(ValidationException::class, 'may not assign')   // a Production Manager cannot assign
        ->and($try(['assigned_to' => (string) $this->bde->id], $this->mm))->toThrow(ValidationException::class, 'does maintenance work')
        ->and($try([], $this->mumTech))->toThrow(ValidationException::class, 'branch you work in');

    expect(($this->report)(['assigned_to' => (string) $this->tech->id], $this->mm)->assigned_to)->toBe($this->tech->id);
});

it('lets a technician work only on an order that is theirs or nobody’s, and a manager on any', function () {
    $mine = ($this->report)(['assigned_to' => (string) $this->tech->id], $this->mm);
    $other = ($this->report)(['title' => 'Second fault', 'assigned_to' => (string) $this->tech2->id], $this->mm);
    $free = ($this->report)(['title' => 'Third fault']);
    $can = fn ($user, string $ability, $order) => inCompany($this->acme, fn () => ($this->as)($user)->can($ability, is_object($order) ? $order->fresh() : $order));

    expect($can($this->tech, 'execute', $mine))->toBeTrue()->and($can($this->tech, 'execute', $other))->toBeFalse()->and($can($this->tech, 'execute', $free))->toBeTrue()
        ->and($can($this->mm, 'execute', $other))->toBeTrue()->and($can($this->tech, 'assign', $mine))->toBeFalse()->and($can($this->mm, 'assign', $mine))->toBeTrue()
        ->and($can($this->pm, 'execute', $free))->toBeFalse()->and($can($this->pm, 'create', MaintenanceOrder::class))->toBeTrue()->and($can($this->bde, 'view', $mine))->toBeFalse();

    $moved = ($this->do)(fn () => ($this->service)()->assign($free->fresh(), $this->tech2->id, $this->mm));
    expect($moved->assigned_to)->toBe($this->tech2->id)->and(fn () => ($this->do)(fn () => ($this->service)()->assign($free->fresh(), $this->bde->id, $this->mm)))->toThrow(ValidationException::class);
});

it('starts on the first tick, takes spare parts from stock once for one entry, and refuses what stock cannot give', function () {
    ($this->stock)('20');
    $order = ($this->report)(['checklist' => "Isolate the press\nReplace the seal"]);
    $first = $order->tasks[0];

    ($this->do)(fn () => ($this->service)()->tick($order->fresh(), $first->id, $this->tech));
    expect($order->fresh()->state)->toBe('in_progress')->and($order->fresh()->started_at)->not->toBeNull()
        ->and(fn () => $first->fresh()->forceFill(['description' => 'changed'])->save())->toThrow(LogicException::class)
        ->and(($this->do)(fn () => ($this->service)()->tick($order->fresh(), $first->id, $this->tech))->done_at)->not->toBeNull();   // ticking again changes nothing

    $key = ($this->key)();
    $a = ($this->do)(fn () => ($this->service)()->issuePart($order->fresh(), $this->seal->id, null, '2', $key, $this->tech));
    $b = ($this->do)(fn () => ($this->service)()->issuePart($order->fresh(), $this->seal->id, null, '2', $key, $this->tech));   // a double click

    expect($b->id)->toBe($a->id)->and(MaintenancePart::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(1)->and(($this->onHand)())->toBe('18.000')
        ->and(($this->do)(fn () => app(MaintenanceFigures::class)->partsValue([$order->id])))->toBe('100.00');   // 2 × ₹50

    $try = fn (array $a2) => fn () => ($this->do)(fn () => ($this->service)()->issuePart($order->fresh(), ...$a2));
    expect($try([$this->draftItem->id, null, '1', ($this->key)(), $this->tech]))->toThrow(ValidationException::class, 'approved, active')
        ->and($try([$this->seal->id, null, '0', ($this->key)(), $this->tech]))->toThrow(ValidationException::class)
        ->and($try([$this->seal->id, null, '1', 'short', $this->tech]))->toThrow(ValidationException::class, 'out of date')
        ->and($try([$this->seal->id, null, '500', ($this->key)(), $this->tech]))->toThrow(StockException::class, 'on hand')
        ->and(MaintenancePart::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(1)   // the refused one left nothing behind
        ->and(fn () => MaintenancePart::withoutGlobalScopes(Tenancy::SCOPES)->first()->forceFill(['quantity' => '1'])->save())->toThrow(LogicException::class)
        ->and(fn () => MaintenancePart::withoutGlobalScopes(Tenancy::SCOPES)->first()->delete())->toThrow(LogicException::class);
});

it('logs hours for the day worked, once for one entry, and refuses impossible ones', function () {
    $order = ($this->report)();
    $key = ($this->key)();
    $log = fn (string $hours, ?string $date = null, ?string $k = null) => ($this->do)(fn () => ($this->service)()->logHours($order->fresh(), $hours, $date, 'Replaced the seal', $k ?? ($this->key)(), $this->tech));

    $a = ($this->do)(fn () => ($this->service)()->logHours($order->fresh(), '2.5', null, 'Isolated and drained', $key, $this->tech));
    $again = ($this->do)(fn () => ($this->service)()->logHours($order->fresh(), '2.5', null, 'Isolated and drained', $key, $this->tech));
    $log('1.25');

    expect($again->id)->toBe($a->id)->and(MaintenanceLabour::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(2)
        ->and(($this->do)(fn () => app(MaintenanceFigures::class)->labourHours([$order->id])))->toBe('3.75')
        ->and(fn () => $log('0'))->toThrow(ValidationException::class)->and(fn () => $log('25'))->toThrow(ValidationException::class)->and(fn () => $log('abc'))->toThrow(ValidationException::class)
        ->and(fn () => $log('1', '2026-10-13'))->toThrow(ValidationException::class, 'not in the future')->and(fn () => $log('1', '2026-08-01'))->toThrow(ValidationException::class)
        ->and(fn () => MaintenanceLabour::withoutGlobalScopes(Tenancy::SCOPES)->first()->delete())->toThrow(LogicException::class);
});

it('completes only with every task ticked and a note, works downtime out from the two moments, and keeps a record', function () {
    $order = ($this->report)(['checklist' => "Isolate the press\nReplace the seal"]);
    $complete = fn (string $note = 'Ram seal replaced, tested at full load', ?string $restored = '2026-10-12T08:30') => ($this->do)(fn () => ($this->service)()->complete($order->fresh(), $note, $restored, $this->tech));

    expect(fn () => $complete())->toThrow(ValidationException::class, 'Tick every task');
    foreach ($order->tasks as $task) {
        ($this->do)(fn () => ($this->service)()->tick($order->fresh(), $task->id, $this->tech));
    }

    expect(fn () => $complete(''))->toThrow(ValidationException::class, 'what was done')
        ->and(fn () => $complete('Fixed', '2026-10-12T05:00'))->toThrow(ValidationException::class, 'not before it stopped')
        ->and(fn () => $complete('Fixed', '2026-10-12T23:00'))->toThrow(ValidationException::class, 'not in the future')
        ->and(fn () => $complete('Fixed', 'yesterday-ish'))->toThrow(ValidationException::class);

    $done = $complete();
    $f = ($this->do)(fn () => app(MaintenanceFigures::class)->forOrder($done));

    expect($done->state)->toBe('completed')->and($done->restored_at->format('H:i'))->toBe('08:30')->and($f['downtime_minutes'])->toBe(150)->and(MaintenanceFigures::duration(150))->toBe('2 h 30 min')
        ->and(MaintenanceFigures::duration(45))->toBe('45 min')->and(MaintenanceFigures::duration(null))->toBe('—')
        ->and(fn () => MaintenanceOrder::withoutGlobalScopes(Tenancy::SCOPES)->find($done->id)->forceFill(['title' => 'Other'])->save())->toThrow(LogicException::class)
        ->and(fn () => $complete())->toThrow(FieldRuleException::class, 'already finished');
});

it('works out breakdowns, downtime and the mean time to repair for an asset', function () {
    $finish = function (string $stopped, string $restored) {
        $o = ($this->report)(['stopped_at' => $stopped]);

        return ($this->do)(fn () => ($this->service)()->complete($o, 'Repaired and tested', $restored, $this->mm));
    };
    $finish('2026-10-12T06:00', '2026-10-12T08:30');      // 150 minutes
    $finish('2026-10-11T05:00', '2026-10-11T06:30');      // 90 minutes
    ($this->report)(['title' => 'Still down', 'stopped_at' => '2026-10-12T09:00']);   // open: not counted

    $f = ($this->do)(fn () => app(MaintenanceFigures::class)->forAsset(($this->press)()->id, now()->subYear()));

    expect($f['breakdowns'])->toBe(2)->and($f['downtime_minutes'])->toBe(240)->and($f['mttr_minutes'])->toBe(120);

    $open = MaintenanceOrder::withoutGlobalScopes(Tenancy::SCOPES)->where('title', 'Still down')->first();
    expect(($this->do)(fn () => app(MaintenanceFigures::class)->forOrder($open))['downtime_minutes'])->toBe(30);   // since 09:00, counted to now (09:30)
});

it('cancels a work order only before parts or hours were put on it', function () {
    ($this->stock)();
    $fresh = ($this->report)();
    $used = ($this->report)(['title' => 'Second fault']);
    ($this->do)(fn () => ($this->service)()->issuePart($used->fresh(), $this->seal->id, null, '1', ($this->key)(), $this->tech));

    expect(fn () => ($this->do)(fn () => ($this->service)()->cancel($fresh->fresh(), 'x', $this->mm)))->toThrow(ValidationException::class)
        ->and(fn () => ($this->do)(fn () => ($this->service)()->cancel($used->fresh(), 'No longer needed', $this->mm)))->toThrow(FieldRuleException::class, 'complete it instead');

    $cancelled = ($this->do)(fn () => ($this->service)()->cancel($fresh->fresh(), 'Reported by mistake', $this->mm));
    expect($cancelled->state)->toBe('cancelled')->and($cancelled->cancel_reason)->toBe('Reported by mistake');
});

// ---- preventive orders -----------------------------------------------------------------------------------------------------------------

it('makes a preventive order a few days ahead, once for each due date, and moves the schedule on from the day it was done', function () {
    $asset = ($this->press)();
    $plan = ($this->do)(function () use ($asset) {
        ($this->as)($this->mm);

        return app(MaintenancePlanService::class)->create(['code' => 'PM-PRESS-01', 'asset_id' => (string) $asset->id, 'name' => 'Grease and inspect the hydraulics', 'checklist' => "Grease the points\nCheck the oil level",
            'every_n' => '1', 'unit' => 'months', 'lead_days' => '3', 'next_due_on' => '2026-10-20'], $this->mm);
    });
    $generate = fn (string $day) => ($this->do)(fn () => ($this->service)()->generateDue(CarbonImmutable::parse($day)));

    expect($generate('2026-10-12'))->toBe(0);          // due on the 20th, 3 days ahead = the 17th: not yet
    expect($generate('2026-10-17'))->toBe(1)->and($generate('2026-10-17'))->toBe(0)->and($generate('2026-10-18'))->toBe(0);   // once, and not again while one is open

    $order = MaintenanceOrder::withoutGlobalScopes(Tenancy::SCOPES)->where('maintenance_plan_id', $plan->id)->firstOrFail();
    expect($order->type)->toBe('preventive')->and($order->due_on->toDateString())->toBe('2026-10-20')->and($order->plan_due_on->toDateString())->toBe('2026-10-20')
        ->and($order->tasks)->toHaveCount(2)->and($order->order_number)->toBe('MW/AHD/26-27/00001')->and($order->reported_by)->toBeNull();

    foreach ($order->tasks as $task) {
        ($this->do)(fn () => ($this->service)()->tick($order->fresh(), $task->id, $this->tech));
    }
    Carbon::setTestNow('2026-10-18 10:00:00');                       // done two days early
    ($this->do)(fn () => ($this->service)()->complete($order->fresh(), 'Greased and inspected', null, $this->tech));

    $plan = $plan->fresh();
    expect($plan->last_done_on->toDateString())->toBe('2026-10-18')->and($plan->next_due_on->toDateString())->toBe('2026-11-18');   // from the day it was done

    expect($generate('2026-10-25'))->toBe(0)->and($generate('2026-11-15'))->toBe(1);

    // a switched-off schedule, or one on an inactive asset, makes nothing
    $plan->forceFill(['status' => 'inactive'])->save();
    DB::table('maintenance_orders')->whereIn('state', ['open'])->update(['state' => 'cancelled']);
    expect($generate('2026-12-31'))->toBe(0);
});

it('runs from the command for every company', function () {
    $asset = ($this->press)();
    ($this->do)(function () use ($asset) {
        ($this->as)($this->mm);
        app(MaintenancePlanService::class)->create(['code' => 'PM-1', 'asset_id' => (string) $asset->id, 'name' => 'Check', 'every_n' => '1', 'unit' => 'weeks', 'lead_days' => '3', 'next_due_on' => '2026-10-14'], $this->mm);
    });

    expect(Artisan::call('maintenance:generate-orders'))->toBe(0)->and(Artisan::output())->toContain('Work orders made: 1');
});

// ---- links, permissions and the screens ------------------------------------------------------------------------------------------------

it('links a breakdown to a loss incident by hand, and unlinks it', function () {
    $this->seed(IndustryTemplateSeeder::class);
    $auto = IndustryTemplate::query()->where('code', 'automotive')->firstOrFail();
    $admin = makeUser(['Company Admin'], [$this->acme]);
    $investigator = makeUser(['Investigator'], [$this->acme]);
    ($this->as)($admin);
    app(IndustryTemplateService::class)->enable($auto, $admin);
    $centre = ($this->do)(fn () => CostCenter::query()->create(['code' => 'PRESS', 'name' => 'Press shop']));
    $category = LossCategory::query()->where('industry_template_id', $auto->id)->where('code', 'line_stoppage')->firstOrFail();
    $incident = ($this->do)(function () use ($auto, $category, $centre, $investigator) {
        ($this->as)($investigator);

        return app(LossIncidentService::class)->create([
            'industry_template_id' => (string) $auto->id, 'loss_category_id' => (string) $category->id, 'location_id' => (string) $this->ahd->id, 'cost_center_id' => (string) $centre->id,
            'occurred_at' => '2026-10-12T08:00', 'severity' => 'high', 'title' => 'Press down for the morning', 'description' => 'The ram seal failed and the line stopped', 'custom_fields' => ['line' => 'L1'],
            'impact' => ['impact_type' => 'actual', 'formula_key' => 'manual', 'inputs' => ['amount' => '20000'], 'assumptions' => 'Lost output of the morning'],
        ], $investigator);
    });
    $order = ($this->report)();

    expect(fn () => ($this->do)(fn () => ($this->service)()->linkIncident($order->fresh(), 999999, $this->mm)))->toThrow(FieldRuleException::class, 'not found');

    $linked = ($this->do)(fn () => ($this->service)()->linkIncident($order->fresh(), $incident->id, $this->mm));
    expect($linked->loss_incident_id)->toBe($incident->id)->and(($this->do)(fn () => ($this->service)()->linkIncident($linked->fresh(), null, $this->mm))->loss_incident_id)->toBeNull();
});

it('keeps asset work to the maintenance people, reporting to those who may create, and other companies out', function () {
    $order = ($this->report)();
    $asset = ($this->press)();
    $can = fn ($user, string $ability, $model) => inCompany($this->acme, fn () => ($this->as)($user)->can($ability, $model));

    expect($can($this->tech, 'view', $asset))->toBeTrue()->and($can($this->tech, 'create', Asset::class))->toBeFalse()->and($can($this->mm, 'create', Asset::class))->toBeTrue()
        ->and($can($this->tech, 'create', MaintenancePlan::class))->toBeFalse()->and($can($this->mm, 'create', MaintenancePlan::class))->toBeTrue()
        ->and($can($this->bde, 'view', $asset))->toBeFalse()->and($can($this->pm, 'view', $asset))->toBeTrue()->and($can($this->pm, 'create', Asset::class))->toBeFalse()
        ->and($can($this->tech, 'update', $order->fresh()))->toBeFalse();   // planning an order is a manager's

    $this->actingAs($this->outsider)->get(route('maintenance.orders.show', $order))->assertNotFound();
    $this->actingAs($this->bde)->get(route('maintenance.assets.index'))->assertForbidden();
});

it('shows the asset and schedule screens and saves from their forms', function () {
    $asset = ($this->press)();
    ($this->as)($this->mm);

    Livewire::actingAs($this->mm)->test(AssetList::class)->assertSee('PRESS-01')->assertSee('Hydraulic press')->set('search', 'nothing-like-this')->assertDontSee('PRESS-01');
    Livewire::actingAs($this->mm)->test(AssetShow::class, ['asset' => $asset])->assertSee('Report a breakdown')->assertSee('Mean time to repair')->call('toggle')->assertHasNoErrors();
    expect($asset->fresh()->status)->toBe('inactive');
    Livewire::actingAs($this->mm)->test(AssetShow::class, ['asset' => $asset->fresh()])->call('toggle');

    Livewire::actingAs($this->mm)->test(AssetForm::class)->set('f.code', 'cnc-01')->set('f.name', 'CNC lathe')->set('f.location_id', (string) $this->mum->id)->set('f.category', 'machine')->call('save')->assertHasNoErrors();
    expect(Asset::withoutGlobalScopes(Tenancy::SCOPES)->where('code', 'CNC-01')->exists())->toBeTrue();

    Livewire::actingAs($this->mm)->test(ScheduleForm::class)->set('f.code', 'pm-1')->set('f.asset_id', (string) $asset->id)->set('f.name', 'Monthly grease')->set('f.next_due_on', '2026-11-01')->call('save')->assertHasNoErrors();
    $plan = MaintenancePlan::withoutGlobalScopes(Tenancy::SCOPES)->where('code', 'PM-1')->firstOrFail();
    Livewire::actingAs($this->mm)->test(ScheduleList::class)->assertSee('Monthly grease')->call('toggle', $plan->id);
    expect($plan->fresh()->status)->toBe('inactive');
});

it('runs a work order through its screens: report, assign, tick, take a part, log hours, complete', function () {
    ($this->stock)();
    $asset = ($this->press)();
    ($this->as)($this->pm);

    Livewire::actingAs($this->pm)->test(WorkOrderForm::class)->set('assetId', (string) $asset->id)->set('type', 'breakdown')->set('title', 'Ram seal failed')->set('stoppedAt', '2026-10-12T08:00')
        ->set('checklist', "Isolate the press\nReplace the seal")->call('save')->assertHasNoErrors();
    $order = MaintenanceOrder::withoutGlobalScopes(Tenancy::SCOPES)->firstOrFail();

    Livewire::actingAs($this->pm)->test(WorkOrderList::class)->assertSeeHtml('wire:key="wo-'.$order->id.'"')->set('type', 'preventive')->assertDontSeeHtml('wire:key="wo-'.$order->id.'"');

    Livewire::actingAs($this->mm)->test(WorkOrderShow::class, ['order' => $order])->assertSee('Ram seal failed')->set('assigneeId', (string) $this->tech->id)->call('assignTo')->assertHasNoErrors();
    expect($order->fresh()->assigned_to)->toBe($this->tech->id);

    $page = fn ($user) => Livewire::actingAs($user)->test(WorkOrderShow::class, ['order' => $order->fresh()]);

    $page($this->pm)->call('startWork')->assertForbidden();                                                         // reporting is not doing
    $page($this->tech2)->call('startWork')->assertForbidden();                                                      // another technician’s order
    foreach ($order->tasks as $task) {
        $page($this->tech)->call('tickTask', $task->id)->assertHasNoErrors();
    }
    $page($this->tech)->call('pickPart', $this->seal->id)->set('partQuantity', '2')->call('issueSparePart')->assertHasNoErrors();
    $page($this->tech)->set('hours', '2.5')->set('hourNote', 'Replaced the seal')->call('logWork')->assertHasNoErrors();
    expect(($this->onHand)())->toBe('18.000')->and(MaintenanceLabour::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(1);

    $page($this->tech)->call('openDialog', 'complete')->set('note', 'Seal replaced and tested')->set('restoredAt', '2026-10-12T09:15')->call('finishDialog')->assertHasNoErrors();
    expect($order->fresh()->state)->toBe('completed')->and($order->fresh()->restored_at->format('H:i'))->toBe('09:15');
});

it('opens every maintenance page for a manager', function () {
    $order = ($this->report)();
    $asset = ($this->press)();
    $plan = ($this->do)(function () use ($asset) {
        ($this->as)($this->mm);

        return app(MaintenancePlanService::class)->create(['code' => 'PM-1', 'asset_id' => (string) $asset->id, 'name' => 'Monthly grease', 'every_n' => '1', 'unit' => 'months', 'lead_days' => '3', 'next_due_on' => '2026-11-01'], $this->mm);
    });

    foreach (['/maintenance/orders', '/maintenance/orders/create', '/maintenance/orders/'.$order->id, '/maintenance/assets', '/maintenance/assets/create', '/maintenance/assets/'.$asset->id,
        '/maintenance/assets/'.$asset->id.'/edit', '/maintenance/schedules', '/maintenance/schedules/create', '/maintenance/schedules/'.$plan->id.'/edit'] as $url) {
        $this->actingAs($this->mm)->get($url)->assertOk();
    }
});

it('lists work orders and asset downtime in the reports, with the figures the pages show', function () {
    $asset = ($this->press)();
    $finish = function (string $stopped, string $restored, string $hours) {
        $o = ($this->report)(['stopped_at' => $stopped]);
        ($this->do)(fn () => ($this->service)()->logHours($o->fresh(), $hours, '2026-10-12', 'Work', ($this->key)(), $this->tech));

        return ($this->do)(fn () => ($this->service)()->complete($o->fresh(), 'Repaired and tested', $restored, $this->mm));
    };
    $finish('2026-10-12T06:00', '2026-10-12T08:30', '2');      // 150 minutes
    $finish('2026-10-11T05:00', '2026-10-11T06:30', '1.5');    // 90 minutes
    ($this->report)(['title' => 'Still down', 'stopped_at' => '2026-10-12T09:00']);
    ($this->forklift)();

    $run = fn (string $key, $user) => ($this->do)(function () use ($key, $user) {
        ($this->as)($user);
        $report = app(ReportCatalog::class)->find($key);
        $input = ReportInput::resolve($report, [], $user);

        return ['report' => $report, 'rows' => iterator_to_array($report->rows($input, $user), false), 'summary' => $report->summary($input, $user)];
    });

    $register = $run('maintenance-order-register', $this->mm);
    $still = collect($register['rows'])->firstWhere('title', 'Still down');
    expect($register['report']->canRun($this->bde))->toBeFalse()->and($register['rows'])->toHaveCount(3)->and($register['summary']['Not finished'])->toBe('1')->and($register['summary']['Breakdowns'])->toBe('3')
        ->and($still['downtime_text'])->toBe('30 min')->and(collect($register['rows'])->firstWhere('hours_text', '2')['downtime_text'])->toBe('2 h 30 min');

    $downtime = $run('asset-downtime', $this->mm);
    $row = collect($downtime['rows'])->firstWhere('code', 'PRESS-01');
    $page = ($this->do)(fn () => app(MaintenanceFigures::class)->forAsset($asset->id, now()->subYear()));

    expect($downtime['rows'])->toHaveCount(2)->and((int) $row['breakdowns'])->toBe($page['breakdowns'])->and($row['downtime_text'])->toBe(MaintenanceFigures::duration($page['downtime_minutes']))
        ->and($row['mttr_text'])->toBe(MaintenanceFigures::duration($page['mttr_minutes']))->and($row['hours_text'])->toBe('3.5')->and($page['breakdowns'])->toBe(2)->and($page['mttr_minutes'])->toBe(120)
        ->and(collect($downtime['rows'])->firstWhere('code', 'FORK-02')['mttr_text'])->toBe('—')->and($downtime['summary']['Downtime'])->toBe('4 h');
});

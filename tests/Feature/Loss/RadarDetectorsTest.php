<?php

use App\Modules\Inventory\Services\StockService;
use App\Modules\Loss\Models\RadarFinding;
use App\Modules\Loss\Notifications\LossAlertNotification;
use App\Modules\Loss\Radar\RadarService;
use App\Modules\Procurement\Services\GrnService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Production\Services\BomService;
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
 * Radar detectors that need more than stock records: material used above the standard on a completed production order, and goods bought above the standard cost.
 * Hand-calculated: an order for 500 Nos needs steel 20 × 1.05 × 5 = 105 kg; 110 kg issued at an average ₹50 and 500 made (480 good, 20 rejected) is 5 kg too much = ₹250.00.
 * A bolt with a standard cost of ₹4 bought at ₹4.50 is 12.5% above it: 90 accepted × ₹0.50 = ₹45.00; at ₹4.10 (2.5%) it is within the 5% allowed.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');

    $this->manager = makeUser(['Quality/Loss Manager'], [$this->acme]);
    $this->store = makeUser(['Inventory User'], [$this->acme], [$this->ahd]);
    $this->pm = makeUser(['Production Manager'], [$this->acme]);
    $this->op = makeUser(['Production Operator'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->purchase = makeUser(['Purchase User'], [$this->acme]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    app(SettingsService::class)->set('radar.min_amount', 10, $this->acme->id);

    $make = fn (string $name, string $unit, array $item = []) => makeItem($this->acme, $this->purchase, ['item' => ['name' => $name, 'base_unit_id' => (string) unitId($this->acme, $unit), ...$item], 'uoms' => [], 'inventory' => []], 'approved');
    $this->bolt = $make('Hex Bolt M10', 'NOS', ['standard_cost' => '4']);
    $this->steel = $make('Steel rod', 'KG');
    $this->zinc = $make('Zinc', 'KG');
    $this->supplier = makeLedger($this->acme, $this->purchase, 'supplier', ['ledger' => ['name' => 'Steel Traders']], true, $this->accounts);

    $this->do = fn (callable $call) => inCompany($this->acme, $call);
    $this->sweep = fn () => app(RadarService::class)->sweep();
    $this->findings = fn () => RadarFinding::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->orderBy('detector')->get()->keyBy('detector');
    $this->as = function ($user) {
        $this->actingAs($user);
        app(TenantContext::class)->flush();

        return $user;
    };

    // a receipt of 100 bolts at $rate (90 accepted, 5 rejected for the first test)
    $this->receipt = function (string $rate, string $accepted = '90', string $rejected = '0') {
        $po = ($this->do)(fn () => app(PurchaseOrderService::class)->create(['location_id' => (string) $this->ahd->id, 'supplier_ledger_id' => (string) $this->supplier->id,
            'items' => [['item_id' => (string) $this->bolt->id, 'unit_id' => (string) unitId($this->acme, 'NOS'), 'quantity' => '100', 'rate' => $rate]]], $this->purchase));
        $po = approveDocument($this->acme, $po, $this->purchase, $this->accounts);
        $line = $po->items()->first();
        $input = ['accepted' => $accepted, 'rejected' => $rejected] + ($rejected !== '0' ? ['reject_reason' => 'Rusted threads'] : []);
        $grn = ($this->do)(fn () => app(GrnService::class)->create($po->fresh(), ['lines' => [$line->id => $input]], $this->store));

        return approveDocument($this->acme, $grn, $this->store, $this->accounts);
    };
});

afterEach(fn () => Carbon::setTestNow());

it('flags goods bought more than the allowed percent above the standard cost, valued as the accepted quantity times the difference', function () {
    ($this->receipt)('4.5');
    ($this->receipt)('4.1');

    ($this->sweep)();
    $f = ($this->findings)()['price_variance'];

    expect(RadarFinding::withoutGlobalScopes(Tenancy::SCOPES)->where('detector', 'price_variance')->count())->toBe(1)   // 4.10 is within 5%
        ->and($f->estimated_amount)->toBe('45.00')->and($f->impact_type)->toBe('actual')->and($f->source_type)->toBe('grn_price')
        ->and($f->evidence['formula_key'])->toBe('material_cost')->and($f->evidence['inputs'])->toMatchArray(['quantity' => '90', 'unit_cost' => '0.5'])
        ->and($f->evidence['assumptions'])->toContain('current standard cost')->and($f->evidence['method'])->toContain('5%');

    app(SettingsService::class)->set('radar.price_variance_percent', 0, $this->acme->id);
    app(SettingsService::class)->set('radar.min_amount', 5, $this->acme->id);
    Carbon::setTestNow('2026-10-12 10:30:00');
    ($this->sweep)();

    // the company asked for any price above standard, and a minimum of ₹5: the 4.10 receipt (₹0.10 × 90 = ₹9) is now a finding too
    expect(RadarFinding::withoutGlobalScopes(Tenancy::SCOPES)->where('detector', 'price_variance')->count())->toBe(2);
});

it('leaves out items with no standard cost and prices that are not above it', function () {
    ($this->receipt)('3.8');
    ($this->do)(fn () => $this->bolt->forceFill(['standard_cost' => null])->save());
    ($this->receipt)('9');

    ($this->sweep)();

    expect(RadarFinding::withoutGlobalScopes(Tenancy::SCOPES)->where('detector', 'price_variance')->count())->toBe(0);
});

it('flags a completed production order that used more material than the standard for what it made', function () {
    $this->actingAs($this->pm);
    $bom = ($this->do)(function () {
        ($this->as)($this->pm);

        return app(BomService::class)->create(['code' => 'BOM-BOLT', 'item_id' => (string) $this->bolt->id, 'output_quantity' => '100', 'remarks' => '', 'items' => [
            ['item_id' => (string) $this->steel->id, 'quantity' => '20', 'scrap_percent' => '5'], ['item_id' => (string) $this->zinc->id, 'quantity' => '2.5', 'scrap_percent' => '0'],
        ]], $this->pm);
    });
    $bom = approveDocument($this->acme, $bom, $this->pm, $this->approver);
    ($this->do)(function () {
        app(StockService::class)->receive($this->steel, $this->ahd->id, '200', '50', null, 'test', 1, 1);
        app(StockService::class)->receive($this->zinc, $this->ahd->id, '50', '80', null, 'test', 1, 2);
    });

    $order = ($this->do)(function () use ($bom) {
        ($this->as)($this->pm);
        $order = app(ProductionOrderService::class)->create(['location_id' => (string) $this->ahd->id, 'bom_id' => (string) $bom->id, 'planned_quantity' => '500', 'planned_start' => '2026-10-12', 'planned_end' => '2026-10-16'], $this->pm);

        return app(ProductionOrderService::class)->release($order, $this->pm);
    });
    $issue = fn ($item, string $qty) => ($this->do)(function () use ($order, $item, $qty) {
        ($this->as)($this->op);

        return app(ProductionOrderService::class)->issue($order->fresh(), $order->components()->where('item_id', $item->id)->firstOrFail()->id, null, $qty, bin2hex(random_bytes(8)), $this->op);
    });

    $issue($this->steel, '110');
    $issue($this->zinc, '12.5');
    ($this->do)(function () use ($order) {
        ($this->as)($this->op);
        app(ProductionOrderService::class)->recordOutput($order->fresh(), ['good_quantity' => '480', 'rejected_quantity' => '20', 'reject_reason' => 'Damaged'], bin2hex(random_bytes(8)), $this->op);
    });

    ($this->sweep)();
    expect(RadarFinding::withoutGlobalScopes(Tenancy::SCOPES)->where('detector', 'production_variance')->count())->toBe(0);   // not completed yet

    ($this->do)(function () use ($order) {
        ($this->as)($this->pm);
        app(ProductionOrderService::class)->complete($order->fresh(), 'Rejects', $this->pm);
    });
    Carbon::setTestNow('2026-10-12 10:30:00');
    ($this->sweep)();
    $f = ($this->findings)()['production_variance'];

    expect($f->estimated_amount)->toBe('250.00')->and($f->severity)->toBe('high')->and($f->source_type)->toBe('production_order')->and($f->source_id)->toBe($order->id)
        ->and($f->evidence['assumptions'])->toContain('5 extra Steel rod')->and($f->evidence['formula_key'])->toBe('manual');

    // a high finding tells the reviewers once, and a refresh tells nobody again
    Notification::assertSentTo($this->manager, LossAlertNotification::class, fn ($n) => $n->kind === 'radar_high');
    Notification::assertNotSentTo($this->store, LossAlertNotification::class);
    Carbon::setTestNow('2026-10-12 11:30:00');
    ($this->sweep)();
    Notification::assertSentToTimes($this->manager, LossAlertNotification::class, 1);
});

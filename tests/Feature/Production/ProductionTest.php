<?php

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Inventory\Exceptions\StockException;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Production\Http\Livewire\BomForm;
use App\Modules\Production\Http\Livewire\BomList;
use App\Modules\Production\Http\Livewire\BomShow;
use App\Modules\Production\Http\Livewire\ProductionOrderForm;
use App\Modules\Production\Http\Livewire\ProductionOrderList;
use App\Modules\Production\Http\Livewire\ProductionOrderShow;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomItem;
use App\Modules\Production\Models\ProductionIssue;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOutput;
use App\Modules\Production\Services\BomService;
use App\Modules\Production\Services\ProductionFigures;
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\Reports\Services\ReportCatalog;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * P1: bills of materials and production orders. Hand-calculated: BOM-BOLT makes 100 Nos from 20 kg of steel (5% wastage) and 2.5 kg of zinc. An order for 500 Nos requires
 * steel 20 × 1.05 × 5 = 105 kg and zinc 2.5 × 5 = 12.5 kg. Steel is stocked 200 kg at ₹50, zinc 50 kg at ₹80: issuing 105 and 12.5 takes ₹5,250 + ₹1,000 = ₹6,250 of material.
 * Reporting 480 good (and 20 rejected) values the 480 at 6,250 ÷ 480 = ₹13.0208 each (₹6,249.98 in stock) and gives a yield of 480 ÷ 500 = 96%.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->pm = makeUser(['Production Manager'], [$this->acme]);
    $this->pm2 = makeUser(['Production Manager'], [$this->acme]);
    $this->ahdPm = makeUser(['Production Manager'], [$this->acme], [$this->ahd]);   // works in Ahmedabad only
    $this->op = makeUser(['Production Operator'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme]);
    $this->outsider = makeUser(['Production Manager'], [$this->globex]);
    $purchase = makeUser(['Purchase User'], [$this->acme]);

    $make = fn (string $name, string $unit = 'NOS', array $inv = [], array $item = []) => makeItem($this->acme, $purchase, ['item' => ['name' => $name, 'base_unit_id' => (string) unitId($this->acme, $unit), ...$item], 'uoms' => [], 'inventory' => $inv], 'approved');
    $this->bolt = $make('Hex Bolt M10');
    $this->steel = $make('Steel rod', 'KG');
    $this->zinc = $make('Zinc', 'KG');
    $this->box = $make('Carton', 'NOS');
    $this->pack = $make('Bolt pack of 10');
    $this->drug = $make('Tablet 500', 'NOS', ['tracking' => 'batch', 'has_expiry' => true]);
    $this->resin = $make('Resin', 'KG', ['tracking' => 'batch']);

    $this->do = fn (callable $call) => inCompany($this->acme, $call);
    $this->as = function ($user) {
        $this->actingAs($user);
        app(TenantContext::class)->flush();

        return $user;
    };
    $this->stock = fn ($item, string $qty, string $cost, $batch = null, int $line = 1) => ($this->do)(fn () => app(StockService::class)->receive($item, $this->ahd->id, $qty, $cost, $batch, 'test', 1, $line));
    $this->onHand = fn ($item, $loc = null) => DB::table('stock_balances')->where('item_id', $item->id)->where('location_id', ($loc ?? $this->ahd)->id)->first();

    $this->bomInput = fn ($finished, array $lines, array $over = []) => ['code' => 'BOM-BOLT', 'item_id' => (string) $finished->id, 'output_quantity' => '100', 'remarks' => '', 'items' => $lines, ...$over];
    $this->line = fn ($item, string $qty, string $scrap = '0') => ['item_id' => (string) $item->id, 'quantity' => $qty, 'scrap_percent' => $scrap];
    $this->makeBom = fn (array $input, $by = null) => ($this->do)(function () use ($input, $by) {
        ($this->as)($by ?? $this->pm);

        return app(BomService::class)->create($input, $by ?? $this->pm);
    });
    $this->approveBom = fn (Bom $bom, $by = null) => approveDocument($this->acme, $bom, $this->pm, $by ?? $this->approver);
    $this->boltBom = function () {
        static $bom = null;   // one bill per test: the same code cannot be made twice

        return $bom ??= ($this->approveBom)(($this->makeBom)(($this->bomInput)($this->bolt, [($this->line)($this->steel, '20', '5'), ($this->line)($this->zinc, '2.5')])));
    };
    $this->order = fn (Bom $bom, string $qty = '500', array $over = [], $by = null) => ($this->do)(function () use ($bom, $qty, $over, $by) {
        ($this->as)($by ?? $this->pm);

        return app(ProductionOrderService::class)->create(['location_id' => (string) $this->ahd->id, 'bom_id' => (string) $bom->id, 'planned_quantity' => $qty, 'planned_start' => '2026-10-12', 'planned_end' => '2026-10-16', ...$over], $by ?? $this->pm);
    });
    $this->released = function (string $qty = '500') {
        $order = ($this->order)(($this->boltBom)(), $qty);

        return ($this->do)(fn () => app(ProductionOrderService::class)->release($order, $this->pm));
    };
    $this->service = fn () => app(ProductionOrderService::class);
    $this->component = fn (ProductionOrder $o, $item) => $o->components()->where('item_id', $item->id)->firstOrFail();
    $this->issue = fn (ProductionOrder $o, $item, string $qty, ?string $key = null, $batch = null) => ($this->do)(fn () => ($this->service)()->issue($o->fresh(), ($this->component)($o, $item)->id, $batch?->id, $qty, $key ?? bin2hex(random_bytes(8)), $this->op));
    $this->output = fn (ProductionOrder $o, array $in, ?string $key = null) => ($this->do)(fn () => ($this->service)()->recordOutput($o->fresh(), $in, $key ?? bin2hex(random_bytes(8)), $this->op));
    $this->figures = fn (ProductionOrder $o) => ($this->do)(fn () => app(ProductionFigures::class)->forOrder($o->fresh()));
});

afterEach(fn () => Carbon::setTestNow());

// ---- bills of materials ------------------------------------------------------------------------------------------------------------

it('makes a bill of materials in base units and approves it by another person, then locks it', function () {
    $bom = ($this->makeBom)(($this->bomInput)($this->bolt, [($this->line)($this->steel, '20', '5'), ($this->line)($this->zinc, '2.5')]));

    expect($bom->code)->toBe('BOM-BOLT')->and($bom->approval_status->value)->toBe('draft')->and((string) $bom->output_quantity)->toBe('100.000')
        ->and($bom->components)->toHaveCount(2)->and((string) $bom->components[0]->scrap_percent)->toBe('5.00')->and((string) $bom->components[1]->quantity)->toBe('2.500');

    expect(fn () => approveDocument($this->acme, $bom, $this->pm, $this->pm))->toThrow(Exception::class);   // the maker cannot approve it

    ($this->do)(fn () => app(ApprovalService::class)->approve($bom->fresh(), $this->pm2));
    $approved = $bom->fresh();
    expect($approved->isUsable())->toBeTrue()
        ->and(fn () => Bom::withoutGlobalScopes(Tenancy::SCOPES)->find($bom->id)->forceFill(['output_quantity' => '5'])->save())->toThrow(LogicException::class)
        ->and(fn () => BomItem::withoutGlobalScopes(Tenancy::SCOPES)->where('bom_id', $bom->id)->first()->forceFill(['quantity' => '1'])->save())->toThrow(LogicException::class)
        ->and(fn () => ($this->do)(fn () => app(BomService::class)->update($approved, ($this->bomInput)($this->bolt, [($this->line)($this->steel, '1')]), $this->pm)))->toThrow(FieldRuleException::class);

    // an inactive bill cannot start an order, and can be switched back on
    ($this->do)(fn () => app(BomService::class)->setStatus($approved, false, $this->pm));
    expect($approved->fresh()->isUsable())->toBeFalse();
    ($this->do)(fn () => app(BomService::class)->setStatus($approved, true, $this->pm));
    expect($approved->fresh()->isUsable())->toBeTrue();
});

it('refuses a bill that is not sensible', function () {
    $try = fn (array $input) => fn () => ($this->makeBom)($input);
    $ok = [($this->line)($this->steel, '20')];
    $draft = makeItem($this->acme, makeUser(['Purchase User'], [$this->acme]), ['item' => ['name' => 'Not yet approved']]);

    expect($try(($this->bomInput)($this->bolt, $ok, ['code' => 'x'])))->toThrow(ValidationException::class)                       // code too short / shape
        ->and($try(($this->bomInput)($this->bolt, [])))->toThrow(ValidationException::class)                                          // no components
        ->and($try(($this->bomInput)($this->bolt, [($this->line)($this->bolt, '1')])))->toThrow(ValidationException::class)         // itself
        ->and($try(($this->bomInput)($this->bolt, [($this->line)($this->steel, '1'), ($this->line)($this->steel, '2')])))->toThrow(ValidationException::class)   // twice
        ->and($try(($this->bomInput)($this->bolt, [($this->line)($this->steel, '0')])))->toThrow(ValidationException::class)
        ->and($try(($this->bomInput)($this->bolt, [($this->line)($this->steel, '1.5555')])))->toThrow(ValidationException::class)
        ->and($try(($this->bomInput)($this->bolt, [($this->line)($this->steel, '1', '60')])))->toThrow(ValidationException::class)     // wastage over 50%
        ->and($try(($this->bomInput)($draft, $ok)))->toThrow(ValidationException::class)                                               // an unapproved item
        ->and($try(($this->bomInput)($this->bolt, $ok, ['output_quantity' => '0'])))->toThrow(ValidationException::class)
        ->and(Bom::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);

    ($this->makeBom)(($this->bomInput)($this->bolt, $ok));
    expect($try(($this->bomInput)($this->bolt, $ok)))->toThrow(ValidationException::class);                                            // the code is taken
});

it('refuses a bill that would go round in a circle, through another bill', function () {
    ($this->makeBom)(($this->bomInput)($this->pack, [($this->line)($this->bolt, '10')], ['code' => 'BOM-PACK', 'output_quantity' => '1']));   // a pack is made of bolts

    // bolts made from a pack: a loop through the pack's bill
    $loop = fn () => ($this->makeBom)(($this->bomInput)($this->bolt, [($this->line)($this->pack, '1')], ['code' => 'BOM-LOOP']));

    expect($loop)->toThrow(ValidationException::class, 'circle');
});

it('explodes a bill down to raw materials through a component that has a bill of its own', function () {
    ($this->boltBom)();   // 100 bolts = 21 kg steel (20 + 5%) and 2.5 kg zinc
    $pack = ($this->makeBom)(($this->bomInput)($this->pack, [($this->line)($this->bolt, '10'), ($this->line)($this->box, '1')], ['code' => 'BOM-PACK', 'output_quantity' => '1']));

    $needs = app(BomService::class)->explode($pack);   // one pack = 10 bolts = 0.1 of a bill: steel 2.1, zinc 0.25, and one carton

    expect($needs[$this->steel->id]['quantity'])->toBe('2.100')->and($needs[$this->zinc->id]['quantity'])->toBe('0.250')->and($needs[$this->box->id]['quantity'])->toBe('1.000')
        ->and(isset($needs[$this->bolt->id]))->toBeFalse()
        ->and(app(BomService::class)->explode($pack, '5')[$this->steel->id]['quantity'])->toBe('10.500');
});

// ---- production orders ---------------------------------------------------------------------------------------------------------------

it('plans an order, copying what it requires from the bill with the wastage', function () {
    $order = ($this->order)(($this->boltBom)());

    expect($order->order_number)->toBe('MO/AHD/26-27/00001')->and($order->state)->toBe('draft')->and($order->item_name)->toBe('Hex Bolt M10')->and((string) $order->planned_quantity)->toBe('500.000')
        ->and((string) ($this->component)($order, $this->steel)->required_quantity)->toBe('105.000')->and((string) ($this->component)($order, $this->zinc)->required_quantity)->toBe('12.500');

    // a later change of the bill moves nothing already planned: the bill is locked, so a new one is made — the order keeps its copy
    $changed = ($this->do)(fn () => app(ProductionOrderService::class)->update($order, ['location_id' => (string) $this->ahd->id, 'bom_id' => (string) $order->bom_id, 'planned_quantity' => '250', 'planned_start' => '2026-10-13', 'planned_end' => '2026-10-14'], $this->pm));
    expect($changed->order_number)->toBe($order->order_number)->and((string) ($this->component)($changed, $this->steel)->required_quantity)->toBe('52.500')->and($changed->components()->count())->toBe(2);
});

it('refuses an order that is not sensible', function () {
    $bom = ($this->boltBom)();
    $draftBom = ($this->makeBom)(($this->bomInput)($this->pack, [($this->line)($this->box, '1')], ['code' => 'BOM-DRAFT']));
    $try = fn (Bom $b, string $qty = '10', array $over = [], $by = null) => fn () => ($this->order)($b, $qty, $over, $by);

    expect($try($bom, '0'))->toThrow(ValidationException::class)
        ->and($try($bom, '1.2345'))->toThrow(ValidationException::class)
        ->and($try($draftBom))->toThrow(ValidationException::class)                                                     // not approved
        ->and($try($bom, '10', ['planned_end' => '2026-10-01']))->toThrow(ValidationException::class)                    // finishes before it starts
        ->and($try($bom, '10', ['location_id' => '999999']))->toThrow(ValidationException::class)
        ->and($try($bom, '10', ['location_id' => (string) $this->mum->id], $this->ahdPm))->toThrow(ValidationException::class)   // a branch the person does not work in
        ->and(ProductionOrder::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('takes material out of stock at the average, up to what is required plus the allowance, and only once for one entry', function () {
    ($this->stock)($this->steel, '200', '50');
    ($this->stock)($this->zinc, '50', '80', null, 2);
    $order = ($this->released)();

    // an unreleased order issues nothing
    $draft = ($this->order)(($this->boltBom)());
    expect(fn () => ($this->issue)($draft, $this->steel, '10'))->toThrow(FieldRuleException::class, 'released');

    $first = ($this->issue)($order, $this->steel, '105', 'key-steel-0001');
    ($this->issue)($order, $this->zinc, '12.5');
    $again = ($this->issue)($order, $this->steel, '105', 'key-steel-0001');       // a double click: the same entry

    expect($again->id)->toBe($first->id)->and(ProductionIssue::withoutGlobalScopes(Tenancy::SCOPES)->where('production_order_id', $order->id)->count())->toBe(2)
        ->and((string) ($this->onHand)($this->steel)->quantity)->toBe('95.000')->and((string) ($this->onHand)($this->steel)->value)->toBe('4750.00')
        ->and(($this->do)(fn () => app(ProductionFigures::class)->materialValue($order->fresh())))->toBe('6250.00')
        ->and($order->fresh()->state)->toBe('in_progress');

    // the allowance is 10%: 105 × 1.10 = 115.5, so 10.5 more at most
    $f = ($this->figures)($order);
    expect(collect($f['components'])->firstWhere('item_id', $this->steel->id)['allowed_more'])->toBe('10.500')
        ->and(fn () => ($this->issue)($order, $this->steel, '11'))->toThrow(ValidationException::class, 'at most 10.5')
        ->and(($this->issue)($order, $this->steel, '10.5')->quantity)->toBe('10.500')
        ->and(fn () => ($this->issue)($order, $this->steel, '0.001'))->toThrow(ValidationException::class, 'already been issued');

    // more than is on hand is the stock's refusal, and leaves no entry behind
    ($this->do)(fn () => app(StockService::class)->adjustOut($this->zinc, $this->ahd->id, '37.5', null, 'Used elsewhere in a test', $this->pm));
    $before = ProductionIssue::withoutGlobalScopes(Tenancy::SCOPES)->count();
    expect(fn () => ($this->issue)($order, $this->zinc, '0.5'))->toThrow(StockException::class, 'on hand')
        ->and(ProductionIssue::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe($before)
        ->and(fn () => ProductionIssue::withoutGlobalScopes(Tenancy::SCOPES)->first()->forceFill(['quantity' => '1'])->save())->toThrow(LogicException::class)
        ->and(fn () => ProductionIssue::withoutGlobalScopes(Tenancy::SCOPES)->first()->delete())->toThrow(LogicException::class);
});

it('receives the good units at the material cost, records rejects, and works out yield and variance', function () {
    ($this->stock)($this->steel, '200', '50');
    ($this->stock)($this->zinc, '50', '80', null, 2);
    $order = ($this->released)();

    // nothing issued yet: the goods cannot be valued
    expect(fn () => ($this->output)($order, ['good_quantity' => '10']))->toThrow(FieldRuleException::class, 'Issue the material first');

    ($this->issue)($order, $this->steel, '105');
    ($this->issue)($order, $this->zinc, '12.5');

    // rejects need a reason; the totals may not pass planned + 10%
    expect(fn () => ($this->output)($order, ['good_quantity' => '5', 'rejected_quantity' => '1']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->output)($order, ['good_quantity' => '551']))->toThrow(ValidationException::class, 'at most 550')
        ->and(fn () => ($this->output)($order, []))->toThrow(ValidationException::class);

    $out = ($this->output)($order, ['good_quantity' => '480', 'rejected_quantity' => '20', 'reject_reason' => 'Thread damaged'], 'out-key-00001');
    $again = ($this->output)($order, ['good_quantity' => '480', 'rejected_quantity' => '20', 'reject_reason' => 'Thread damaged'], 'out-key-00001');

    expect($again->id)->toBe($out->id)->and((string) $out->unit_cost)->toBe('13.0208')
        ->and((string) ($this->onHand)($this->bolt)->quantity)->toBe('480.000')->and((string) ($this->onHand)($this->bolt)->value)->toBe('6249.98')
        ->and(ProductionOutput::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(1);

    $f = ($this->figures)($order);
    $steel = collect($f['components'])->firstWhere('item_id', $this->steel->id);
    expect($f['good'])->toBe('480.000')->and($f['rejected'])->toBe('20.000')->and($f['yield'])->toBe('96.00')->and($f['still_to_make'])->toBe('20.000')
        ->and($f['material_value'])->toBe('6250.00')->and($f['unit_cost'])->toBe('13.0208')
        ->and($steel['standard_for_actual'])->toBe('105.000')->and($steel['variance'])->toBe('0.000')   // 500 made (480 + 20) of 500 planned: exactly the standard
        ->and($f['allowed_more_output'])->toBe('50.000');
});

it('completes only with output, closes short with a note, and cancels only before any material is issued', function () {
    ($this->stock)($this->steel, '200', '50');
    ($this->stock)($this->zinc, '50', '80', null, 2);
    $order = ($this->released)();
    $svc = ($this->service);

    expect(fn () => ($this->do)(fn () => $svc()->complete($order->fresh(), '', $this->pm)))->toThrow(FieldRuleException::class, 'Nothing has been reported');

    ($this->issue)($order, $this->steel, '105');
    ($this->issue)($order, $this->zinc, '12.5');
    expect(fn () => ($this->do)(fn () => $svc()->cancel($order->fresh(), 'No longer needed', $this->pm)))->toThrow(FieldRuleException::class, 'complete it instead');

    ($this->output)($order, ['good_quantity' => '480', 'rejected_quantity' => '20', 'reject_reason' => 'Damaged']);
    expect(fn () => ($this->do)(fn () => $svc()->complete($order->fresh(), '', $this->pm)))->toThrow(ValidationException::class, 'closed short');

    $done = ($this->do)(fn () => $svc()->complete($order->fresh(), 'Machine breakdown', $this->pm));
    expect($done->state)->toBe('completed')->and($done->completion_note)->toBe('Machine breakdown')
        ->and(fn () => ($this->issue)($done, $this->steel, '1'))->toThrow(FieldRuleException::class)
        ->and(fn () => ($this->output)($done, ['good_quantity' => '1']))->toThrow(FieldRuleException::class)
        ->and(fn () => ProductionOrder::withoutGlobalScopes(Tenancy::SCOPES)->find($done->id)->forceFill(['notes' => 'x'])->save())->toThrow(LogicException::class);

    // an order with no work can be cancelled, with a reason
    $fresh = ($this->order)(($this->boltBom)());
    expect(fn () => ($this->do)(fn () => $svc()->cancel($fresh->fresh(), 'x', $this->pm)))->toThrow(ValidationException::class)
        ->and(($this->do)(fn () => $svc()->cancel($fresh->fresh(), 'Customer withdrew', $this->pm))->state)->toBe('cancelled');
});

it('releases only while the bill is still approved and active', function () {
    $bom = ($this->boltBom)();
    $order = ($this->order)($bom);
    ($this->do)(fn () => app(BomService::class)->setStatus($bom, false, $this->pm));

    expect(fn () => ($this->do)(fn () => ($this->service)()->release($order->fresh(), $this->pm)))->toThrow(FieldRuleException::class, 'no longer approved')
        ->and(fn () => ($this->do)(fn () => ($this->service)()->release($order->fresh(), $this->ahdPm)))->toThrow(FieldRuleException::class);
});

it('works with batch-tracked components and finished goods', function () {
    $resinBatch = ($this->do)(function () {
        $b = new Batch;
        $b->forceFill(['company_id' => $this->acme->id, 'item_id' => $this->resin->id, 'batch_no' => 'R-1', 'status' => 'active'])->save();

        return $b;
    });
    ($this->stock)($this->resin, '100', '10', $resinBatch);
    $bom = ($this->approveBom)(($this->makeBom)(($this->bomInput)($this->drug, [($this->line)($this->resin, '1')], ['code' => 'BOM-TAB', 'output_quantity' => '1000'])));
    $order = ($this->do)(fn () => ($this->service)()->release(($this->order)($bom, '2000'), $this->pm));   // requires 2 kg of resin

    // a tracked component needs its batch
    expect(fn () => ($this->issue)($order, $this->resin, '2'))->toThrow(StockException::class, 'tracked by batch');
    ($this->issue)($order, $this->resin, '2', null, $resinBatch);

    // the tablets need a batch and an expiry
    expect(fn () => ($this->output)($order, ['good_quantity' => '2000']))->toThrow(ValidationException::class, 'batch')
        ->and(fn () => ($this->output)($order, ['good_quantity' => '2000', 'batch_no' => 'T-1']))->toThrow(ValidationException::class, 'expiry');

    $out = ($this->output)($order, ['good_quantity' => '2000', 'batch_no' => 't-1', 'expiry_date' => '2028-09-30']);
    $batch = Batch::withoutGlobalScopes(Tenancy::SCOPES)->find($out->batch_id);

    expect($batch->batch_no)->toBe('T-1')->and($batch->expiry_date->toDateString())->toBe('2028-09-30')->and((string) ($this->onHand)($this->drug)->quantity)->toBe('2000.000')
        ->and((string) $out->unit_cost)->toBe('0.0100');   // ₹20 of resin over 2,000 tablets
});

// ---- who may, and the screens --------------------------------------------------------------------------------------------------------

it('keeps planning to the planners, the shop floor to its operators, and other companies out', function () {
    $bom = ($this->boltBom)();
    $order = ($this->order)($bom);
    $can = fn ($user, string $ability, $model) => inCompany($this->acme, fn () => ($this->as)($user)->can($ability, $model));

    expect($can($this->op, 'view', $order))->toBeTrue()->and($can($this->op, 'create', ProductionOrder::class))->toBeFalse()->and($can($this->op, 'release', $order))->toBeFalse()
        ->and($can($this->pm, 'release', $order))->toBeTrue()->and($can($this->bde, 'view', $order))->toBeFalse()->and($can($this->bde, 'view', $bom))->toBeFalse()
        ->and($can($this->pm, 'update', $order))->toBeTrue();

    $released = ($this->do)(fn () => ($this->service)()->release($order, $this->pm));
    expect($can($this->op, 'execute', $released))->toBeTrue()->and($can($this->pm, 'update', $released))->toBeFalse()   // only a draft can be edited
        ->and($can($this->pm, 'execute', $order->fresh()))->toBeTrue();

    $this->actingAs($this->outsider)->get(route('production.orders.show', $order))->assertNotFound();
    $this->actingAs($this->bde)->get(route('production.orders.index'))->assertForbidden();
});

it('shows the bill screens and saves a bill from the form', function () {
    $bom = ($this->boltBom)();
    ($this->as)($this->pm);

    Livewire::actingAs($this->pm)->test(BomList::class)->assertSee('BOM-BOLT')->assertSee('Hex Bolt M10')->set('search', 'nothing-like-this')->assertDontSee('BOM-BOLT');
    Livewire::actingAs($this->pm)->test(BomShow::class, ['bom' => $bom])->assertSee('Down to raw materials')->assertSee('Steel rod')->assertSee('21 KG');

    $form = Livewire::actingAs($this->pm)->test(BomForm::class)
        ->set('code', 'bom-pack')->call('pickItem', $this->pack->id)->set('outputQuantity', '1')
        ->call('pickComponent', 0, $this->bolt->id)->set('rows.0.quantity', '10')->call('save')->assertHasNoErrors();

    $made = Bom::withoutGlobalScopes(Tenancy::SCOPES)->where('code', 'BOM-PACK')->firstOrFail();
    expect($made->components()->count())->toBe(1);
    $form->assertRedirect(route('production.boms.show', $made));
});

it('shows the order screens, plans an order from the form, and runs the shop floor from the order page', function () {
    ($this->stock)($this->steel, '200', '50');
    ($this->stock)($this->zinc, '50', '80', null, 2);
    $bom = ($this->boltBom)();
    ($this->as)($this->pm);

    Livewire::actingAs($this->pm)->test(ProductionOrderForm::class)->set('bomId', (string) $bom->id)->set('locationId', (string) $this->ahd->id)->set('plannedQuantity', '500')
        ->set('plannedStart', '2026-10-12')->set('plannedEnd', '2026-10-16')->call('save')->assertHasNoErrors();
    $order = ProductionOrder::withoutGlobalScopes(Tenancy::SCOPES)->firstOrFail();

    Livewire::actingAs($this->pm)->test(ProductionOrderList::class)->assertSeeHtml('wire:key="mo-'.$order->id.'"')->assertSee('Draft')->set('state', 'completed')->assertDontSeeHtml('wire:key="mo-'.$order->id.'"');

    Livewire::actingAs($this->pm)->test(ProductionOrderShow::class, ['order' => $order])->assertSee('Release to the shop floor')->call('release')->assertHasNoErrors();
    expect($order->fresh()->state)->toBe('released');

    $page = fn () => Livewire::actingAs($this->op)->test(ProductionOrderShow::class, ['order' => $order->fresh()]);
    $component = ($this->component)($order, $this->steel)->id;

    $page()->assertSee('Issue material')->set('componentId', (string) $component)->set('issueQuantity', '105')->call('issueMaterial')->assertHasNoErrors();
    $page()->set('componentId', (string) $component)->set('issueQuantity', '500')->call('issueMaterial')->assertHasErrors('quantity');
    $page()->set('goodQuantity', '480')->set('rejectedQuantity', '20')->set('rejectReason', 'Damaged')->call('reportOutput')->assertHasNoErrors();

    expect(DB::table('production_issues')->where('production_order_id', $order->id)->count())->toBe(1)->and((string) ($this->onHand)($this->bolt)->quantity)->toBe('480.000');

    // the shop floor cannot plan, release, complete or cancel
    Livewire::actingAs($this->op)->test(ProductionOrderShow::class, ['order' => $order->fresh()])->call('openDialog', 'complete')->assertForbidden();
    Livewire::actingAs($this->pm)->test(ProductionOrderShow::class, ['order' => $order->fresh()])->call('openDialog', 'complete')->set('note', 'Short')->call('finishOrder')->assertHasNoErrors();
    expect($order->fresh()->state)->toBe('completed');
});

it('opens every production page for a manager', function () {
    $bom = ($this->boltBom)();
    $order = ($this->order)($bom);

    foreach (['/production/boms', '/production/boms/create', '/production/boms/'.$bom->id, '/production/orders', '/production/orders/create', '/production/orders/'.$order->id, '/production/orders/'.$order->id.'/edit'] as $url) {
        $this->actingAs($this->pm)->get($url)->assertOk();
    }
});

it('lists production orders in the reports, with the same figures as the order page', function () {
    ($this->stock)($this->steel, '200', '50');
    ($this->stock)($this->zinc, '50', '80', null, 2);
    $order = ($this->released)();
    ($this->issue)($order, $this->steel, '105');
    ($this->issue)($order, $this->zinc, '12.5');
    ($this->output)($order, ['good_quantity' => '480', 'rejected_quantity' => '20', 'reject_reason' => 'Damaged']);

    $report = app(ReportCatalog::class)->find('production-order-register');
    $rows = ($this->do)(function () use ($report) {
        ($this->as)($this->pm);
        $input = ReportInput::resolve($report, [], $this->pm);

        return iterator_to_array($report->rows($input, $this->pm), false);
    });

    expect($report->canRun($this->pm))->toBeTrue()->and($report->canRun($this->bde))->toBeFalse()->and($rows)->toHaveCount(1)
        ->and($rows[0]['order_number'])->toBe($order->order_number)->and($rows[0]['good_text'])->toBe('480')->and($rows[0]['rejected_text'])->toBe('20')->and($rows[0]['yield_text'])->toBe('96.00%')
        ->and(($this->figures)($order)['yield'])->toBe('96.00');
});

<?php

use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Loss\Http\Livewire\RadarFindingList;
use App\Modules\Loss\Models\RadarFinding;
use App\Modules\Loss\Radar\RadarService;
use App\Modules\Loss\Services\LossFigures;
use App\Modules\Loss\Services\LossIncidentService;
use App\Modules\Procurement\Services\GrnService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use Database\Seeders\IndustryTemplateSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Profit Leakage Radar: detectors propose findings from stock and receipts, valued by the loss formulas (hand-calculated here), once per source
 * record, never counted as loss until a person reports them, dismissable with a reason, isolated by company.
 * Hand-calculated: 40 scrapped at an average ₹5 = ₹200; 50 expired at ₹8 = ₹400; 5 bolts rejected at the PO's ₹4.50 = ₹22.50. Minimum set to ₹10.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();
    $this->seed(IndustryTemplateSeeder::class);

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->manager = makeUser(['Quality/Loss Manager'], [$this->acme]);
    $this->store = makeUser(['Inventory User'], [$this->acme], [$this->ahd]);
    $this->purchase = makeUser(['Purchase User'], [$this->acme]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->outsider = makeUser(['Quality/Loss Manager'], [$this->globex]);

    $this->actingAs($this->admin);
    $this->auto = IndustryTemplate::query()->where('code', 'automotive')->firstOrFail();
    app(IndustryTemplateService::class)->enable($this->auto, $this->admin);
    app(SettingsService::class)->set('radar.min_amount', 10, $this->acme->id);

    $this->supplier = makeLedger($this->acme, $this->purchase, 'supplier', ['ledger' => ['name' => 'Steel Traders']], true, $this->accounts);
    $this->bolt = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'MS Hex Bolt M10', 'standard_cost' => '4']], 'approved');
    $this->drug = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Paracetamol 500'], 'uoms' => [], 'inventory' => ['tracking' => 'batch', 'has_expiry' => true]], 'approved');

    $this->do = fn (callable $call) => inCompany($this->acme, $call);
    $this->sweep = fn () => app(RadarService::class)->sweep();
    $this->findings = fn () => RadarFinding::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->orderBy('detector')->get()->keyBy('detector');

    // 40 scrapped at ₹5 (a movement 2026-10-12) and 50 of a batch that expires 2026-11-01, received today
    $this->scrap = ($this->do)(function () {
        $this->actingAs($this->admin);
        app(StockService::class)->receive($this->bolt, $this->ahd->id, '100', '5', null, 'test', 1, 1, $this->admin);

        return app(StockService::class)->scrap($this->bolt, $this->ahd->id, '40', null, 'Crushed by the forklift', $this->admin);
    });
    $this->batch = ($this->do)(function () {
        $batch = new Batch;
        $batch->forceFill(['company_id' => $this->acme->id, 'item_id' => $this->drug->id, 'batch_no' => 'P-1', 'expiry_date' => '2026-11-01', 'status' => 'active'])->save();
        app(StockService::class)->receive($this->drug, $this->ahd->id, '50', '8', $batch, 'test', 1, 2, $this->admin);

        return $batch;
    });
});

afterEach(fn () => Carbon::setTestNow());

it('proposes a finding for scrap, and for expired stock once it has expired, valued by the loss formulas', function () {
    $first = ($this->sweep)();
    expect($first['new'])->toBe(1)->and(($this->findings)()->keys()->all())->toBe(['scrap']);   // the batch has not expired yet

    $scrap = ($this->findings)()['scrap'];
    expect($scrap->estimated_amount)->toBe('200.00')->and($scrap->impact_type)->toBe('actual')->and($scrap->severity)->toBe('high')   // 200 / 10 = 20 times the minimum
        ->and($scrap->state)->toBe('open')->and($scrap->source_type)->toBe('stock_movement')->and($scrap->source_id)->toBe($this->scrap->id)
        ->and($scrap->evidence['formula_key'])->toBe('material_cost')->and($scrap->evidence['inputs'])->toMatchArray(['quantity' => '40', 'unit_cost' => '5'])
        ->and($scrap->evidence['method'])->not->toBe('')->and($scrap->evidence['assumptions'])->toContain('moving-average')->and($scrap->evidence['data_as_of'])->toContain('2026-10-12');

    Carbon::setTestNow('2026-11-05 09:30:00');
    ($this->sweep)();

    $expired = ($this->findings)()['expired_stock'];
    expect($expired->estimated_amount)->toBe('400.00')->and($expired->source_type)->toBe('batch')->and($expired->source_id)->toBe($this->batch->id)
        ->and($expired->evidence['formula_key'])->toBe('expiry');
});

it('proposes a finding for goods rejected on an approved receipt, as an exposure at the order price', function () {
    $po = ($this->do)(fn () => app(PurchaseOrderService::class)->create(['location_id' => (string) $this->ahd->id, 'supplier_ledger_id' => (string) $this->supplier->id,
        'items' => [['item_id' => (string) $this->bolt->id, 'unit_id' => (string) unitId($this->acme, 'NOS'), 'quantity' => '100', 'rate' => '4.5']]], $this->purchase));
    $po = approveDocument($this->acme, $po, $this->purchase, $this->accounts);
    $line = $po->items()->first();
    $grn = ($this->do)(fn () => app(GrnService::class)->create($po->fresh(), ['lines' => [$line->id => ['accepted' => '90', 'rejected' => '5', 'reject_reason' => 'Rusted threads']]], $this->store));
    approveDocument($this->acme, $grn, $this->store, $this->accounts);

    ($this->sweep)();
    $f = ($this->findings)()['rejected_receipt'];

    expect($f->estimated_amount)->toBe('22.50')->and($f->impact_type)->toBe('exposure')->and($f->severity)->toBe('low')->and($f->source_type)->toBe('grn_item')
        ->and($f->evidence['assumptions'])->toContain('Rusted threads');
});

it('keeps one finding per record however often it sweeps, and ignores what is below the minimum', function () {
    ($this->sweep)();
    $again = ($this->sweep)();

    expect($again['new'])->toBe(0)->and($again['refreshed'])->toBe(1)->and(RadarFinding::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(1);

    app(SettingsService::class)->set('radar.min_amount', 500, $this->acme->id);
    Carbon::setTestNow('2026-10-12 11:30:00');   // a later sweep: what it does not see again was last seen earlier
    $third = ($this->sweep)();

    expect($third['resolved'])->toBe(1)->and(($this->findings)()['scrap']->state)->toBe('resolved');   // ₹200 is now below the line

    app(SettingsService::class)->set('radar.min_amount', 10, $this->acme->id);
    ($this->sweep)();
    expect(($this->findings)()['scrap']->state)->toBe('open')->and(RadarFinding::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(1);
});

it('does nothing for a company that has switched the Radar off, and a finding ages out of the look-back', function () {
    app(SettingsService::class)->set('radar.enabled', false, $this->acme->id);
    expect(($this->sweep)()['new'])->toBe(0)->and(RadarFinding::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);

    app(SettingsService::class)->set('radar.enabled', true, $this->acme->id);
    ($this->sweep)();
    Carbon::setTestNow('2026-12-12 09:30:00');   // 61 days later: the scrap is outside the 30-day look-back
    ($this->sweep)();

    expect(($this->findings)()['scrap']->state)->toBe('resolved');
});

it('creates no incident and adds nothing to the loss figures until a person reports it', function () {
    ($this->sweep)();
    $figures = fn () => ($this->do)(fn () => app(LossFigures::class)->forPeriod(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')));

    expect($figures()['actual_loss'])->toBe('0.00')->and($figures()['incidents'])->toBe(0);

    // a person reports it from the proposal's source; the finding then follows the incident and is never proposed again
    $category = LossCategory::query()->where('industry_template_id', $this->auto->id)->where('code', 'line_stoppage')->firstOrFail();
    $this->actingAs($this->manager);
    $incident = app(LossIncidentService::class)->create([
        'industry_template_id' => (string) $this->auto->id, 'loss_category_id' => (string) $category->id, 'location_id' => (string) $this->ahd->id, 'occurred_at' => '2026-10-12T08:00',
        'severity' => 'medium', 'title' => 'Scrap', 'description' => 'Crushed by the forklift', 'custom_fields' => ['line' => 'Press line 3', 'shift' => 'C', 'downtime_hours' => '1'],
        'source_type' => 'stock_movement', 'source_id' => (string) $this->scrap->id,
    ], $this->manager);

    $again = ($this->sweep)();
    $f = ($this->findings)()['scrap'];

    expect($f->state)->toBe('reported')->and($f->loss_incident_id)->toBe($incident->id)->and($again['new'])->toBe(0)->and($figures()['actual_loss'])->toBe('0.00');   // still not validated
});

it('lets someone with the right dismiss an open finding with a reason, once, for good', function () {
    ($this->sweep)();
    $finding = ($this->findings)()['scrap'];

    $this->actingAs($this->store);
    expect(fn () => app(RadarService::class)->dismiss($finding->fresh(), 'Stock was transferred, not lost', $this->store))->toThrow(AuthorizationException::class);   // may not even see it

    $this->actingAs($this->manager);
    expect(fn () => app(RadarService::class)->dismiss($finding->fresh(), 'too short', $this->manager))->toThrow(ValidationException::class);

    app(RadarService::class)->dismiss($finding->fresh(), 'A known test batch, not a real loss', $this->manager);
    $fresh = $finding->fresh();

    expect($fresh->state)->toBe('dismissed')->and($fresh->dismissed_by)->toBe($this->manager->id)->and($fresh->dismiss_reason)->toBe('A known test batch, not a real loss')
        ->and(fn () => app(RadarService::class)->dismiss($finding->fresh(), 'Dismissing a second time here', $this->manager))->toThrow(ValidationException::class);

    ($this->sweep)();
    expect($finding->fresh()->state)->toBe('dismissed')->and(RadarFinding::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(1);
});

it('shows findings to those who may see them, only for their own company, and lets them dismiss from the screen', function () {
    ($this->sweep)();
    $finding = ($this->findings)()['scrap'];

    Livewire::actingAs($this->store)->test(RadarFindingList::class)->assertForbidden();
    Livewire::actingAs($this->outsider)->test(RadarFindingList::class)->assertDontSeeHtml('wire:key="rf-'.$finding->id.'"');

    Livewire::actingAs($this->manager)->test(RadarFindingList::class)
        ->assertSeeHtml('wire:key="rf-'.$finding->id.'"')->assertSee('Evidence and calculation')->assertSee('200.00')
        ->call('askDismiss', $finding->id)->set('reason', 'short')->call('dismissFinding')->assertHasErrors('reason')
        ->set('reason', 'Counted and put back on the shelf')->call('dismissFinding')->assertHasNoErrors();

    expect($finding->fresh()->state)->toBe('dismissed');
});

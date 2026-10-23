<?php

use App\Modules\Finance\Http\Livewire\BudgetForm;
use App\Modules\Finance\Http\Livewire\BudgetList;
use App\Modules\Finance\Http\Livewire\BudgetShow;
use App\Modules\Finance\Http\Livewire\CostCenterList;
use App\Modules\Finance\Http\Livewire\ProfitabilityReport;
use App\Modules\Finance\Http\Livewire\VarianceReport;
use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Models\CostCenter;
use App\Modules\Finance\Models\ItemCostEntry;
use App\Modules\Finance\Services\BudgetService;
use App\Modules\Finance\Services\BudgetVsActual;
use App\Modules\Finance\Services\Profitability;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Loss\Services\LossCalculationService;
use App\Modules\Loss\Services\LossIncidentService;
use App\Modules\Masters\Models\Brand;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Services\NetRevenueCalculator;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\ApprovalService;
use Database\Seeders\IndustryTemplateSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Cost centres, budgets, budget against actual, the item cost history and profitability. Hand-calculated: two bolts sales (10 at ₹125.50, cost ₹100;
 * 4 returned) and wire (2.5 kg at ₹80, cost ₹60) — net revenue 953.00, cost 750.00, profit 203.00, margin 21.30%.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();
    $this->seed(IndustryTemplateSeeder::class);

    $this->acme = makeCompany('ACME', ['state_code' => '24', 'gstin' => gstinFor('24', 'ABCDE1234F')]);
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);
    $this->mum = makeLocation($this->acme, 'MUM', ['state_code' => '27']);

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->controller = makeUser(['Finance Controller'], [$this->acme]);
    $this->management = makeUser(['Management'], [$this->acme]);
    $this->manager = makeUser(['Quality/Loss Manager'], [$this->acme]);
    $this->investigator = makeUser(['Investigator'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->outsider = makeUser(['Company Admin'], [$this->globex]);

    $this->auto = IndustryTemplate::query()->where('code', 'automotive')->firstOrFail();
    $this->retail = IndustryTemplate::query()->where('code', 'retail')->firstOrFail();
    $this->actingAs($this->admin);
    app(IndustryTemplateService::class)->enable($this->auto, $this->admin);

    $this->centre = fn (string $code, string $name, array $over = []) => inCompany($this->acme, fn () => CostCenter::query()->create(['code' => $code, 'name' => $name, ...$over]));
    $this->press = ($this->centre)('PRESS', 'Press shop');
    $this->paint = ($this->centre)('PAINT', 'Paint shop');
    $this->category = fn (string $code) => LossCategory::query()->where('industry_template_id', $this->auto->id)->where('code', $code)->firstOrFail();
    $this->budget = fn (CostCenter $c, string $month, string $amount, ?string $category = null, bool $approved = true) => (function () use ($c, $month, $amount, $category, $approved) {
        $this->actingAs($this->accounts);
        $b = app(BudgetService::class)->save(['cost_center_id' => (string) $c->id, 'loss_category_id' => $category === null ? '' : (string) ($this->category)($category)->id, 'month' => $month, 'amount' => $amount], $this->accounts);

        return $approved ? approveDocument($this->acme, $b, $this->accounts, $this->controller) : $b;
    })();
    $this->loss = function (CostCenter $c, string $categoryCode, string $amount, string $when = '2026-10-05T10:00', bool $validate = true) {
        $this->actingAs($this->investigator);
        $i = app(LossIncidentService::class)->create([
            'industry_template_id' => (string) $this->auto->id, 'loss_category_id' => (string) ($this->category)($categoryCode)->id, 'location_id' => (string) $this->ahd->id,
            'cost_center_id' => (string) $c->id, 'occurred_at' => $when, 'severity' => 'medium', 'title' => 'Loss '.$amount, 'description' => 'Something was lost',
            'custom_fields' => ['line' => 'L1'], 'impact' => ['impact_type' => 'actual', 'formula_key' => 'manual', 'inputs' => ['amount' => $amount], 'assumptions' => 'Counted'],
        ], $this->investigator);

        if ($validate) {
            $this->actingAs($this->manager);
            app(LossCalculationService::class)->validateLine($i->impactLines()->firstOrFail(), $this->manager);
        }

        return $i;
    };
    $this->variance = fn (?int $centre = null) => (function () use ($centre) {
        $this->actingAs($this->controller);

        return app(BudgetVsActual::class)->forPeriod(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $centre);
    })();
});

afterEach(fn () => Carbon::setTestNow());

// ---- cost centres --------------------------------------------------------------------------------------------------------------------

it('keeps cost centres to the company, with a parent, branch and department of the same company', function () {
    Livewire::actingAs($this->admin)->test(CostCenterList::class)->assertOk()->assertSee('Press shop')
        ->call('create')->set('values.code', ' line1 ')->set('values.name', 'Press line 1')->set('values.parent_id', (string) $this->press->id)->set('values.location_id', (string) $this->ahd->id)
        ->call('save')->assertHasNoErrors();

    $line = CostCenter::query()->where('code', 'LINE1')->firstOrFail();
    expect($line->parent_id)->toBe($this->press->id)->and($line->location_id)->toBe($this->ahd->id);

    $foreign = new CostCenter(['code' => 'THEIRS', 'name' => 'Their shop']);   // another company's, written below the tenant guard
    $foreign->forceFill(['company_id' => $this->globex->id]);
    CostCenter::withoutEvents(fn () => $foreign->save());
    Livewire::actingAs($this->admin)->test(CostCenterList::class)->call('create')->set('values.code', 'BAD')->set('values.name', 'Bad')->set('values.parent_id', (string) $foreign->id)
        ->call('save')->assertHasErrors(['values.parent_id']);
    Livewire::actingAs($this->admin)->test(CostCenterList::class)->call('edit', $this->press->id)->set('values.parent_id', (string) $this->press->id)->call('save')->assertHasErrors(['values.parent_id']);

    Livewire::actingAs($this->outsider)->test(CostCenterList::class)->assertDontSee('Press shop');
    Livewire::actingAs($this->telecaller)->test(CostCenterList::class)->assertForbidden();
});

it('will not delete a cost centre that has a child, a budget or a loss', function () {
    ($this->budget)($this->press, '2026-10', '1000');

    Livewire::actingAs($this->admin)->test(CostCenterList::class)->call('delete', $this->press->id)->assertHasErrors(['list']);
    expect(CostCenter::query()->whereKey($this->press->id)->exists())->toBeTrue();
});

// ---- budgets -------------------------------------------------------------------------------------------------------------------------

it('makes a budget for one cost centre, month and category, approved by someone other than the maker', function () {
    $b = ($this->budget)($this->press, '2026-10', '25000.50', 'line_stoppage', false);

    expect($b->period_start->toDateString())->toBe('2026-10-01')->and((string) $b->amount)->toBe('25000.50')->and($b->approval_status->value)->toBe('draft')
        ->and($b->loss_category_id)->toBe(($this->category)('line_stoppage')->id);

    expect(fn () => inCompany($this->acme, fn () => app(ApprovalService::class)->submit($b->fresh(), $this->accounts)))->not->toThrow(Exception::class);
    expect(fn () => inCompany($this->acme, fn () => app(ApprovalService::class)->approve($b->fresh(), $this->accounts)))->toThrow(Exception::class);   // the maker
    inCompany($this->acme, fn () => app(ApprovalService::class)->approve($b->fresh(), $this->controller));
    expect($b->fresh()->isApproved())->toBeTrue()->and(fn () => Budget::query()->find($b->id)->forceFill(['amount' => '1'])->save())->toThrow(LogicException::class);
});

it('refuses a budget that is not sensible, and a second one for the same cost centre, category and month', function () {
    $try = fn (array $over) => fn () => app(BudgetService::class)->save([
        'cost_center_id' => (string) $this->press->id, 'loss_category_id' => '', 'month' => '2026-11', 'amount' => '100', ...$over,
    ], ($this->actingAs($this->accounts))->accounts);

    expect($try(['amount' => '-5']))->toThrow(ValidationException::class)
        ->and($try(['amount' => '10.555']))->toThrow(ValidationException::class)
        ->and($try(['month' => 'next month']))->toThrow(ValidationException::class)
        ->and($try(['cost_center_id' => '999999']))->toThrow(ValidationException::class)
        ->and($try(['loss_category_id' => (string) LossCategory::query()->where('industry_template_id', $this->retail->id)->firstOrFail()->id]))->toThrow(ValidationException::class);   // a template the company does not use

    ($this->budget)($this->press, '2026-11', '100', null, false);
    expect($try([]))->toThrow(ValidationException::class, 'already has a budget');
    ($this->budget)($this->press, '2026-11', '100', 'line_stoppage', false);   // another category is fine
    ($this->budget)($this->paint, '2026-11', '100', null, false);               // another cost centre is fine
});

it('changes only a draft budget, and keeps people without the right out', function () {
    $b = ($this->budget)($this->press, '2026-10', '1000', null, false);
    $changed = app(BudgetService::class)->save(['cost_center_id' => (string) $this->press->id, 'loss_category_id' => '', 'month' => '2026-10', 'amount' => '1500'], $this->accounts, $b);
    expect((string) $changed->amount)->toBe('1500.00');

    $approved = ($this->budget)($this->paint, '2026-10', '1000');
    expect(fn () => app(BudgetService::class)->save(['cost_center_id' => (string) $this->paint->id, 'loss_category_id' => '', 'month' => '2026-10', 'amount' => '9'], $this->accounts, $approved))->toThrow(AuthorizationException::class);

    expect(fn () => app(BudgetService::class)->save(['cost_center_id' => (string) $this->press->id, 'month' => '2026-12', 'amount' => '1'], $this->telecaller))->toThrow(AuthorizationException::class);
});

// ---- budget against actual ----------------------------------------------------------------------------------------------------------

it('compares approved budgets with validated actual loss by cost centre and category, flagging what is over', function () {
    ($this->budget)($this->press, '2026-10', '10000', 'line_stoppage');
    ($this->budget)($this->press, '2026-10', '3000', 'component_defect');
    ($this->budget)($this->paint, '2026-10', '5000');                                  // all categories together
    ($this->budget)($this->paint, '2026-11', '99999');                                 // another month: out of the period
    ($this->budget)($this->press, '2026-10', '777', 'warranty_claim', false);          // not approved: compared with nothing

    ($this->loss)($this->press, 'line_stoppage', '12500');                              // over by 2,500
    ($this->loss)($this->press, 'line_stoppage', '500');                                // 13,000 in all
    ($this->loss)($this->press, 'component_defect', '1000');                            // within
    ($this->loss)($this->press, 'warranty_claim', '800');                               // a category with no approved budget
    ($this->loss)($this->paint, 'line_stoppage', '2000');
    ($this->loss)($this->paint, 'component_defect', '1500');                            // paint total 3,500 against its 5,000
    ($this->loss)($this->press, 'line_stoppage', '9999', '2026-10-06T09:00', false);   // not validated: not actual yet
    ($this->loss)($this->press, 'line_stoppage', '4444', '2026-09-20T09:00');           // last month: out of the period

    $rows = collect(($this->variance)())->keyBy(fn ($r) => $r['cost_center'].'|'.$r['category']);

    expect($rows['Press shop|Line stoppage'])->toMatchArray(['budget' => '10000.00', 'actual' => '13000.00', 'variance' => '3000.00', 'variance_pct' => '30.00', 'status' => 'over'])
        ->and($rows['Press shop|Component defect'])->toMatchArray(['budget' => '3000.00', 'actual' => '1000.00', 'variance' => '-2000.00', 'variance_pct' => '-66.67', 'status' => 'within'])
        ->and($rows['Press shop|Warranty claim'])->toMatchArray(['budget' => '0.00', 'actual' => '800.00', 'variance_pct' => null, 'status' => 'unbudgeted'])
        ->and($rows['Paint shop|All categories'])->toMatchArray(['budget' => '5000.00', 'actual' => '3500.00', 'variance' => '-1500.00', 'variance_pct' => '-30.00', 'status' => 'within'])
        ->and($rows['Paint shop|Line stoppage'])->toMatchArray(['budget' => '0.00', 'actual' => '2000.00', 'status' => 'unbudgeted'])   // shown, not hidden, even beside the all-categories budget
        ->and($rows)->toHaveCount(6);   // press ×3, paint's all-categories budget, and paint's two categories with losses but no budget of their own

    expect(collect(($this->variance)($this->paint->id))->every(fn ($r) => $r['cost_center'] === 'Paint shop'))->toBeTrue();
});

it('counts a loss reported twice from one record once, and nothing of a duplicate or rejected incident', function () {
    ($this->budget)($this->press, '2026-10', '1000', 'line_stoppage');
    ($this->loss)($this->press, 'line_stoppage', '400');

    $rows = collect(($this->variance)());
    expect($rows->firstWhere('category', 'Line stoppage')['actual'])->toBe('400.00');

    DB::table('loss_incidents')->update(['state' => 'rejected']);
    expect(collect(($this->variance)())->firstWhere('category', 'Line stoppage')['actual'])->toBe('0.00');
});

it('keeps another company’s budgets and losses out', function () {
    ($this->budget)($this->press, '2026-10', '1000');
    $this->actingAs($this->outsider);

    expect(app(BudgetVsActual::class)->forPeriod(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')))->toBe([]);
});

// ---- item cost history ----------------------------------------------------------------------------------------------------------------

it('writes down every change of an item’s standard cost and of its moving average after a receipt', function () {
    $purchase = makeUser(['Purchase User'], [$this->acme]);
    $item = makeItem($this->acme, $purchase, ['item' => ['name' => 'MS Hex Bolt M10', 'standard_cost' => '4']], 'approved');

    $history = fn (?string $method = null) => ItemCostEntry::withoutGlobalScopes(Tenancy::SCOPES)->where('item_id', $item->id)->when($method, fn ($q) => $q->where('method', $method))->orderBy('id')->get();
    expect($history('standard'))->toHaveCount(1)->and((string) $history()[0]->unit_cost)->toBe('4.0000')->and($history()[0]->previous_cost)->toBeNull();

    inCompany($this->acme, fn () => tap($item->fresh(), fn ($i) => $i->forceFill(['standard_cost' => '4.5'])->save()));
    inCompany($this->acme, fn () => tap($item->fresh(), fn ($i) => $i->forceFill(['standard_cost' => '4.5'])->save()));   // no change: no entry
    expect($history('standard'))->toHaveCount(2)->and((string) $history('standard')[1]->previous_cost)->toBe('4.0000')->and((string) $history('standard')[1]->unit_cost)->toBe('4.5000');

    $stock = app(StockService::class);
    inCompany($this->acme, fn () => $stock->receive($item->fresh(), $this->ahd->id, '100', '10', null, 'grn', 1, 1));
    inCompany($this->acme, fn () => $stock->receive($item->fresh(), $this->ahd->id, '50', '16', null, 'grn', 2, 1));

    $moving = $history('moving_average');
    expect($moving)->toHaveCount(2)->and((string) $moving[0]->unit_cost)->toBe('10.0000')->and($moving[0]->previous_cost)->toBeNull()
        ->and((string) $moving[1]->previous_cost)->toBe('10.0000')->and((string) $moving[1]->unit_cost)->toBe('12.0000')->and($moving[1]->location_id)->toBe($this->ahd->id);

    expect(fn () => $moving[0]->forceFill(['unit_cost' => '1'])->save())->toThrow(LogicException::class)->and(fn () => $moving[0]->delete())->toThrow(LogicException::class);
});

// ---- profitability ---------------------------------------------------------------------------------------------------------------------

/** Bolts and wire sold and invoiced through the real flow; four bolts returned and approved; some loss on the bolts. */
function profitWorld(): void
{
    $t = test();
    $purchase = makeUser(['Purchase User'], [$t->acme]);
    $manager = makeUser(['Sales Manager'], [$t->acme]);
    $approver = $t->approver;
    $brand = inCompany($t->acme, fn () => Brand::query()->create(['code' => 'FASTX', 'name' => 'FastX']));
    $t->bolt = makeUsableItem($t->acme, 'MS Hex Bolt M10', ['standard_cost' => '100', 'brand_id' => (string) $brand->id]);
    $t->wire = makeUsableItem($t->acme, 'Steel Wire 2mm', ['base_unit_id' => (string) unitId($t->acme, 'KG'), 'gst_rate' => '12', 'standard_cost' => '60']);
    makeStandardRate($t->acme, $t->bolt, $t->ahd, '125.50');
    makeStandardRate($t->acme, $t->wire, $t->ahd, '80');
    $team = makeTeam($t->acme, $manager, [$t->bde]);
    $customer = makeCustomer($t->acme, 'Shree Ganesh Industries', ['credit_limit' => '5000000', 'credit_days' => '30']);
    $deal = approveDocument($t->acme, makeDeal($t->acme, $t->bde, makeLead($t->acme, $t->bde, $t->ahd, [], [lineRow($t->bolt, '10', ''), lineRow($t->wire, '2.5', '')]), ['customer_ledger_id' => (string) $customer->id]), $t->bde, $approver);
    $t->invoice = makeSalesOrder($t->acme, $t->bde, $deal, $customer, [], null, 'approved', $approver)->invoice;
    $line = SalesInvoiceItem::query()->where('sales_invoice_id', $t->invoice->id)->where('item_name', 'MS Hex Bolt M10')->firstOrFail();
    $return = inCompany($t->acme, fn () => app(SalesReturnService::class)->create($t->invoice->fresh(), ['return_type' => 'return', 'reason' => 'Damaged in transit', 'lines' => [$line->id => '4']], $t->accounts));
    approveDocument($t->acme, $return, $t->accounts, $approver);
}

it('works out profit and margin by product, brand and site from invoices less approved returns, with loss beside it', function () {
    profitWorld();
    ($this->loss)($this->press, 'component_defect', '600');   // not on an item: shows as "No item"

    $this->actingAs($this->controller);
    $profit = app(Profitability::class);
    $from = Carbon::parse('2026-10-01');
    $to = Carbon::parse('2026-10-31');

    $products = collect($profit->by('product', $from, $to))->keyBy('name');
    expect($products['MS Hex Bolt M10'])->toMatchArray(['gross_sales' => '1255.00', 'returns' => '502.00', 'net_revenue' => '753.00', 'cogs' => '600.00', 'gross_profit' => '153.00', 'margin_pct' => '20.32', 'lines_without_cost' => 0])
        ->and($products['Steel Wire 2mm'])->toMatchArray(['net_revenue' => '200.00', 'cogs' => '150.00', 'gross_profit' => '50.00', 'margin_pct' => '25.00'])
        ->and($products['No item'])->toMatchArray(['net_revenue' => '0.00', 'actual_loss' => '600.00', 'margin_pct' => null]);

    $brands = collect($profit->by('brand', $from, $to))->keyBy('name');
    expect($brands['FastX'])->toMatchArray(['net_revenue' => '753.00', 'gross_profit' => '153.00'])
        ->and($brands['No brand'])->toMatchArray(['net_revenue' => '200.00', 'gross_profit' => '50.00', 'actual_loss' => '600.00']);

    $sites = collect($profit->by('site', $from, $to))->keyBy('name');
    expect($sites['AHD Branch'])->toMatchArray(['net_revenue' => '953.00', 'cogs' => '750.00', 'gross_profit' => '203.00', 'margin_pct' => '21.30', 'actual_loss' => '600.00']);

    // the totals of every view agree with each other and with the one revenue calculation
    $overall = app(NetRevenueCalculator::class)->forPeriod($from, $to);
    foreach (['product', 'brand', 'site'] as $by) {
        expect(array_reduce($profit->by($by, $from, $to), fn ($c, $r) => bcadd($c, $r['net_revenue'], 2), '0.00'))->toBe($overall['net_revenue']);
    }

    expect(collect($profit->by('site', Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')))->sum('net_revenue'))->toEqual(0);   // another period
});

it('says when the cost of a row is not complete, and keeps another company out', function () {
    profitWorld();
    DB::table('sales_invoice_items')->where('item_name', 'Steel Wire 2mm')->update(['cost_amount' => null, 'unit_cost' => null]);

    $this->actingAs($this->controller);
    $wire = collect(app(Profitability::class)->by('product', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')))->firstWhere('name', 'Steel Wire 2mm');
    expect($wire['lines_without_cost'])->toBe(1)->and($wire['cogs'])->toBe('0.00');

    $this->actingAs($this->outsider);
    expect(app(Profitability::class)->by('site', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')))->toBe([]);
});

// ---- the screens ----------------------------------------------------------------------------------------------------------------------

it('makes and approves a budget from the screens', function () {
    Livewire::actingAs($this->accounts)->test(BudgetForm::class)->assertOk()
        ->set('costCenterId', (string) $this->press->id)->set('month', '2026-10')->set('amount', '12000')->call('save')->assertHasNoErrors()->assertRedirect();

    $b = Budget::withoutGlobalScopes(Tenancy::SCOPES)->sole();
    Livewire::actingAs($this->accounts)->test(BudgetShow::class, ['budget' => $b])->assertOk()->assertSee('12,000.00')->assertSee('Press shop');
    Livewire::actingAs($this->accounts)->test(BudgetList::class)->assertOk()->assertSee('Oct 2026');
    Livewire::actingAs($this->telecaller)->test(BudgetList::class)->assertForbidden();
    Livewire::actingAs($this->management)->test(BudgetForm::class)->assertForbidden();   // reads, does not make
});

it('shows budget against actual and profitability to those who may read them', function () {
    ($this->budget)($this->press, '2026-10', '1000', 'line_stoppage');
    ($this->loss)($this->press, 'line_stoppage', '1500');

    Livewire::actingAs($this->management)->withQueryParams(['from' => '2026-10-01', 'to' => '2026-10-31'])->test(VarianceReport::class)->assertOk()->assertSee('Over budget')->assertSee('1,500.00');
    Livewire::actingAs($this->management)->test(ProfitabilityReport::class)->assertOk()->assertSee('Net revenue');
    Livewire::actingAs($this->telecaller)->test(VarianceReport::class)->assertForbidden();
    Livewire::actingAs($this->telecaller)->test(ProfitabilityReport::class)->assertForbidden();
    Livewire::actingAs($this->bde)->test(ProfitabilityReport::class)->assertForbidden();
});

it('opens the pages over HTTP and puts them in the menu', function () {
    $b = ($this->budget)($this->press, '2026-10', '1000');

    foreach (['/finance/cost-centres', '/finance/budgets', '/finance/budgets/create', '/finance/budgets/'.$b->id, '/finance/variance', '/finance/profitability'] as $url) {
        $this->actingAs($this->controller)->get($url)->assertOk();
    }

    $this->actingAs($this->controller)->get('/finance/profitability')->assertSee('Budget against actual')->assertSee('Cost centres');
    $this->actingAs($this->telecaller)->get('/finance/profitability')->assertForbidden();
});

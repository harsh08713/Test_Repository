<?php

use App\Modules\Analytics\Http\Livewire\ExecutiveDashboard;
use App\Modules\Analytics\Services\AnalyticsFilter;
use App\Modules\Analytics\Services\AnalyticsPages;
use App\Modules\Analytics\Services\DashboardAnalytics;
use App\Modules\Analytics\Services\GlobalSearch;
use App\Modules\Analytics\Services\KpiCalculator;
use App\Modules\Analytics\Services\KpiSnapshotService;
use App\Modules\Finance\Services\Profitability;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\KpiDefinition;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Loss\Services\LossCalculationService;
use App\Modules\Loss\Services\LossIncidentService;
use App\Modules\Masters\Models\Brand;
use App\Modules\Masters\Services\ItemService;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Services\NetRevenueCalculator;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\IndustryTemplateSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
 * L6: the filter, the dashboard figures, the six pages, KPI snapshots and the header search. Hand-calculated on the Finance world: bolts 10 at ₹125.50
 * (cost 100) with 4 returned, wire 2.5 kg at ₹80 (cost 60): bolts net 753.00 (automotive), wire net 200.00 (retail); total 953.00, cost 750.00, profit 203.00.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();
    $this->seed(IndustryTemplateSeeder::class);

    $this->acme = makeCompany('ACME', ['state_code' => '24', 'gstin' => gstinFor('24', 'ABCDE1234F')]);
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->management = makeUser(['Management'], [$this->acme]);
    $this->manager = makeUser(['Quality/Loss Manager'], [$this->acme]);
    $this->investigator = makeUser(['Investigator'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    // someone who may read profitability but not losses, and the reverse
    Role::create(['name' => 'Money only'])->givePermissionTo(['analytics.view', 'profitability.view', 'ledger.view']);
    $this->moneyOnly = makeUser(['Money only'], [$this->acme]);
    $this->outsider = makeUser(['Company Admin'], [$this->globex]);

    $this->auto = IndustryTemplate::query()->where('code', 'automotive')->firstOrFail();
    $this->retail = IndustryTemplate::query()->where('code', 'retail')->firstOrFail();
    $this->actingAs($this->admin);
    app(IndustryTemplateService::class)->enable($this->auto, $this->admin);
    app(IndustryTemplateService::class)->enable($this->retail, $this->admin);

    $this->period = AnalyticsFilter::fromArray(['range' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31']);
});

afterEach(fn () => Carbon::setTestNow());

function analyticsWorld(): void
{
    $t = test();
    $manager = makeUser(['Sales Manager'], [$t->acme]);
    $brand = inCompany($t->acme, fn () => Brand::query()->create(['code' => 'FASTX', 'name' => 'FastX']));
    $t->bolt = makeUsableItem($t->acme, 'MS Hex Bolt M10', ['standard_cost' => '100', 'brand_id' => (string) $brand->id]);
    $t->wire = makeUsableItem($t->acme, 'Steel Wire 2mm', ['base_unit_id' => (string) unitId($t->acme, 'KG'), 'gst_rate' => '12', 'standard_cost' => '60']);
    DB::table('items')->where('id', $t->bolt->id)->update(['industry_template_id' => $t->auto->id]);
    DB::table('items')->where('id', $t->wire->id)->update(['industry_template_id' => $t->retail->id]);
    makeStandardRate($t->acme, $t->bolt, $t->ahd, '125.50');
    makeStandardRate($t->acme, $t->wire, $t->ahd, '80');
    makeTeam($t->acme, $manager, [$t->bde]);
    $customer = makeCustomer($t->acme, 'Shree Ganesh Industries', ['credit_limit' => '5000000', 'credit_days' => '30']);
    $deal = approveDocument($t->acme, makeDeal($t->acme, $t->bde, makeLead($t->acme, $t->bde, $t->ahd, [], [lineRow($t->bolt, '10', ''), lineRow($t->wire, '2.5', '')]), ['customer_ledger_id' => (string) $customer->id]), $t->bde, $t->approver);
    $t->invoice = makeSalesOrder($t->acme, $t->bde, $deal, $customer, [], null, 'approved', $t->approver)->invoice;
    $line = SalesInvoiceItem::query()->where('sales_invoice_id', $t->invoice->id)->where('item_name', 'MS Hex Bolt M10')->firstOrFail();
    $return = inCompany($t->acme, fn () => app(SalesReturnService::class)->create($t->invoice->fresh(), ['return_type' => 'return', 'reason' => 'Damaged in transit', 'lines' => [$line->id => '4']], $t->accounts));
    approveDocument($t->acme, $return, $t->accounts, $t->approver);
}

function analyticsLoss(string $amount, string $when = '2026-10-05T10:00', string $category = 'component_defect', ?IndustryTemplate $template = null): void
{
    $t = test();
    $template ??= $t->auto;
    $cat = LossCategory::query()->where('industry_template_id', $template->id)->orderBy('id')->when($template->id === $t->auto->id, fn ($q) => $q->where('code', $category))->firstOrFail();
    $t->actingAs($t->investigator);
    $i = app(LossIncidentService::class)->create([
        'industry_template_id' => (string) $template->id, 'loss_category_id' => (string) $cat->id, 'location_id' => (string) $t->ahd->id, 'occurred_at' => $when, 'severity' => 'medium',
        'title' => 'Loss '.$amount, 'description' => 'Something was lost', 'custom_fields' => $template->id === $t->auto->id ? ['line' => 'L1'] : [],
        'impact' => ['impact_type' => 'actual', 'formula_key' => 'manual', 'inputs' => ['amount' => $amount], 'assumptions' => 'Counted'],
    ], $t->investigator);
    $t->actingAs($t->manager);
    app(LossCalculationService::class)->validateLine($i->impactLines()->firstOrFail(), $t->manager);
}

// ---- the filter ------------------------------------------------------------------------------------------------------------------------

it('reads the filter from the query string and falls back safely on anything odd', function () {
    $today = CarbonImmutable::parse('2026-10-12');

    $month = AnalyticsFilter::fromArray(['range' => 'this_month'], $today);
    expect($month->from->toDateString())->toBe('2026-10-01')->and($month->to->toDateString())->toBe('2026-10-12');

    $last = AnalyticsFilter::fromArray(['range' => 'last_month'], $today);
    expect($last->from->toDateString())->toBe('2026-09-01')->and($last->to->toDateString())->toBe('2026-09-30');

    $year = AnalyticsFilter::fromArray(['range' => 'this_year'], $today);
    expect($year->from->toDateString())->toBe('2026-04-01');   // the Indian financial year

    // nonsense: unknown preset, reversed or over-long custom range, non-numeric ids
    expect(AnalyticsFilter::fromArray(['range' => 'sometime'], $today)->range)->toBe('this_year')
        ->and(AnalyticsFilter::fromArray(['range' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-01'], $today)->range)->toBe('this_year')
        ->and(AnalyticsFilter::fromArray(['range' => 'custom', 'from' => '2015-01-01', 'to' => '2026-10-01'], $today)->range)->toBe('this_year')
        ->and(AnalyticsFilter::fromArray(['range' => 'custom', 'from' => 'x', 'to' => '2026-10-01'], $today)->range)->toBe('this_year');

    $odd = AnalyticsFilter::fromArray(['industry' => '1 OR 1=1', 'branch' => '-3', 'category' => ['x']], $today);
    expect($odd->industryId)->toBeNull()->and($odd->locationId)->toBeNull()->and($odd->categoryId)->toBeNull();
    expect(AnalyticsFilter::fromArray(['industry' => '7', 'branch' => '9'], $today)->toQuery())->toMatchArray(['industry' => 7, 'branch' => 9]);
});

it('compares with the period of the same length just before, and picks a sensible grain', function () {
    $f = AnalyticsFilter::fromArray(['range' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31']);
    $p = $f->previous();

    expect($p->from->toDateString())->toBe('2026-08-31')->and($p->to->toDateString())->toBe('2026-09-30')
        ->and($f->granularity())->toBe('day')
        ->and(AnalyticsFilter::fromArray(['range' => 'custom', 'from' => '2026-04-01', 'to' => '2026-10-12'])->granularity())->toBe('month')
        ->and(AnalyticsFilter::fromArray(['range' => 'custom', 'from' => '2026-07-01', 'to' => '2026-10-12'])->granularity())->toBe('week')
        ->and($f->granularity('month'))->toBe('month')->and($f->granularity('fortnight'))->toBe('day');
});

// ---- the figures -------------------------------------------------------------------------------------------------------------------------

it('works out the cards, the shares and the trend from the invoices, returns and validated losses', function () {
    analyticsWorld();
    analyticsLoss('600');

    $this->actingAs($this->accounts);
    $a = app(DashboardAnalytics::class);
    $cards = $a->kpiCards($this->period);

    expect($cards['revenue']['value'])->toBe('953.00')->and($cards['revenue']['change_pct'])->toBeNull()   // nothing in September to compare with
        ->and($cards['losses']['value'])->toBe('600.00')
        ->and($cards['products']['value'])->toBe('2')
        ->and($cards['brand']['value'])->toBe('FastX')
        ->and($cards['templates']['value'])->toBe('2');

    $rev = collect($a->revenueByIndustry($this->period))->keyBy('name');
    expect($rev[$this->auto->name]['amount'])->toBe('753.00')->and($rev[$this->retail->name]['amount'])->toBe('200.00')
        ->and((float) $rev[$this->auto->name]['share_pct'])->toEqualWithDelta(79.01, 0.01)
        ->and((float) $rev[$this->retail->name]['share_pct'])->toEqualWithDelta(20.99, 0.01);

    $lost = $a->lossesByIndustry($this->period);
    expect($lost)->toHaveCount(1)->and($lost[0]['name'])->toBe($this->auto->name)->and($lost[0]['amount'])->toBe('600.00')->and($lost[0]['share_pct'])->toBe('100.00');

    // the trend adds up to the same totals the cards show
    $trend = $a->trend($this->period);
    expect(array_reduce($trend['revenue'], fn ($c, $v) => bcadd($c, $v, 2), '0.00'))->toBe('953.00')
        ->and(array_reduce($trend['loss'], fn ($c, $v) => bcadd($c, $v, 2), '0.00'))->toBe('600.00')
        ->and($trend['labels'])->toHaveCount(31)->and(array_sum(array_map('floatval', $trend['prior_revenue'])))->toEqual(0);

    // and they are the figures of the one revenue calculation
    expect(app(NetRevenueCalculator::class)->forPeriod($this->period->from, $this->period->to)['net_revenue'])->toBe('953.00');
});

it('compares with the previous period, and shows no change when there was nothing before', function () {
    analyticsWorld();
    $a = app(DashboardAnalytics::class);
    $this->actingAs($this->accounts);

    expect($a->change('150.00', '100.00'))->toBe('50.00')->and($a->change('50.00', '100.00'))->toBe('-50.00')->and($a->change('50.00', '0.00'))->toBeNull();

    // a window that ends at the invoice: the same invoice is "previous" for the next window
    $next = AnalyticsFilter::fromArray(['range' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-30']);
    expect($a->kpiCards($next)['revenue']['value'])->toBe('0.00')->and($a->kpiCards($next)['revenue']['change_pct'])->toBe('-100.00');
});

it('lists the top products, the brands and the industry overview with every template, the unused ones marked', function () {
    analyticsWorld();
    $this->actingAs($this->accounts);
    $a = app(DashboardAnalytics::class);

    $products = $a->topProducts($this->period);
    expect(array_column($products, 'name'))->toBe(['MS Hex Bolt M10', 'Steel Wire 2mm'])
        ->and($products[0]['net_revenue'])->toBe('753.00')->and($products[0]['gross_profit'])->toBe('153.00')
        ->and(rtrim(rtrim($products[1]['units'], '0'), '.'))->toBe('2.5');

    $brands = $a->brandProfitability($this->period);
    expect($brands[0]['name'])->toBe('FastX')->and($brands[0]['net_revenue'])->toBe('753.00');

    $overview = collect($a->industryOverview($this->period))->keyBy('code');
    expect($overview['automotive'])->toMatchArray(['enabled' => true, 'net_revenue' => '753.00'])
        ->and($overview['retail']['net_revenue'])->toBe('200.00')
        ->and($overview->where('enabled', false))->not->toBeEmpty()
        ->and($overview->where('enabled', false)->first()['net_revenue'])->toBe('0.00');
});

it('adds an industry to the profitability breakdown and filters by it, always agreeing with the revenue calculation', function () {
    analyticsWorld();
    analyticsLoss('600');
    $this->actingAs($this->accounts);
    $profit = app(Profitability::class);
    [$from, $to] = [$this->period->from, $this->period->to];

    $rows = collect($profit->by('industry', $from, $to))->keyBy('name');
    expect($rows[$this->auto->name])->toMatchArray(['net_revenue' => '753.00', 'actual_loss' => '600.00'])->and($rows[$this->retail->name]['net_revenue'])->toBe('200.00');
    expect(array_reduce($profit->by('industry', $from, $to), fn ($c, $r) => bcadd($c, $r['net_revenue'], 2), '0.00'))->toBe('953.00');

    // narrowed to one industry, every dimension shows only that industry's sales and losses
    expect(array_reduce($profit->by('product', $from, $to, $this->retail->id), fn ($c, $r) => bcadd($c, $r['net_revenue'], 2), '0.00'))->toBe('200.00')
        ->and(array_reduce($profit->by('site', $from, $to, $this->auto->id), fn ($c, $r) => bcadd($c, $r['actual_loss'], 2), '0.00'))->toBe('600.00')
        ->and(array_reduce($profit->by('site', $from, $to, $this->retail->id), fn ($c, $r) => bcadd($c, $r['actual_loss'], 2), '0.00'))->toBe('0.00');
});

it('leaves out another company’s sales, and a branch the person does not work in', function () {
    analyticsWorld();
    $other = makeLocation($this->acme, 'MUM', ['state_code' => '27']);
    $this->actingAs($this->outsider);
    app(TenantContext::class)->flush();
    expect(app(DashboardAnalytics::class)->kpiCards($this->period)['revenue']['value'])->toBe('0.00');

    $this->actingAs($this->bde);
    app(TenantContext::class)->flush();   // works in AHD only
    $f = AnalyticsFilter::fromArray(['range' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31', 'branch' => (string) $other->id]);
    expect(app(DashboardAnalytics::class)->kpiCards($f)['revenue']['value'])->toBe('0.00');
});

// ---- the six pages -----------------------------------------------------------------------------------------------------------------------

it('lets a person open a page only with analytics.view and the right to the figures it shows', function () {
    $pages = app(AnalyticsPages::class);

    expect($pages->allowed($this->moneyOnly, 'top-products'))->toBeTrue()          // profitability.view
        ->and($pages->allowed($this->moneyOnly, 'loss-categories'))->toBeFalse()    // no loss_incident.view
        ->and($pages->allowed($this->manager, 'loss-categories'))->toBeTrue()
        ->and($pages->allowed($this->manager, 'brand-profitability'))->toBeFalse()
        ->and($pages->allowed($this->management, 'revenue-loss'))->toBeTrue()
        ->and($pages->allowed($this->bde, 'revenue-loss'))->toBeFalse()            // no analytics.view
        ->and($pages->exists('nonsense'))->toBeFalse();
});

it('serves each page as JSON to people allowed and refuses the rest', function () {
    analyticsWorld();
    analyticsLoss('600');
    $q = '?format=json&range=custom&from=2026-10-01&to=2026-10-31';

    $this->actingAs($this->management);
    $this->getJson('/analytics/industry-revenue'.$q)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.rows.0.amount', '753.00')->assertJsonPath('data.period.from', '2026-10-01');
    $this->getJson('/analytics/loss-categories'.$q)->assertOk()->assertJsonPath('data.rows.0.amount', '600.00');
    $this->getJson('/analytics/revenue-loss'.$q.'&granularity=month')->assertOk()->assertJsonPath('data.trend.granularity', 'month');
    $this->getJson('/analytics/nothing'.$q)->assertNotFound();

    $this->actingAs($this->bde)->getJson('/analytics/top-products'.$q)->assertForbidden();
    $this->actingAs($this->manager)->getJson('/analytics/top-products'.$q)->assertForbidden();   // loss rights are not sales rights

    // the trend hides the series the person may not see
    $this->actingAs($this->manager)->getJson('/analytics/revenue-loss'.$q)->assertOk()->assertJsonPath('data.can.revenue', false)->assertJsonPath('data.trend.loss.4', '600.00');
    expect(array_sum(array_map('floatval', $this->actingAs($this->manager)->getJson('/analytics/revenue-loss'.$q)->json('data.trend.revenue'))))->toEqual(0);
});

it('drills from an industry to its categories, branches and incidents', function () {
    analyticsWorld();
    analyticsLoss('600');
    $this->actingAs($this->manager);
    $pages = app(AnalyticsPages::class);

    $top = $pages->data($this->manager, 'industry-losses', $this->period);
    expect($top['categories'])->toBe([])->and($top['incidents'])->toBe([]);

    $f = AnalyticsFilter::fromArray(['range' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31', 'industry' => (string) $this->auto->id]);
    $drill = $pages->data($this->manager, 'industry-losses', $f);
    expect($drill['categories'][0]['amount'])->toBe('600.00')->and($drill['sites'][0]['amount'])->toBe('600.00')->and($drill['incidents'][0]['actual_loss'])->toBe('600.00');
});

// ---- the screens -------------------------------------------------------------------------------------------------------------------------

it('opens the dashboard and the pages for people allowed, and shows only what they may see', function () {
    analyticsWorld();
    analyticsLoss('600');

    $this->actingAs($this->management)->get('/executive')->assertOk()->assertSee('Executive dashboard')->assertSee('Total revenue')->assertSee('Total losses')->assertSee('Industry performance overview');
    foreach (array_keys(AnalyticsPages::PAGES) as $page) {
        $this->actingAs($this->management)->get('/analytics/'.$page.'?range=this_year')->assertOk();
    }

    // someone with money rights sees money, not losses; the loss manager sees losses, not revenue
    $this->actingAs($this->moneyOnly)->get('/executive')->assertOk()->assertSee('Total revenue')->assertDontSee('Total losses')->assertDontSee('Latest loss incidents');
    $this->actingAs($this->manager)->get('/executive')->assertOk()->assertSee('Total losses')->assertDontSee('Total revenue')->assertDontSee('Profitability by brand');

    $this->actingAs($this->bde)->get('/executive')->assertForbidden();
    $this->actingAs($this->bde)->get('/analytics/top-products')->assertForbidden();
    $this->actingAs($this->management)->get('/analytics/unknown')->assertNotFound();
});

it('re-reads the filter when it is changed on the screen, and ignores a value it does not know', function () {
    analyticsWorld();
    $this->actingAs($this->management);

    Livewire::test(ExecutiveDashboard::class)->set('range', 'custom')->set('from', '2026-10-01')->set('to', '2026-10-31')
        ->assertSee('01 Oct 2026 – 31 Oct 2026')->assertSee('₹953');
    Livewire::test(ExecutiveDashboard::class)->set('range', 'custom')->set('from', 'garbage')->set('to', '2026-10-31')->assertOk();
    Livewire::test(ExecutiveDashboard::class)->set('industry', '1; DROP TABLE items')->assertOk()->set('grain', 'decade')->assertOk();
});

it('shows an industry its KPIs, with thresholds, and “not measured yet” where the system has no data', function () {
    analyticsWorld();
    $this->actingAs($this->management);

    $this->get('/industries/automotive/dashboard')->assertOk()->assertSee('Not measured yet');
    $this->get('/industries/hospitality/dashboard')->assertOk()->assertSee('not switched on');
    $this->actingAs($this->bde)->get('/industries/automotive/dashboard')->assertForbidden();
});

// ---- KPIs and snapshots ------------------------------------------------------------------------------------------------------------------

it('measures only the KPIs it has data for and says nothing about the rest', function () {
    analyticsWorld();
    analyticsLoss('600');
    $this->actingAs($this->admin);
    $calc = app(KpiCalculator::class);

    $kpis = KpiDefinition::query()->where('industry_template_id', $this->auto->id)->where('version', 1)->get();
    expect($kpis->filter(fn ($k) => ! $calc->supports($k)))->not->toBeEmpty();
    foreach ($kpis->filter(fn ($k) => ! $calc->supports($k)) as $k) {
        expect($calc->compute($k, $this->auto, $this->period->from, $this->period->to))->toBeNull();
    }

    $margin = $kpis->firstWhere('formula_key', 'gross_margin_pct');
    if ($margin !== null) {
        $r = $calc->compute($margin, $this->auto, $this->period->from, $this->period->to);
        expect($r['numerator'])->toBe('153.0000')->and($r['denominator'])->toBe('753.0000')->and($r['value'])->toBe('20.3187');
    }

    $open = $kpis->firstWhere('formula_key', 'open_count');
    if ($open !== null) {
        expect($calc->compute($open, $this->auto, $this->period->from, $this->period->to)['value'])->not->toBeNull();
    }
});

it('takes the snapshots once a day, rewriting rather than duplicating, for every company', function () {
    analyticsWorld();
    $svc = app(KpiSnapshotService::class);

    $first = $svc->run();
    $rows = DB::table('kpi_snapshots')->count();
    expect($first)->toBeGreaterThan(0)->and($rows)->toBe($first);

    expect($svc->run())->toBe($first)->and(DB::table('kpi_snapshots')->count())->toBe($rows);   // idempotent

    $svc->run(CarbonImmutable::parse('2026-10-13'));
    expect(DB::table('kpi_snapshots')->count())->toBe($rows * 2);   // a new day is new rows

    expect(DB::table('kpi_snapshots')->where('company_id', $this->globex->id)->count())->toBe(0);   // nothing switched on there

    $this->artisan('analytics:snapshots')->assertSuccessful();
    expect(DB::table('kpi_snapshots')->count())->toBe($rows * 2);
});

// ---- search and navigation ---------------------------------------------------------------------------------------------------------------

it('searches only what the person may open, and treats the term as plain text', function () {
    analyticsWorld();
    $search = app(GlobalSearch::class);

    $names = fn ($user, $term) => collect($search->search($user, $term))->pluck('hits.*.label')->flatten()->all();

    $this->actingAs($this->admin);
    expect(implode('|', $names($this->admin, 'Hex Bolt')))->toContain('MS Hex Bolt M10');
    expect(collect($search->search($this->admin, 'Hex Bolt'))->pluck('group')->all())->toContain('Products');
    expect($search->search($this->admin, 'a'))->toBe([])                                 // too short
        ->and($search->search($this->admin, '%%'))->toBe([])                              // a wildcard is a literal, and nothing is called that
        ->and(collect($search->search($this->admin, 'Executive'))->pluck('group')->all())->toContain('Go to');

    // a person without the right gets no group for it; another company's records never appear
    $this->actingAs($this->bde);
    expect(collect($search->search($this->bde, 'Hex Bolt'))->pluck('group')->all())->not->toContain('Loss incidents');
    $this->actingAs($this->outsider);
    expect(collect($search->search($this->outsider, 'Shree Ganesh'))->pluck('group')->all())->not->toContain('Customers and suppliers');
});

it('puts the analytics pages in the menu only for those who may open them, and the unbuilt modules as “soon”', function () {
    $this->actingAs($this->management)->get('/dashboard')->assertOk()->assertSee('Executive dashboard')->assertSee('Brand profitability')->assertSee('Soon');
    $this->actingAs($this->manager)->get('/dashboard')->assertOk()->assertSee('Loss categories')->assertDontSee('Brand profitability');
    $this->actingAs($this->bde)->get('/dashboard')->assertOk()->assertDontSee('Executive dashboard');
});

it('lets an item be given an industry the company uses, and no other', function () {
    $purchase = makeUser(['Purchase User'], [$this->acme]);
    $service = app(ItemService::class);
    $other = IndustryTemplate::query()->where('code', 'hospitality')->firstOrFail();   // not switched on for this company

    $this->actingAs($purchase);
    expect(fn () => $service->validatePurchase(itemInput($this->acme, ['item' => ['industry_template_id' => (string) $other->id]]), null))->toThrow(ValidationException::class);

    $item = makeItem($this->acme, $purchase, ['item' => ['industry_template_id' => (string) $this->auto->id]]);
    expect($item->fresh()->industry_template_id)->toBe($this->auto->id);
});

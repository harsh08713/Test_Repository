<?php

use App\Modules\Analytics\Services\AnalyticsFilter;
use App\Modules\Analytics\Services\DashboardAnalytics;
use App\Modules\Finance\Models\CostCenter;
use App\Modules\Finance\Services\BudgetService;
use App\Modules\Finance\Services\BudgetVsActual;
use App\Modules\Finance\Services\Profitability;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Services\EmployeeAccess;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\HR\Services\LeaveAccrual;
use App\Modules\HR\Services\LeaveService;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Loss\Models\CorrectiveAction;
use App\Modules\Loss\Services\LossCalculationService;
use App\Modules\Loss\Services\LossFigures;
use App\Modules\Loss\Services\LossIncidentService;
use App\Modules\Masters\Models\Brand;
use App\Modules\Reports\Exceptions\InvalidReportFilters;
use App\Modules\Reports\Http\Livewire\ReportRun;
use App\Modules\Reports\Models\ReportExport;
use App\Modules\Reports\Services\ReportCatalog;
use App\Modules\Reports\Services\ReportExporter;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Services\NetRevenueCalculator;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\IndustryTemplateSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;

/*
 * L8: the loss, finance, returns and HR reports, and their exports. Each is checked against the one place its figures are worked out (LossFigures, DashboardAnalytics,
 * BudgetVsActual, Profitability, NetRevenueCalculator) so that the page, the Excel and the PDF can never differ from the screens, and against who may see what.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();
    Storage::fake('local');
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
    $this->ahdManager = makeUser(['Quality/Loss Manager'], [$this->acme], [$this->ahd]);     // works in one branch only
    $this->investigator = makeUser(['Investigator'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->hr = makeUser(['HR Manager'], [$this->acme]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->outsider = makeUser(['Company Admin'], [$this->globex]);

    $this->auto = IndustryTemplate::query()->where('code', 'automotive')->firstOrFail();
    $this->actingAs($this->admin);
    app(IndustryTemplateService::class)->enable($this->auto, $this->admin);
    $this->press = inCompany($this->acme, fn () => CostCenter::query()->create(['code' => 'PRESS', 'name' => 'Press shop']));
    $this->paint = inCompany($this->acme, fn () => CostCenter::query()->create(['code' => 'PAINT', 'name' => 'Paint shop']));

    $this->as = function ($user) {
        $this->actingAs($user);
        Auth::setUser($user);
        app(TenantContext::class)->flush();
        app(EmployeeAccess::class)->flush();

        return $user;
    };
    $this->loss = function (CostCenter $c, string $category, string $amount, string $when = '2026-10-05T10:00', bool $validate = true, $branch = null) {
        $cat = LossCategory::query()->where('industry_template_id', $this->auto->id)->where('code', $category)->firstOrFail();
        ($this->as)($this->investigator);
        $i = app(LossIncidentService::class)->create([
            'industry_template_id' => (string) $this->auto->id, 'loss_category_id' => (string) $cat->id, 'location_id' => (string) ($branch ?? $this->ahd)->id, 'cost_center_id' => (string) $c->id,
            'occurred_at' => $when, 'severity' => 'medium', 'title' => 'Loss '.$amount, 'description' => 'Something was lost', 'custom_fields' => ['line' => 'L1'],
            'impact' => ['impact_type' => 'actual', 'formula_key' => 'manual', 'inputs' => ['amount' => $amount], 'assumptions' => 'Counted'],
        ], $this->investigator);

        if ($validate) {
            ($this->as)($this->manager);
            app(LossCalculationService::class)->validateLine($i->impactLines()->firstOrFail(), $this->manager);
        }

        return $i;
    };
    $this->budget = function (CostCenter $c, string $month, string $amount, ?string $category = null) {
        ($this->as)($this->accounts);
        $b = app(BudgetService::class)->save(['cost_center_id' => (string) $c->id, 'loss_category_id' => $category === null ? '' : (string) LossCategory::query()->where('industry_template_id', $this->auto->id)->where('code', $category)->value('id'), 'month' => $month, 'amount' => $amount], $this->accounts);

        return approveDocument($this->acme, $b, $this->accounts, $this->controller);
    };
    $this->run = function ($user, string $key, array $filters = []) {
        return inCompany($this->acme, function () use ($user, $key, $filters) {
            ($this->as)($user);
            $report = app(ReportCatalog::class)->find($key);
            $input = ReportInput::resolve($report, $filters, $user);

            return ['report' => $report, 'rows' => iterator_to_array($report->rows($input, $user), false), 'page' => $report->page($input, $user, 100), 'summary' => $report->summary($input, $user), 'input' => $input];
        });
    };
    $this->span = ['from' => '2026-10-01', 'to' => '2026-10-31'];
});

afterEach(fn () => Carbon::setTestNow());

// ---- loss ---------------------------------------------------------------------------------------------------------------------------------------

it('lists every incident with its four values apart, counts only what LossFigures counts, and its summary is LossFigures', function () {
    $a = ($this->loss)($this->press, 'component_defect', '600');
    $b = ($this->loss)($this->press, 'line_stoppage', '1400', '2026-10-06T08:00');
    $dup = ($this->loss)($this->paint, 'component_defect', '600', '2026-10-07T08:00');
    $rejected = ($this->loss)($this->paint, 'line_stoppage', '250', '2026-10-08T08:00');
    ($this->loss)($this->press, 'line_stoppage', '9000', '2026-10-09T08:00', false);                // not validated: not actual loss yet
    ($this->loss)($this->press, 'line_stoppage', '4444', '2026-09-20T08:00');                         // last month

    DB::table('loss_incidents')->where('id', $dup->id)->update(['primary_incident_id' => $a->id]);   // a duplicate of the first
    DB::table('loss_incidents')->where('id', $rejected->id)->update(['state' => 'rejected']);

    $r = ($this->run)($this->manager, 'loss-incident-register', $this->span);
    $byNumber = collect($r['rows'])->keyBy('incident_number');

    expect($r['rows'])->toHaveCount(5)                                                                     // October's five, not September's
        ->and($byNumber[$a->incident_number])->toMatchArray(['actual_loss' => '600.00', 'counted' => 'Yes'])
        ->and($byNumber[$dup->incident_number])->toMatchArray(['actual_loss' => null, 'counted' => 'No'])      // a duplicate counts for nothing
        ->and($byNumber[$rejected->incident_number])->toMatchArray(['actual_loss' => null, 'counted' => 'No', 'state' => 'rejected'])
        ->and(array_keys($r['rows'][0]))->toBe(['incident_number', 'occurred_at', 'industry', 'category', 'branch', 'severity', 'state', 'title', 'item', 'actual_loss', 'estimated_exposure', 'opportunity', 'counted']);

    // the column adds up to LossFigures, which is also the summary: 600 + 1400 (the unvalidated line is not actual)
    $figures = app(LossFigures::class)->forPeriod(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
    $sum = collect($r['rows'])->reduce(fn ($c, $row) => bcadd($c, (string) ($row['actual_loss'] ?? '0'), 2), '0.00');
    expect($figures['actual_loss'])->toBe('2000.00')->and($sum)->toBe($figures['actual_loss'])
        ->and($r['summary']['Actual loss (₹)'])->toBe('2,000.00')->and($r['summary']['Incidents counted'])->toBe((string) $figures['incidents']);

    // the choices narrow the list
    expect(($this->run)($this->manager, 'loss-incident-register', $this->span + ['choice' => 'duplicates'])['rows'])->toHaveCount(1)
        ->and(($this->run)($this->manager, 'loss-incident-register', $this->span + ['choice' => 'rejected'])['rows'])->toHaveCount(1)
        ->and(($this->run)($this->manager, 'loss-incident-register', $this->span + ['choice' => 'counted'])['rows'])->toHaveCount(3);
});

it('keeps another branch, another company and people without the right out of the loss reports', function () {
    ($this->loss)($this->press, 'component_defect', '600');
    ($this->loss)($this->press, 'line_stoppage', '900', '2026-10-06T08:00', true, $this->mum);

    expect(($this->run)($this->manager, 'loss-incident-register', $this->span)['rows'])->toHaveCount(2)                  // works in every branch
        ->and(($this->run)($this->ahdManager, 'loss-incident-register', $this->span)['rows'])->toHaveCount(1)             // works in Ahmedabad only
        ->and(collect(($this->run)($this->ahdManager, 'loss-incident-register', $this->span)['rows'])->pluck('branch')->unique()->all())->toBe(['AHD Branch'])
        ->and(($this->run)($this->manager, 'loss-incident-register', $this->span + ['branch' => (string) $this->mum->id])['rows'])->toHaveCount(1);

    // another company sees nothing, and cannot name this company's branch
    expect(inCompany($this->globex, function () {
        ($this->as)($this->outsider);
        $report = app(ReportCatalog::class)->find('loss-incident-register');

        return iterator_to_array($report->rows(ReportInput::resolve($report, [], $this->outsider), $this->outsider), false);
    }))->toBe([]);
    expect(fn () => inCompany($this->globex, function () {
        ($this->as)($this->outsider);

        return ReportInput::resolve(app(ReportCatalog::class)->find('loss-incident-register'), ['branch' => (string) $this->ahd->id], $this->outsider);
    }))->toThrow(InvalidReportFilters::class);

    // who may run and export (the loss and finance reports need both report rights and the right to the records)
    $catalog = app(ReportCatalog::class);
    $may = fn ($user, string $key) => [$catalog->find($key)->canRun($user), $catalog->find($key)->canExport($user)];
    expect($may($this->manager, 'loss-incident-register'))->toBe([true, true])
        ->and($may($this->management, 'loss-incident-register'))->toBe([true, false])      // reads, does not export
        ->and($may($this->bde, 'loss-incident-register'))->toBe([false, false])
        ->and($may($this->accounts, 'budget-vs-actual'))->toBe([true, true])
        ->and($may($this->manager, 'budget-vs-actual'))->toBe([false, false])               // loss rights are not budget rights
        ->and($may($this->manager, 'profitability'))->toBe([false, false])
        ->and($may($this->controller, 'profitability'))->toBe([true, true]);
});

it('gives the Pareto and the industry split from the same numbers as the dashboard', function () {
    ($this->loss)($this->press, 'component_defect', '600');
    ($this->loss)($this->press, 'line_stoppage', '1400', '2026-10-06T08:00');
    ($this->loss)($this->paint, 'line_stoppage', '1000', '2026-10-07T08:00');

    $r = ($this->run)($this->manager, 'loss-pareto', $this->span);
    $dash = app(DashboardAnalytics::class);
    $filter = new AnalyticsFilter(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), 'custom');
    ($this->as)($this->manager);
    $expected = $dash->lossCategories($filter);

    expect(collect($r['rows'])->pluck('category')->all())->toBe(['Line stoppage', 'Component defect'])
        ->and(collect($r['rows'])->pluck('amount')->all())->toBe(['2400.00', '600.00'])
        ->and(collect($r['rows'])->pluck('amount')->all())->toBe(collect($expected)->pluck('amount')->all())
        ->and(collect($r['rows'])->pluck('cumulative_pct')->all())->toBe(['80.00', '100.00'])
        ->and($r['page']->total())->toBe(2)
        ->and($r['summary']['Actual loss (₹)'])->toBe('3,000.00')->and($r['summary']['Largest category'])->toBe('Line stoppage');

    $industry = ($this->run)($this->manager, 'loss-by-industry', $this->span);
    expect($industry['rows'])->toHaveCount(1)->and($industry['rows'][0])->toMatchArray(['name' => 'Automotive', 'amount' => '3000.00', 'share_pct' => '100.00']);

    // narrowed to an industry the company uses; an industry it does not use is refused
    expect(($this->run)($this->manager, 'loss-pareto', $this->span + ['choice' => (string) $this->auto->id])['rows'])->toHaveCount(2);
    expect(fn () => ($this->run)($this->manager, 'loss-pareto', $this->span + ['choice' => '999999']))->toThrow(InvalidReportFilters::class);
});

it('lists corrective actions with what is overdue and only the savings a second person verified', function () {
    $i = ($this->loss)($this->press, 'component_defect', '600');
    $make = fn (string $number, string $state, string $due, ?string $result = null) => (new CorrectiveAction)->forceFill([
        'company_id' => $this->acme->id, 'location_id' => $this->ahd->id, 'loss_incident_id' => $i->id, 'action_number' => $number, 'action_type' => 'corrective', 'title' => 'Fix '.$number, 'owner_id' => $this->investigator->id,
        'due_date' => $due, 'state' => $state, 'expected_benefit_amount' => '5000', 'verified_result_amount' => $result, 'verified_at' => $result === null ? null : now(),
    ])->save();
    $make('CA-1', 'open', '2026-10-02');                       // 10 days overdue on 12 October
    $make('CA-2', 'in_progress', '2026-10-20');                // not yet due
    $make('CA-3', 'verified', '2026-09-30', '4200');           // done and verified
    $make('CA-4', 'verified', '2026-10-01', '300');

    $overdue = ($this->run)($this->manager, 'corrective-action-register', ['choice' => 'overdue']);
    expect($overdue['rows'])->toHaveCount(1)->and($overdue['rows'][0])->toMatchArray(['action_number' => 'CA-1', 'days_overdue' => 10, 'state' => 'open', 'owner' => $this->investigator->name]);

    $all = ($this->run)($this->manager, 'corrective-action-register', ['choice' => 'verified']);
    expect($all['rows'])->toHaveCount(2)->and($all['summary']['Verified saving (₹)'])->toBe('4,500.00');
    expect(($this->run)($this->manager, 'corrective-action-register', ['choice' => 'live'])['rows'])->toHaveCount(2)
        ->and(($this->run)($this->investigator, 'corrective-action-register', [])['rows'])->toHaveCount(4);   // nothing asked, nothing narrowed: every action (the screen itself opens on "overdue")
});

// ---- finance --------------------------------------------------------------------------------------------------------------------------------------

it('reports budget against actual with the same rows as BudgetVsActual', function () {
    ($this->budget)($this->press, '2026-10', '10000', 'line_stoppage');
    ($this->budget)($this->paint, '2026-10', '5000');
    ($this->loss)($this->press, 'line_stoppage', '12500');
    ($this->loss)($this->paint, 'component_defect', '1500');

    $r = ($this->run)($this->controller, 'budget-vs-actual', $this->span);
    ($this->as)($this->controller);
    $expected = app(BudgetVsActual::class)->forPeriod(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

    expect($r['rows'])->toHaveCount(count($expected))->and(collect($r['rows'])->pluck('variance')->all())->toBe(collect($expected)->pluck('variance')->all())
        ->and(collect($r['rows'])->firstWhere('cost_center', 'Press shop'))->toMatchArray(['budget' => '10000.00', 'actual' => '12500.00', 'variance' => '2500.00', 'status' => 'over'])
        ->and($r['summary']['Budget (₹)'])->toBe('15,000.00')->and($r['summary']['Actual loss (₹)'])->toBe('14,000.00');

    $paint = ($this->run)($this->controller, 'budget-vs-actual', $this->span + ['choice' => (string) $this->paint->id]);
    expect(collect($paint['rows'])->pluck('cost_center')->unique()->all())->toBe(['Paint shop']);
});

it('reports profitability by product, brand and site, agreeing with the one revenue calculation', function () {
    $brand = inCompany($this->acme, fn () => Brand::query()->create(['code' => 'FASTX', 'name' => 'FastX']));
    $bolt = makeUsableItem($this->acme, 'MS Hex Bolt M10', ['standard_cost' => '100', 'brand_id' => (string) $brand->id]);
    $wire = makeUsableItem($this->acme, 'Steel Wire 2mm', ['base_unit_id' => (string) unitId($this->acme, 'KG'), 'gst_rate' => '12', 'standard_cost' => '60']);
    makeStandardRate($this->acme, $bolt, $this->ahd, '125.50');
    makeStandardRate($this->acme, $wire, $this->ahd, '80');
    $sales = makeUser(['Sales Manager'], [$this->acme]);
    makeTeam($this->acme, $sales, [$this->bde]);
    $customer = makeCustomer($this->acme, 'Shree Ganesh Industries', ['credit_limit' => '5000000', 'credit_days' => '30']);
    $deal = approveDocument($this->acme, makeDeal($this->acme, $this->bde, makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($bolt, '10', ''), lineRow($wire, '2.5', '')]), ['customer_ledger_id' => (string) $customer->id]), $this->bde, $this->approver);
    $invoice = makeSalesOrder($this->acme, $this->bde, $deal, $customer, [], null, 'approved', $this->approver)->invoice;
    $line = SalesInvoiceItem::query()->where('sales_invoice_id', $invoice->id)->where('item_name', 'MS Hex Bolt M10')->firstOrFail();
    $return = inCompany($this->acme, fn () => app(SalesReturnService::class)->create($invoice->fresh(), ['return_type' => 'return', 'reason' => 'Damaged in transit', 'lines' => [$line->id => '4']], $this->accounts));
    approveDocument($this->acme, $return, $this->accounts, $this->approver);

    $byProduct = ($this->run)($this->controller, 'profitability', $this->span);
    $rows = collect($byProduct['rows'])->keyBy('name');
    expect($rows['MS Hex Bolt M10'])->toMatchArray(['gross_sales' => '1255.00', 'returns' => '502.00', 'net_revenue' => '753.00', 'cogs' => '600.00', 'gross_profit' => '153.00'])
        ->and($rows['Steel Wire 2mm'])->toMatchArray(['net_revenue' => '200.00', 'cogs' => '150.00', 'gross_profit' => '50.00', 'margin_pct' => '25.00'])
        ->and($byProduct['summary']['Net revenue (₹)'])->toBe('953.00')->and($byProduct['summary']['Gross profit (₹)'])->toBe('203.00');

    ($this->as)($this->controller);
    $overall = app(NetRevenueCalculator::class)->forPeriod(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
    foreach (['product', 'brand', 'site', 'industry'] as $by) {
        $r = ($this->run)($this->controller, 'profitability', $this->span + ['choice' => $by]);
        expect(collect($r['rows'])->reduce(fn ($c, $row) => bcadd($c, $row['net_revenue'], 2), '0.00'))->toBe($overall['net_revenue'], $by);
    }

    // the returns register: the approved return takes 502.00 (before tax) off net revenue; a draft takes nothing
    $returns = ($this->run)($this->accounts, 'sales-return-register', $this->span);
    expect($returns['rows'])->toHaveCount(1)->and($returns['rows'][0])->toMatchArray(['kind' => 'Return', 'approval' => 'Approved', 'invoice_number' => $invoice->invoice_number])
        ->and($returns['summary']['Approved, before tax (₹)'])->toBe('502.00');
});

// ---- HR ---------------------------------------------------------------------------------------------------------------------------------------------

it('lists employees as far as the person may see them, with no personal details', function () {
    $hire = function (string $code, string $name, $user = null, array $over = []) {
        ($this->as)($this->hr);
        $e = app(EmployeeService::class)->create(['code' => $code, 'name' => $name, 'location_id' => (string) $this->ahd->id, 'joining_date' => '2026-01-05', 'employment_type' => 'permanent', 'date_of_birth' => '1990-05-05', 'mobile' => '9876500000', ...$over], $this->hr);
        $user === null || app(EmployeeService::class)->linkUser($e, $user, $this->hr);

        return $e;
    };
    $boss = $hire('B1', 'Boss', $this->manager);
    $hire('W1', 'Worker', $this->telecaller, ['reports_to_id' => (string) $boss->id]);
    $hire('O1', 'Other');
    $gone = $hire('G1', 'Gone');
    DB::table('employees')->where('id', $gone->id)->update(['employment_status' => 'exited', 'exit_date' => '2026-09-30']);

    $hr = ($this->run)($this->hr, 'employee-register', ['choice' => 'current']);
    expect(collect($hr['rows'])->pluck('code')->sort()->values()->all())->toBe(['B1', 'O1', 'W1'])                          // the default view is "working here"
        ->and(array_keys($hr['rows'][0]))->toBe(['code', 'name', 'designation', 'department', 'branch', 'manager', 'type', 'status', 'joining_date', 'exit_date'])
        ->and(json_encode($hr['rows']))->not->toContain('1990-05-05')->not->toContain('9876500000')
        ->and(collect(($this->run)($this->hr, 'employee-register', ['choice' => 'exited'])['rows'])->pluck('code')->all())->toBe(['G1'])
        ->and(collect(($this->run)($this->manager, 'employee-register', ['choice' => 'current'])['rows'])->pluck('code')->sort()->values()->all())->toBe(['B1', 'W1']);   // the manager's own line
});

it('shows the leave register to a person only within their reach', function () {
    $hire = function (string $code, string $name, $user = null, array $over = []) {
        ($this->as)($this->hr);
        $e = app(EmployeeService::class)->create(['code' => $code, 'name' => $name, 'location_id' => (string) $this->ahd->id, 'joining_date' => '2026-01-05', 'employment_type' => 'permanent', ...$over], $this->hr);
        $user === null || app(EmployeeService::class)->linkUser($e, $user, $this->hr);

        return $e;
    };
    $boss = $hire('B1', 'Boss', $this->manager);
    $worker = $hire('W1', 'Worker', $this->telecaller, ['reports_to_id' => (string) $boss->id]);
    $other = $hire('O1', 'Other', $this->bde);
    inCompany($this->acme, fn () => LeaveType::query()->create(['code' => 'CL', 'name' => 'Casual leave', 'annual_quota' => '12', 'accrual' => 'yearly', 'allow_half_day' => true]));
    ($this->as)($this->hr);
    app(LeaveAccrual::class)->run(now());
    $type = LeaveType::query()->firstOrFail();
    $mine = app(LeaveService::class)->apply($worker, $type, '2026-10-20', '2026-10-21', null, 'Treatment for my private health matter', $this->telecaller);
    app(LeaveService::class)->apply($other, $type, '2026-10-22', '2026-10-22', null, 'Family event', $this->bde);
    ($this->as)($this->manager);
    app(LeaveService::class)->approve($mine, $this->manager);

    $span = ['from' => '2026-10-01', 'to' => '2026-10-31'];
    $leave = ($this->run)($this->manager, 'leave-register', $span);
    expect(collect($leave['rows'])->pluck('employee')->all())->toBe(['Worker'])                                              // their line, not the other team
        ->and($leave['rows'][0])->toMatchArray(['leave_type' => 'Casual leave', 'days' => '2', 'state_label' => 'Approved', 'decider' => $this->manager->name])
        ->and(json_encode($leave['rows']))->not->toContain('private health')
        ->and($leave['summary']['Approved days'])->toBe('2')
        ->and(collect(($this->run)($this->hr, 'leave-register', $span)['rows'])->pluck('employee')->sort()->values()->all())->toBe(['Other', 'Worker'])
        ->and(app(ReportCatalog::class)->find('leave-register')->canRun($this->telecaller))->toBeFalse();
});

// ---- exports and the screens -------------------------------------------------------------------------------------------------------------------

it('exports the loss, finance and HR reports as Excel and PDF with the rows the page shows', function () {
    ($this->loss)($this->press, 'component_defect', '600');
    ($this->loss)($this->press, 'line_stoppage', '1400', '2026-10-06T08:00');
    ($this->budget)($this->press, '2026-10', '1000');

    $ask = function ($user, string $key, string $format = 'xlsx') {
        return inCompany($this->acme, function () use ($user, $key, $format) {
            ($this->as)($user);

            return app(ReportExporter::class)->request(app(ReportCatalog::class)->find($key), $this->span, $format, $user);
        })->refresh();
    };
    $sheet = fn (ReportExport $e) => IOFactory::load(Storage::disk('local')->path($e->file_path))->getActiveSheet()->toArray(null, true, false, false);

    // a query report: the register
    $e = $ask($this->manager, 'loss-incident-register');
    $rows = $sheet($e);
    expect($e->status)->toBe('ready')->and($e->row_count)->toBe(2)->and($rows[0][0])->toBe('Incident')->and(collect($rows)->skip(1)->pluck(9)->map(fn ($v) => (float) $v)->sort()->values()->all())->toBe([600.0, 1400.0]);

    // a service report: the Pareto, in the same order as the page
    $e = $ask($this->manager, 'loss-pareto');
    $rows = $sheet($e);
    expect($e->status)->toBe('ready')->and($e->row_count)->toBe(2)->and(collect($rows)->skip(1)->pluck(0)->all())->toBe(['Line stoppage', 'Component defect']);

    // a PDF of a finance report, and an Excel of the budget report
    expect($ask($this->controller, 'budget-vs-actual', 'pdf')->status)->toBe('ready')->and($ask($this->controller, 'budget-vs-actual')->row_count)->toBe(3);   // the all-categories budget and the two categories that lost money

    // someone who may read but not export is refused, and so is a report they may not run
    expect(fn () => $ask($this->management, 'loss-incident-register'))->toThrow(AuthorizationException::class)
        ->and(fn () => $ask($this->manager, 'profitability'))->toThrow(AuthorizationException::class);

    // HR may export the employee register now that it holds the export right
    expect(app(ReportCatalog::class)->find('employee-register')->canExport($this->hr))->toBeTrue();
});

it('opens every new report from the report centre for those who may, and lists them under their headings', function () {
    ($this->loss)($this->press, 'component_defect', '600');

    foreach (['loss-incident-register', 'loss-pareto', 'loss-by-industry', 'corrective-action-register'] as $key) {
        $this->actingAs($this->manager)->get(route('reports.run', $key))->assertOk();
    }
    foreach (['budget-vs-actual', 'profitability', 'sales-return-register'] as $key) {
        $this->actingAs($this->controller)->get(route('reports.run', $key))->assertOk();
    }
    foreach (['employee-register', 'leave-register'] as $key) {
        $this->actingAs($this->hr)->get(route('reports.run', $key))->assertOk();
    }

    $this->actingAs($this->manager)->get(route('reports.run', 'budget-vs-actual'))->assertForbidden();
    $this->actingAs($this->bde)->get(route('reports.run', 'loss-incident-register'))->assertForbidden();
    $this->actingAs($this->manager)->get(route('reports.index'))->assertSee('Loss incident register')->assertSee('Loss by category (Pareto)')->assertDontSee('Budget against actual loss');
    $this->actingAs($this->controller)->get(route('reports.index'))->assertSee('Budget against actual loss')->assertSee('Profitability');

    Livewire::actingAs($this->manager)->test(ReportRun::class, ['key' => 'loss-pareto'])->assertSee('Component defect');
});

it('stops one person flooding the export queue, and lets a stuck export stop counting after an hour', function () {
    Queue::fake();
    $ask = fn () => inCompany($this->acme, function () {
        ($this->as)($this->manager);

        return app(ReportExporter::class)->request(app(ReportCatalog::class)->find('loss-incident-register'), $this->span, 'xlsx', $this->manager);
    });

    foreach (range(1, ReportExporter::MAX_WAITING) as $n) {
        $ask();
    }

    expect($ask)->toThrow(InvalidReportFilters::class, 'already have 5 exports waiting');

    // somebody else is not held up by it
    expect(inCompany($this->acme, function () {
        ($this->as)($this->accounts);

        return app(ReportExporter::class)->request(app(ReportCatalog::class)->find('sales-return-register'), $this->span, 'xlsx', $this->accounts);
    })->status)->toBe('queued');

    // a job stuck for over an hour no longer blocks the person
    DB::table('report_exports')->where('user_id', $this->manager->id)->update(['created_at' => now()->subMinutes(90)]);
    expect($ask()->status)->toBe('queued');
});

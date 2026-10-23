<?php

use App\Modules\CRM\Models\Deal;
use App\Modules\Reports\Exceptions\InvalidReportFilters;
use App\Modules\Reports\Http\Livewire\ReportRun;
use App\Modules\Reports\Reports\DealPipeline;
use App\Modules\Reports\Reports\LeadRegister;
use App\Modules\Reports\Reports\SalesOrderRegister;
use App\Modules\Reports\Services\ReportCatalog;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * The baseline reports: each one runs, shows a person only what that person may already see on the report's own screen (own / team / all, the
 * branches they work in, one company), refuses filters the person could not have chosen, and keeps approved results apart from forecasts.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME', ['state_code' => '24']);
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);
    $this->gx = makeLocation($this->globex, 'GX', ['state_code' => '24']);

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->operations = makeUser(['Operations User'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);

    $this->customer = makeCustomer($this->acme, 'Shree Ganesh Industries', ['credit_limit' => '5000000', 'credit_days' => '30']);
    $this->bolt = makeUsableItem($this->acme);
    makeStandardRate($this->acme, $this->bolt, $this->ahd, '125.50');

    // a lead each for the two salespeople, and one in another company
    $this->lead1 = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Alpha Works'], [lineRow($this->bolt, '10', '')]);
    $this->lead2 = makeLead($this->acme, $this->bde2, $this->ahd, ['name' => 'Beta Works'], [lineRow($this->bolt, '10', '')]);
    $this->foreign = makeLead($this->globex, $this->globexAdmin, $this->gx, ['name' => 'Foreign Works']);

    $this->deal = fn ($lead, $by) => approveDocument($this->acme, makeDeal($this->acme, $by, qualify($this->acme, $lead, $by), ['customer_ledger_id' => (string) $this->customer->id]), $by, $this->approver);

    $this->run = function ($user, string $key, array $filters = []): array {
        return inCompany($this->acme, function () use ($user, $key, $filters) {
            Auth::setUser($user);
            app(TenantContext::class)->flush();
            $report = app(ReportCatalog::class)->find($key);
            $input = ReportInput::resolve($report, $filters, $user);

            return ['report' => $report, 'page' => $report->page($input, $user, 100), 'summary' => $report->summary($input, $user), 'all' => iterator_to_array($report->rows($input, $user), false)];
        });
    };
});

it('lists forty reports with unique keys, and says which baseline reports wait for later phases', function () {
    $catalog = app(ReportCatalog::class);

    expect($catalog->all())->toHaveCount(40)
        ->and(array_keys($catalog->all()))->toBe(array_values(array_unique(array_keys($catalog->all()))))
        ->and(collect($catalog->planned())->pluck('title')->all())->toBe(['AI scoring and recommendation activity']);

    foreach ($catalog->all() as $key => $report) {
        expect($report->key())->toBe($key)->and($report->columns())->not->toBeEmpty()->and($report->title())->not->toBe('');
    }
});

it('runs every report for an administrator, and the screen and the export read the same rows', function () {
    ($this->deal)($this->lead1->fresh(), $this->bde);
    $deal = Deal::query()->withoutGlobalScopes(Tenancy::SCOPES)->sole();
    makeSalesOrder($this->acme, $this->bde, $deal, $this->customer, [], null, 'approved', $this->approver);

    foreach (array_keys(app(ReportCatalog::class)->all()) as $key) {
        $result = ($this->run)($this->admin, $key);

        expect($result['page']->total())->toBe(count($result['all']), $key)
            ->and(array_keys($result['all'][0] ?? array_fill_keys(array_map(fn ($c) => $c->key, $result['report']->columns()), null)))
            ->toBe(array_map(fn ($c) => $c->key, $result['report']->columns()), $key);
    }
});

it('shows a salesperson only their own leads, a manager their team’s, and never another company’s', function () {
    $own = collect(($this->run)($this->bde, 'lead-register')['all'])->pluck('name')->all();
    $team = collect(($this->run)($this->manager, 'lead-register')['all'])->pluck('name')->all();
    $company = collect(($this->run)($this->admin, 'lead-register')['all'])->pluck('name')->all();

    expect($own)->toBe(['Alpha Works'])
        ->and($team)->toContain('Alpha Works')->not->toContain('Beta Works')
        ->and($company)->toContain('Alpha Works', 'Beta Works')->not->toContain('Foreign Works');
});

it('requires both the right to see reports and the right to see the records a report is about', function () {
    // a tele-caller has leads but no right to reports; operations have reports but not leads
    $this->actingAs($this->telecaller)->get(route('reports.index'))->assertForbidden();
    $this->actingAs($this->telecaller)->get(route('reports.run', 'lead-register'))->assertForbidden();
    $this->actingAs($this->operations)->get(route('reports.index'))->assertOk()->assertSee('Sales order register')->assertDontSee('Lead register');
    $this->actingAs($this->operations)->get(route('reports.run', 'lead-register'))->assertForbidden();
    $this->actingAs($this->operations)->get(route('reports.run', 'sales-order-register'))->assertOk();
    $this->actingAs($this->operations)->get(route('reports.run', 'audit-activity'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('reports.run', 'audit-activity'))->assertOk();
    $this->actingAs($this->admin)->get(route('reports.run', 'no-such-report'))->assertNotFound();
    $this->actingAs($this->manager)->get(route('reports.run', 'integration-failures'))->assertForbidden();
});

it('refuses a person, team, branch or date the user could not have chosen, rather than trusting the address', function () {
    $try = fn ($user, array $filters) => inCompany($this->acme, function () use ($user, $filters) {
        Auth::setUser($user);
        app(TenantContext::class)->flush();

        try {
            ReportInput::resolve(new LeadRegister, $filters, $user);
        } catch (InvalidReportFilters $e) {
            return array_keys($e->problems);
        }

        return [];
    });

    expect($try($this->bde, ['person' => (string) $this->bde2->id]))->toBe(['person'])      // a colleague outside their reach
        ->and($try($this->bde, ['person' => (string) $this->bde->id]))->toBe([])
        ->and($try($this->manager, ['person' => (string) $this->bde->id]))->toBe([])
        ->and($try($this->manager, ['person' => (string) $this->bde2->id]))->toBe(['person'])
        ->and($try($this->bde, ['team' => (string) $this->team->id]))->toBe(['team'])        // not a team they run
        ->and($try($this->admin, ['branch' => (string) $this->gx->id]))->toBe(['branch'])    // another company’s branch
        ->and($try($this->admin, ['source' => '999999']))->toBe(['source'])
        ->and($try($this->admin, ['from' => '2026-13-45']))->toBe(['from'])
        ->and($try($this->admin, ['from' => '2026-10-20', 'to' => '2026-10-01']))->toBe(['to'])
        ->and($try($this->admin, ['choice' => 'nonsense']))->toBe(['choice'])
        ->and($try($this->admin, ['person' => '1; drop table users', 'from' => "2026-10-01' or 1=1"]))->toBe(['from', 'person']);

    // and the page shows the refusal instead of the rows
    Livewire::actingAs($this->bde)->withQueryParams(['person' => (string) $this->bde2->id])->test(ReportRun::class, ['key' => 'lead-register'])
        ->assertSee('That person is not one you can report on.')->assertDontSee('Beta Works');
});

it('narrows by date, owner, source and stage', function () {
    $old = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Gamma Works', 'lead_date' => '2026-08-01']);

    $names = fn (array $filters, $user = null) => collect(($this->run)($user ?? $this->admin, 'lead-register', $filters)['all'])->pluck('name')->sort()->values()->all();

    expect($names(['from' => '2026-10-01']))->toBe(['Alpha Works', 'Beta Works'])
        ->and($names(['to' => '2026-09-30']))->toBe(['Gamma Works'])
        ->and($names(['person' => (string) $this->bde2->id]))->toBe(['Beta Works'])
        ->and($names(['choice' => 'won']))->toBe([])
        ->and($names(['choice' => 'new']))->toBe(['Alpha Works', 'Beta Works', 'Gamma Works'])
        ->and($names(['team' => (string) $this->team->id], $this->manager))->toBe(['Alpha Works', 'Gamma Works']);
});

it('counts only approved, uncancelled orders as sales, and says so', function () {
    $d1 = ($this->deal)($this->lead1->fresh(), $this->bde);
    makeSalesOrder($this->acme, $this->bde, $d1, $this->customer, [], null, 'approved', $this->approver);
    $d2 = ($this->deal)($this->lead2->fresh(), $this->bde2);
    $draft = makeSalesOrder($this->acme, $this->bde2, $d2, $this->customer);

    $result = ($this->run)($this->admin, 'sales-order-register');
    $approved = inCompany($this->acme, fn () => SalesOrder::query()->where('approval_status', 'approved')->sole());

    expect($result['page']->total())->toBe(2)
        ->and($result['summary']['Approved (actual)'])->toBe('1')
        ->and($result['summary']['Approved, net of tax'])->toBe('₹ '.number_format((float) $approved->taxable_total, 2))
        ->and($result['summary']['Not approved yet (not counted)'])->toBe('1')
        ->and((new SalesOrderRegister)->note())->toContain('ACTUAL');

    // the salesperson with the draft sees their own order only, and the pending report lists it
    $mine = ($this->run)($this->bde2, 'pending-sales-orders');
    expect(collect($mine['all'])->pluck('so_number')->all())->toBe([$draft->so_number])
        ->and($mine['all'][0]['waiting_for'])->toBe('Being drafted')
        ->and(($this->run)($this->bde, 'pending-sales-orders')['all'])->toBe([]);   // theirs is approved and invoiced: nothing is pending
});

it('weights the pipeline by probability and labels it a forecast', function () {
    $deal = ($this->deal)($this->lead1->fresh(), $this->bde);
    inCompany($this->acme, fn () => $deal->forceFill(['expected_value' => '100000.00', 'probability' => 40])->saveQuietly());

    $result = ($this->run)($this->admin, 'deal-pipeline', ['choice' => 'open']);

    expect($result['all'])->toHaveCount(1)
        ->and((string) $result['all'][0]['weighted_value'])->toBe('40000.00')
        ->and($result['summary']['Weighted value (forecast)'])->toBe('₹ 40,000.00')
        ->and((new DealPipeline)->note())->toContain('FORECAST')
        ->and(collect((new DealPipeline)->columns())->pluck('label')->all())->toContain('Weighted value (forecast)');
});

it('lists what is waiting for the person in pending approvals, and everything for a holder of the override right', function () {
    $deal = ($this->deal)($this->lead1->fresh(), $this->bde);
    $order = makeSalesOrder($this->acme, $this->bde, $deal, $this->customer, [], null, 'pending');

    $approver = ($this->run)($this->approver, 'pending-approvals');
    $admin = ($this->run)($this->admin, 'pending-approvals');
    $maker = ($this->run)($this->bde, 'pending-approvals');

    expect(collect($approver['all'])->pluck('number')->all())->toContain($order->so_number)
        ->and(collect($admin['all'])->pluck('number')->all())->toContain($order->so_number)
        ->and(collect($maker['all'])->pluck('number')->all())->not->toContain($order->so_number);
});

it('never shows a buyer’s payload in the integration report, and keeps the audit report to the audit right', function () {
    $columns = collect(app(ReportCatalog::class)->find('integration-failures')->columns())->pluck('key')->all();

    expect($columns)->not->toContain('payload')
        ->and(collect(app(ReportCatalog::class)->find('audit-activity')->columns())->pluck('key')->all())->not->toContain('properties');
});

it('prints the menu entry only to those who may open reports', function () {
    $this->actingAs($this->manager)->get(route('dashboard'))->assertOk()->assertSee(route('reports.index'), false);
    $this->actingAs($this->telecaller)->get(route('dashboard'))->assertOk()->assertDontSee(route('reports.index'), false);
});

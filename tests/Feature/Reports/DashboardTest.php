<?php

use App\Modules\CRM\Models\Deal;
use App\Modules\Reports\Http\Livewire\SalesDashboardPanel;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * The sales dashboard shows each person what they may already see — their own work, their team’s, the company’s — only for the sections they
 * may open, with approved results (actual) and open-deal estimates (forecast) kept apart.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME', ['state_code' => '24']);
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);
    $this->mum = makeLocation($this->acme, 'MUM', ['state_code' => '27']);

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->approver = makeUser(['Approver'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);

    $this->customer = makeCustomer($this->acme, 'Shree Ganesh Industries', ['credit_limit' => '5000000', 'credit_days' => '30']);
    $this->bolt = makeUsableItem($this->acme);
    makeStandardRate($this->acme, $this->bolt, $this->ahd, '125.50');

    $this->sale = function ($by, string $quantity = '10') {
        $lead = makeLead($this->acme, $by, $this->ahd, [], [lineRow($this->bolt, $quantity, '')]);
        $deal = approveDocument($this->acme, makeDeal($this->acme, $by, qualify($this->acme, $lead, $by), ['customer_ledger_id' => (string) $this->customer->id]), $by, $this->approver);

        return makeSalesOrder($this->acme, $by, $deal, $this->customer, [], null, 'approved', $this->approver);
    };
    $this->data = fn ($user, array $query = []) => Livewire::actingAs($user)->withQueryParams($query)->test(SalesDashboardPanel::class)->viewData('data');
});

it('shows a salesperson their own approved sales, a manager their team’s, an administrator the company’s', function () {
    $mine = ($this->sale)($this->bde, '10');
    $theirs = ($this->sale)($this->bde2, '30');

    $own = ($this->data)($this->bde)['orders'];
    $team = ($this->data)($this->manager)['orders'];
    $all = ($this->data)($this->admin)['orders'];

    expect($own['count'])->toBe(1)->and((float) $own['net'])->toBe((float) $mine->taxable_total)
        ->and($team['count'])->toBe(1)->and((float) $team['net'])->toBe((float) $mine->taxable_total)
        ->and($all['count'])->toBe(2)->and((float) $all['net'])->toBe((float) $mine->taxable_total + (float) $theirs->taxable_total)
        ->and(collect($all['people'])->pluck('label')->all())->toBe([$this->bde2->name, $this->bde->name])   // biggest first
        ->and(collect($all['teams'])->pluck('label')->all())->toBe(['Field team']);                          // only the team’s member counts for it
});

it('leaves out an order that is not approved, is cancelled, or is dated outside the period', function () {
    $approved = ($this->sale)($this->bde);
    $lead = makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', '')]);
    $deal = approveDocument($this->acme, makeDeal($this->acme, $this->bde, qualify($this->acme, $lead, $this->bde), ['customer_ledger_id' => (string) $this->customer->id]), $this->bde, $this->approver);
    makeSalesOrder($this->acme, $this->bde, $deal, $this->customer, [], null, 'pending');   // waiting, not a result

    expect(($this->data)($this->admin)['orders']['count'])->toBe(1)->and(($this->data)($this->admin)['orders']['waiting'])->toBe(1);

    // a period that ended before the order: nothing
    Carbon::setTestNow('2026-12-20 09:00:00');
    expect(($this->data)($this->admin, ['period' => 'today'])['orders']['count'])->toBe(0);
    Carbon::setTestNow('2026-10-12 09:30:00');

    inCompany($this->acme, fn () => SalesOrder::query()->whereKey($approved->id)->update(['state' => 'cancelled']));
    expect(($this->data)($this->admin)['orders']['count'])->toBe(0);
});

it('gives only the sections a person may open, and nothing at all to someone with none', function () {
    $telecaller = ($this->data)($this->telecaller);
    $accounts = ($this->data)($this->accounts);

    expect($telecaller['leads'])->not->toBeNull()->and($telecaller['orders'])->toBeNull()->and($telecaller['deals'])->toBeNull()->and($telecaller['quotes'])->toBeNull()->and($telecaller['targets'])->toBeNull()
        ->and($accounts['leads'])->toBeNull()->and($accounts['orders'])->not->toBeNull();

    // a person with none of the sales rights sees no sales dashboard on the home page at all
    $nobody = makeUser([], [$this->acme]);
    $this->actingAs($nobody)->get(route('dashboard'))->assertOk()->assertDontSee('Sales dashboard');
    $this->actingAs($this->manager)->get(route('dashboard'))->assertOk()->assertSee('Sales dashboard')->assertSee('Forecast')->assertSee('Actual');
});

it('counts the funnel from the leads’ stages and the pipeline by probability, as a forecast', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd, [], [lineRow($this->bolt, '10', '')]);
    $deal = approveDocument($this->acme, makeDeal($this->acme, $this->bde, qualify($this->acme, $lead, $this->bde), ['customer_ledger_id' => (string) $this->customer->id]), $this->bde, $this->approver);
    inCompany($this->acme, fn () => $deal->forceFill(['expected_value' => '200000.00', 'probability' => 60])->saveQuietly());
    makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Still new']);

    $data = ($this->data)($this->admin);
    $funnel = collect($data['leads']['funnel'])->pluck('value', 'label');
    $band = collect($data['deals']['bands'])->firstWhere('label', '50 – 74 %');

    expect($funnel['Leads made'])->toBe(2)->and($funnel['Became deals'])->toBe(1)->and($funnel['Won'])->toBe(0)
        ->and($band['count'])->toBe(1)->and($band['weighted'])->toBe('120000.00')
        ->and($data['deals']['weighted'])->toBe('120000.00')->and($data['deals']['value'])->toBe('200000.00');
});

it('counts overdue and today’s follow-ups for the person and their team only', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $other = makeLead($this->acme, $this->bde2, $this->ahd);
    Carbon::withTestNow('2026-10-08 09:00:00', function () use ($lead, $other) {   // planned when it was still in the future
        makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-10 11:00:00']);
        makeFollowup($this->acme, $this->bde2, $other, ['scheduled_at' => '2026-10-09 11:00:00']);
    });
    makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-12 16:00:00']);

    expect(($this->data)($this->bde)['followups'])->toMatchArray(['overdue' => 1, 'today' => 1])
        ->and(($this->data)($this->manager)['followups'])->toMatchArray(['overdue' => 1, 'today' => 1])
        ->and(($this->data)($this->admin)['followups'])->toMatchArray(['overdue' => 2, 'today' => 1]);
});

it('limits the dashboard to one branch on request, and ignores a branch the person cannot reach', function () {
    ($this->sale)($this->bde);

    expect(($this->data)($this->admin, ['branch' => (string) $this->mum->id])['orders']['count'])->toBe(0)
        ->and(($this->data)($this->admin, ['branch' => (string) $this->ahd->id])['orders']['count'])->toBe(1)
        ->and(($this->data)($this->admin, ['branch' => '99999'])['orders']['count'])->toBe(1);   // not a branch: the filter is dropped, not trusted

    $rows = collect(($this->data)($this->admin)['branches'])->keyBy('label');
    expect($rows['AHD Branch']['leads'] ?? $rows->first()['leads'])->toBeGreaterThanOrEqual(1);
});

it('shows campaign attribution, and says so when no campaign has been launched', function () {
    Livewire::actingAs($this->manager)->test(SalesDashboardPanel::class)->assertSee('Campaign attribution')->assertSee('No campaign has been launched yet');
});

<?php

use App\Modules\CRM\Enums\FollowupStatus;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Http\Livewire\Followups\FollowupCalendar;
use App\Modules\CRM\Http\Livewire\Followups\FollowupList;
use App\Modules\CRM\Http\Livewire\Leads\LeadForm;
use App\Modules\CRM\Http\Livewire\Leads\LeadList;
use App\Modules\CRM\Http\Livewire\Leads\LeadShow;
use App\Modules\CRM\Http\Livewire\Masters\FollowupOutcomeList;
use App\Modules\CRM\Http\Livewire\Masters\LeadSourceList;
use App\Modules\CRM\Http\Livewire\Masters\LostReasonList;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\FollowupOutcome;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadInterest;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\LeadTransfer;
use App\Modules\CRM\Models\LostReason;
use App\Modules\CRM\Services\FollowupService;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');   // a Monday
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->geo = seedGeography($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->otherManager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->loner = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);     // on nobody's team
    $this->mumBde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->mum]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->bde2]);
    $this->westTeam = makeTeam($this->acme, $this->otherManager, [$this->mumBde], 'WEST', 'West team');

    $this->row = fn (Lead $lead) => 'wire:key="lead-'.$lead->id.'"';
    $this->fuRow = fn (Followup $followup) => 'wire:key="fu-'.$followup->id.'"';
    $this->stage = fn (Lead $lead, LeadStage $to, ?string $comment = null, ?int $reason = null) => inCompany($this->acme, fn () => app(LeadService::class)->changeStage($lead, $to, $this->admin, $comment, $reason));
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// Who can open what
// ======================================================================================================

it('lets each role open only the lead screens that are its own', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);

    $status = fn ($user, string $route, $model = null) => $this->actingAs($user)->get($model ? route($route, $model) : route($route))->status();

    // the salesperson: their leads and follow-ups; no importing, no masters
    expect($status($this->bde, 'crm.leads.index'))->toBe(200)->and($status($this->bde, 'crm.leads.create'))->toBe(200)
        ->and($status($this->bde, 'crm.leads.show', $lead))->toBe(200)->and($status($this->bde, 'crm.leads.edit', $lead))->toBe(200)
        ->and($status($this->bde, 'crm.followups.index'))->toBe(200)->and($status($this->bde, 'crm.followups.calendar'))->toBe(200)
        ->and($status($this->bde, 'crm.leads.import'))->toBe(403)->and($status($this->bde, 'crm.masters.sources'))->toBe(403);

    // a colleague, a salesperson of another branch and a tele-caller are strangers to this lead
    expect($status($this->loner, 'crm.leads.show', $lead))->toBe(403)->and($status($this->loner, 'crm.leads.edit', $lead))->toBe(403)
        ->and($status($this->bde2, 'crm.leads.show', $lead))->toBe(403)
        ->and($status($this->mumBde, 'crm.leads.show', $lead))->toBe(404)                 // not even in their branch
        ->and($status($this->telecaller, 'crm.leads.show', $lead))->toBe(403)
        ->and($status($this->telecaller, 'crm.leads.index'))->toBe(200)->and($status($this->telecaller, 'crm.leads.create'))->toBe(200);

    // the manager works their team's leads and may import
    expect($status($this->manager, 'crm.leads.show', $lead))->toBe(200)->and($status($this->manager, 'crm.leads.edit', $lead))->toBe(200)
        ->and($status($this->manager, 'crm.leads.import'))->toBe(200)->and($status($this->manager, 'crm.masters.sources'))->toBe(403);

    // another manager has no reach over this team's lead
    expect($status($this->otherManager, 'crm.leads.show', $lead))->toBe(403)->and($status($this->otherManager, 'crm.leads.edit', $lead))->toBe(403);

    // an administrator sees everything and keeps the lead masters
    foreach (['crm.leads.index', 'crm.leads.create', 'crm.leads.import', 'crm.followups.index', 'crm.followups.calendar', 'crm.masters.sources', 'crm.masters.outcomes', 'crm.masters.lost-reasons'] as $route) {
        expect($status($this->admin, $route))->toBe(200, $route);
    }
    expect($status($this->admin, 'crm.leads.show', $lead))->toBe(200)->and($status($this->admin, 'crm.leads.edit', $lead))->toBe(200);

    // Accounts has no business with leads
    foreach (['crm.leads.index', 'crm.leads.create', 'crm.leads.import', 'crm.followups.index', 'crm.followups.calendar', 'crm.masters.sources'] as $route) {
        expect($status($this->accounts, $route))->toBe(403, "{$route} for Accounts");
    }
    expect($status($this->accounts, 'crm.leads.show', $lead))->toBe(403);
});

it('keeps guests out and other companies’ leads unreachable', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);

    foreach (['crm.leads.index', 'crm.leads.create', 'crm.leads.import', 'crm.followups.index', 'crm.followups.calendar', 'crm.masters.sources', 'crm.masters.outcomes', 'crm.masters.lost-reasons'] as $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    }
    $this->get(route('crm.leads.show', $lead))->assertRedirect(route('login'));

    $this->actingAs($this->globexAdmin)->get(route('crm.leads.show', $lead))->assertNotFound();
    $this->actingAs($this->globexAdmin)->get(route('crm.leads.edit', $lead))->assertNotFound();
    $this->actingAs($this->globexAdmin)->get(route('crm.leads.index'))->assertOk()->assertDontSee($lead->lead_number);
});

it('links the CRM menu only for those who may use it', function () {
    $href = fn (string $route) => 'href="'.route($route).'"';

    $this->actingAs($this->bde)->get(route('dashboard'))->assertOk()->assertSee($href('crm.leads.index'), false)->assertSee($href('crm.followups.index'), false)
        ->assertSee($href('crm.followups.calendar'), false)->assertDontSee($href('crm.masters.sources'), false);
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee($href('crm.masters.sources'), false);
    $this->actingAs($this->accounts)->get(route('dashboard'))->assertOk()->assertDontSee($href('crm.leads.index'), false)->assertDontSee($href('crm.followups.index'), false);
});

// ======================================================================================================
// The lead list
// ======================================================================================================

it('lists exactly the leads a person may see', function () {
    $mine = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Mine Traders']);
    $colleague = makeLead($this->acme, $this->bde2, $this->ahd, ['name' => 'Colleague Traders']);
    $lonely = makeLead($this->acme, $this->loner, $this->ahd, ['name' => 'Lonely Traders']);
    $pool = makeLead($this->acme, $this->manager, $this->ahd, ['assigned_to' => '', 'name' => 'Pool Traders']);
    $mumbai = makeLead($this->acme, $this->mumBde, $this->mum, ['name' => 'Mumbai Traders']);
    makeFollowup($this->acme, $this->bde, $mine, ['scheduled_at' => '2026-10-13T11:00']);

    Livewire::actingAs($this->bde)->test(LeadList::class)
        ->assertSeeHtml(($this->row)($mine))->assertSee('Mine Traders')->assertSee($mine->lead_number)->assertSee('13 Oct, 11:00')
        ->assertDontSeeHtml(($this->row)($colleague))->assertDontSeeHtml(($this->row)($lonely))->assertDontSeeHtml(($this->row)($pool))->assertDontSeeHtml(($this->row)($mumbai))
        ->assertDontSee('Select this page')                          // handing leads over is not theirs
        ->assertDontSee('Import from Excel')->assertSee('Add a lead');

    Livewire::actingAs($this->manager)->test(LeadList::class)
        ->assertSeeHtml(($this->row)($mine))->assertSeeHtml(($this->row)($colleague))->assertSeeHtml(($this->row)($pool))
        ->assertDontSeeHtml(($this->row)($lonely))->assertDontSeeHtml(($this->row)($mumbai))
        ->assertSee('Select this page')->assertSee('Import from Excel')->assertSee('Unassigned');

    Livewire::actingAs($this->admin)->test(LeadList::class)
        ->assertSeeHtml(($this->row)($mine))->assertSeeHtml(($this->row)($lonely))->assertSeeHtml(($this->row)($mumbai))->assertSeeHtml(($this->row)($pool));

    Livewire::actingAs($this->mumBde)->test(LeadList::class)->assertSeeHtml(($this->row)($mumbai))->assertDontSeeHtml(($this->row)($mine));
});

it('counts the stages over what the person may see, and shows the stage that was asked for', function () {
    $new = makeLead($this->acme, $this->bde, $this->ahd);
    $contacted = makeLead($this->acme, $this->bde, $this->ahd);
    $lost = makeLead($this->acme, $this->bde, $this->ahd);
    makeLead($this->acme, $this->loner, $this->ahd);                    // not the salesperson's: not counted for them
    ($this->stage)($contacted, LeadStage::Contacted);
    ($this->stage)($lost, LeadStage::Lost, null, lostReasonId($this->acme));

    $list = Livewire::actingAs($this->bde)->test(LeadList::class);

    expect($list->viewData('tabs'))->toBe([['open', 'Open', 2], ['new', 'New', 1], ['contacted', 'Contacted', 1], ['qualified', 'Qualified', 0], ['lost', 'Lost', 1], ['', 'All', 3]]);

    // open by default: the lost lead is not in the way
    $list->assertSet('stage', 'open')->assertSeeHtml(($this->row)($new))->assertSeeHtml(($this->row)($contacted))->assertDontSeeHtml(($this->row)($lost));
    $list->call('setStage', 'lost')->assertSeeHtml(($this->row)($lost))->assertDontSeeHtml(($this->row)($new));
    $list->call('setStage', '')->assertSeeHtml(($this->row)($lost))->assertSeeHtml(($this->row)($new));
    $list->call('setStage', 'nonsense')->assertSet('stage', 'open');

    // a stage that only a conversion sets appears once there is something in it
    Lead::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($new->id)->update(['stage' => 'deal']);
    expect(collect(Livewire::actingAs($this->bde)->test(LeadList::class)->viewData('tabs'))->pluck(1)->all())->toContain('Deal');
});

it('filters the list by what was typed, the owner, the branch, the source, the dates and the next step', function () {
    $ganesh = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Shree Ganesh Traders', 'mobile' => '9876543210', 'city' => 'Surat', 'lead_date' => '2026-10-01']);
    $patel = makeLead($this->acme, $this->bde2, $this->ahd, ['name' => 'Patel Polymers', 'contact_person' => 'Rajesh Patel', 'lead_source_id' => (string) leadSourceId($this->acme, 'WEBSITE')]);
    $pool = makeLead($this->acme, $this->admin, $this->mum, ['name' => 'Pool Plastics', 'assigned_to' => '']);
    makeFollowup($this->acme, $this->bde, $ganesh, ['scheduled_at' => '2026-10-12T15:00']);       // later today
    makeFollowup($this->acme, $this->bde2, $patel, ['scheduled_at' => '2026-10-16T10:00']);       // on Friday

    $shown = function (array $filters) use ($ganesh, $patel, $pool) {
        $list = Livewire::actingAs($this->admin)->test(LeadList::class);
        foreach ($filters as $property => $value) {
            $list->set($property, $value);
        }

        return collect(['ganesh' => $ganesh, 'patel' => $patel, 'pool' => $pool])->filter(fn (Lead $lead) => str_contains($list->html(), ($this->row)($lead)))->keys()->all();
    };

    expect($shown([]))->toBe(['ganesh', 'patel', 'pool'])
        ->and($shown(['search' => 'ganesh']))->toBe(['ganesh'])
        ->and($shown(['search' => $patel->lead_number]))->toBe(['patel'])
        ->and($shown(['search' => '98765 432']))->toBe(['ganesh'])                 // part of a mobile number, as people type it
        ->and($shown(['search' => 'Rajesh']))->toBe(['patel'])
        ->and($shown(['search' => 'surat']))->toBe(['ganesh'])
        ->and($shown(['search' => '100%']))->toBe([])                              // a wildcard is just a character
        ->and($shown(['owner' => (string) $this->bde2->id]))->toBe(['patel'])
        ->and($shown(['owner' => 'none']))->toBe(['pool'])
        ->and($shown(['branch' => (string) $this->mum->id]))->toBe(['pool'])
        ->and($shown(['source' => (string) leadSourceId($this->acme, 'WEBSITE')]))->toBe(['patel'])
        ->and($shown(['from' => '2026-10-05']))->toBe(['patel', 'pool'])
        ->and($shown(['to' => '2026-10-05']))->toBe(['ganesh'])
        ->and($shown(['due' => 'today']))->toBe(['ganesh'])
        ->and($shown(['due' => 'week']))->toBe(['ganesh', 'patel'])
        ->and($shown(['due' => 'none']))->toBe(['pool'])
        ->and($shown(['due' => 'overdue']))->toBe([]);

    Carbon::setTestNow('2026-10-13 09:00:00');
    expect($shown(['due' => 'overdue']))->toBe(['ganesh']);

    Livewire::actingAs($this->admin)->test(LeadList::class)->assertSee('Overdue');
});

it('never lets a filter widen what a person may see', function () {
    $colleague = makeLead($this->acme, $this->loner, $this->ahd, ['name' => 'Hidden Traders']);
    $pool = makeLead($this->acme, $this->manager, $this->ahd, ['assigned_to' => '']);

    // asking for somebody else's leads, the pool, or searching for a lead by name: still nothing
    Livewire::actingAs($this->bde)->test(LeadList::class)
        ->set('owner', (string) $this->loner->id)->assertDontSeeHtml(($this->row)($colleague))
        ->set('owner', 'none')->assertDontSeeHtml(($this->row)($pool))
        ->set('owner', '')->set('stage', '')->set('search', 'Hidden')->assertDontSeeHtml(($this->row)($colleague))->assertSee('No leads match.');

    // and the owner filter offers only the people they can see
    expect(array_keys(Livewire::actingAs($this->bde)->test(LeadList::class)->viewData('people')))->toBe([$this->bde->id])
        ->and(Livewire::actingAs($this->bde)->test(LeadList::class)->viewData('showPool'))->toBeFalse();
});

it('pages through a long list', function () {
    for ($i = 1; $i <= LeadList::PER_PAGE + 3; $i++) {
        makeLead($this->acme, $this->bde, $this->ahd, ['name' => "Lead {$i}"]);
    }

    $list = Livewire::actingAs($this->bde)->test(LeadList::class);

    expect($list->viewData('leads')->total())->toBe(LeadList::PER_PAGE + 3)->and($list->viewData('leads')->count())->toBe(LeadList::PER_PAGE);

    $list->call('gotoPage', 2);
    expect($list->viewData('leads')->count())->toBe(3);

    // a new filter starts from the first page again
    $list->set('search', 'Lead 1');
    expect($list->viewData('leads')->currentPage())->toBe(1);
});

// ======================================================================================================
// Handing leads over from the list
// ======================================================================================================

it('lets a manager tick leads and hand them over in one go', function () {
    $a = makeLead($this->acme, $this->bde, $this->ahd);
    $b = makeLead($this->acme, $this->bde, $this->ahd);
    $c = makeLead($this->acme, $this->manager, $this->ahd, ['assigned_to' => '']);

    $list = Livewire::actingAs($this->manager)->test(LeadList::class)
        ->set('selected', [(string) $a->id, (string) $c->id])
        ->call('openTransfer')->assertSet('transferOpen', true)->assertSee('Transfer 2 leads')->assertSee('All or nothing');

    // only the manager and their own people are offered
    expect(array_keys($list->viewData('transferTargets')))->toEqualCanonicalizing([$this->manager->id, $this->bde->id, $this->bde2->id]);

    $list->set('transferTo', (string) $this->bde2->id)->set('reason', 'Territory re-allocated')->call('transfer')
        ->assertHasNoErrors()->assertSet('transferOpen', false)->assertSet('selected', [])
        ->assertSee("2 leads transferred to {$this->bde2->name}.");

    expect($a->fresh()->assigned_to)->toBe($this->bde2->id)
        ->and($c->fresh()->assigned_to)->toBe($this->bde2->id)
        ->and($b->fresh()->assigned_to)->toBe($this->bde->id)                         // not ticked
        ->and(LeadTransfer::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $a->id)->latest('id')->first()->reason)->toBe('Territory re-allocated');
});

it('ticks the whole page on request, and clears it again', function () {
    $leads = collect([1, 2, 3])->map(fn () => makeLead($this->acme, $this->bde, $this->ahd));
    $lost = makeLead($this->acme, $this->bde, $this->ahd);
    ($this->stage)($lost, LeadStage::Lost, null, lostReasonId($this->acme));

    $list = Livewire::actingAs($this->manager)->test(LeadList::class)->call('selectPage');

    expect($list->get('selected'))->toEqualCanonicalizing($leads->map(fn (Lead $l) => (string) $l->id)->all());      // the open ones on the page; not the lost one

    $list->assertSee('3')->call('clearSelection')->assertSet('selected', []);

    // nothing ticked: there is nothing to transfer
    $list->call('openTransfer')->assertHasErrors(['leads'])->assertSet('transferOpen', false);
});

it('explains what stopped a transfer and moves nothing', function () {
    $mine = makeLead($this->acme, $this->bde, $this->ahd);
    $theirs = makeLead($this->acme, $this->mumBde, $this->mum);            // another manager's team

    $list = Livewire::actingAs($this->manager)->test(LeadList::class)->set('selected', [(string) $mine->id])->call('openTransfer');

    $list->call('transfer')->assertHasErrors(['to_user_id']);
    $list->set('transferTo', (string) $this->bde2->id)->call('transfer')->assertHasErrors(['reason']);
    $list->set('reason', 'Rebalancing')->set('transferTo', (string) $this->mumBde->id)->call('transfer')->assertHasErrors(['to_user_id'])->assertSee('teams you manage');

    // ids the browser was never shown: another team's lead, a lead that does not exist
    $list->set('transferTo', (string) $this->bde2->id)->set('selected', [(string) $mine->id, (string) $theirs->id, '999999', 'abc'])->call('transfer')
        ->assertHasErrors(['leads'])->assertSee('Nothing was transferred')->assertSet('transferOpen', true);

    expect($mine->fresh()->assigned_to)->toBe($this->bde->id)->and($theirs->fresh()->assigned_to)->toBe($this->mumBde->id);
});

it('keeps handing leads over away from people who may not', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);

    Livewire::actingAs($this->bde)->test(LeadList::class)->call('selectPage')->assertForbidden();
    Livewire::actingAs($this->bde)->test(LeadList::class)->set('selected', [(string) $lead->id])->call('openTransfer')->assertForbidden();
    Livewire::actingAs($this->bde)->test(LeadList::class)->set('selected', [(string) $lead->id])->set('transferTo', (string) $this->bde2->id)->set('reason', 'I am going on leave')
        ->call('transfer')->assertForbidden();

    expect($lead->fresh()->assigned_to)->toBe($this->bde->id);
});

// ======================================================================================================
// Entering and editing a lead
// ======================================================================================================

it('lets a salesperson enter a lead, which becomes theirs', function () {
    $form = Livewire::actingAs($this->bde)->test(LeadForm::class)
        ->assertSet('lead.location_id', (string) $this->ahd->id)                 // their only branch
        ->assertSet('lead.lead_date', '2026-10-12')
        ->assertSee('A lead you enter is yours.')
        ->set('lead.name', '  Shree   Ganesh Traders ')->set('lead.mobile', '98765 43210')->set('lead.lead_source_id', (string) leadSourceId($this->acme))
        ->set('lead.requirement', "M10 hex bolts\n5,000 a month")->set('lead.expected_value', '2,50,000')
        ->call('save')->assertHasNoErrors();

    $lead = Lead::withoutGlobalScopes(Tenancy::SCOPES)->latest('id')->firstOrFail();
    $form->assertRedirect(route('crm.leads.show', $lead));

    expect($lead->name)->toBe('Shree Ganesh Traders')->and($lead->mobile)->toBe('9876543210')
        ->and($lead->requirement)->toBe("M10 hex bolts\n5,000 a month")                  // free text keeps its lines
        ->and($lead->expected_value)->toBe('250000.00')
        ->and($lead->assigned_to)->toBe($this->bde->id)->and($lead->location_id)->toBe($this->ahd->id);
});

it('shows what the service refuses against the fields', function () {
    Livewire::actingAs($this->bde)->test(LeadForm::class)
        ->set('lead.mobile', '12345')->set('lead.email', 'nope')->set('lead.lead_date', '2026-12-01')
        ->call('save')
        ->assertHasErrors(['lead.name', 'lead.mobile', 'lead.lead_source_id', 'lead.email', 'lead.lead_date'])
        ->assertSee('Some details need attention')->assertSee('The lead date cannot be in the future.')
        ->assertNoRedirect();

    expect(Lead::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('fills the address in from the PIN code while it is typed', function () {
    $form = Livewire::actingAs($this->bde)->test(LeadForm::class);

    $form->set('lead.pin_code', '380001')
        ->assertSet('lead.state_id', (string) $this->geo['gj']->id)->assertSet('lead.district_id', (string) $this->geo['ahmedabad']->id)
        ->assertSet('lead.block_id', (string) $this->geo['daskroi']->id)->assertSet('lead.city', 'Ahmedabad')
        ->assertSee('State, district and block filled in from the PIN code.');

    // a PIN that covers two blocks: the district is certain, the block is not
    $form->set('lead.pin_code', '382110')->assertSet('lead.district_id', (string) $this->geo['ahmedabad']->id)->assertSet('lead.block_id', '')
        ->assertSee('only the parts that are certain');

    // another PIN moves the place but leaves the city the person can see
    $form->set('lead.pin_code', '395003')->assertSet('lead.district_id', (string) $this->geo['surat']->id)->assertSet('lead.block_id', (string) $this->geo['choryasi']->id)
        ->assertSet('lead.city', 'Ahmedabad');

    $form->set('lead.pin_code', '999999')->assertSee('not in the geography master')->assertSet('lead.district_id', (string) $this->geo['surat']->id);

    // a different state cannot keep the old district and block
    $form->set('lead.state_id', (string) $this->geo['mh']->id)->assertSet('lead.district_id', '')->assertSet('lead.block_id', '');
    expect(array_values($form->viewData('districts')))->toBe(['Pune']);
    $form->set('lead.district_id', (string) $this->geo['pune']->id);
    expect(array_values($form->viewData('blocks')))->toBe(['Haveli']);
});

it('points out a mobile number that is already a lead, and asks before adding another', function () {
    $existing = makeLead($this->acme, $this->bde2, $this->ahd, ['mobile' => '9876543210', 'name' => 'Ganesh (first inquiry)']);
    $elsewhere = makeLead($this->acme, $this->mumBde, $this->mum, ['mobile' => '9876543210', 'name' => 'Ganesh (Mumbai)', 'acknowledge_duplicate' => true]);

    $form = Livewire::actingAs($this->bde)->test(LeadForm::class)
        ->set('lead.name', 'Shree Ganesh Traders')->set('lead.lead_source_id', (string) leadSourceId($this->acme))
        ->set('lead.mobile', '+91 98765 43210')
        ->assertSee('already on 2 open leads')->assertSee($existing->lead_number)->assertSee($elsewhere->lead_number)
        ->assertSee($this->bde2->name)->assertSee('MUM Branch')
        ->assertSee('save as a separate lead')
        // somebody else's lead is named so the person can ask about it — but it is not a way in
        ->assertDontSeeHtml('href="'.route('crm.leads.show', $existing->id).'"')
        ->assertDontSeeHtml('href="'.route('crm.leads.show', $elsewhere->id).'"');

    $form->call('save')->assertHasErrors(['lead.mobile'])->assertNoRedirect();
    expect(Lead::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(2);

    $form->set('lead.acknowledge_duplicate', true)->call('save')->assertHasNoErrors();
    expect(Lead::withoutGlobalScopes(Tenancy::SCOPES)->where('mobile', '9876543210')->count())->toBe(3);

    // a different number is a different question: the tick does not carry over
    Livewire::actingAs($this->bde)->test(LeadForm::class)->set('lead.mobile', '9876543210')->set('lead.acknowledge_duplicate', true)
        ->set('lead.mobile', '9876500000')->assertSet('lead.acknowledge_duplicate', false)->assertDontSee('already on');

    // a manager who can open the first lead gets a link to it
    Livewire::actingAs($this->manager)->test(LeadForm::class)->set('lead.mobile', '9876543210')
        ->assertSeeHtml('href="'.route('crm.leads.show', $existing->id).'"');
});

it('says so when the mobile number was a lead before and is closed now', function () {
    $lost = makeLead($this->acme, $this->bde, $this->ahd, ['mobile' => '9876543210']);
    ($this->stage)($lost, LeadStage::Lost, null, lostReasonId($this->acme));

    Livewire::actingAs($this->bde)->test(LeadForm::class)->set('lead.mobile', '9876543210')
        ->assertSee('has been a lead before')->assertSee($lost->lead_number)->assertDontSee('save as a separate lead');
});

it('offers only approved items for the items of interest, with the units each is sold in', function () {
    $purchase = makeUser(['Purchase User'], [$this->acme]);
    $bolt = makeItem($this->acme, $purchase, [], 'approved');
    $draft = makeItem($this->acme, $purchase, ['item' => ['name' => 'MS Hex Nut M10 (draft)']], 'draft');

    $form = Livewire::actingAs($this->bde)->test(LeadForm::class)
        ->set('interests.0.search', 'Hex')->assertSee('MS Hex Bolt M10')->assertDontSee('MS Hex Nut M10 (draft)');

    // an item that is not approved cannot be picked, even by its id
    $form->call('pickItem', 0, $draft->id)->assertSet('interests.0.item_id', '');

    $form->call('pickItem', 0, $bolt->id)
        ->assertSet('interests.0.item_id', (string) $bolt->id)->assertSet('interests.0.unit_id', (string) $bolt->base_unit_id)->assertSet('interests.0.search', '');
    expect(array_keys($form->viewData('itemUnits')[0]))->toEqualCanonicalizing([unitId($this->acme, 'NOS'), unitId($this->acme, 'BOX')]);

    $form->set('interests.0.quantity', '5000')->call('addInterest')->set('interests.1.description', 'Washers to match')
        ->set('lead.name', 'Shree Ganesh Traders')->set('lead.mobile', '9876543210')->set('lead.lead_source_id', (string) leadSourceId($this->acme))
        ->call('save')->assertHasNoErrors();

    $rows = LeadInterest::withoutGlobalScopes(Tenancy::SCOPES)->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->item_id)->toBe($bolt->id)->and($rows[0]->quantity)->toBe('5000.000')
        ->and($rows[1]->description)->toBe('Washers to match');

    // "Change" lets the row be turned into words instead
    Livewire::actingAs($this->bde)->test(LeadForm::class)->call('pickItem', 0, $bolt->id)->call('clearItem', 0)
        ->assertSet('interests.0.item_id', '')->assertSet('interests.0.unit_id', '');
});

it('puts a problem with an interest on the row it belongs to, whatever empty rows came before it', function () {
    $form = Livewire::actingAs($this->bde)->test(LeadForm::class)
        ->set('lead.name', 'Shree Ganesh Traders')->set('lead.mobile', '9876543210')->set('lead.lead_source_id', (string) leadSourceId($this->acme))
        ->call('addInterest')->set('interests.1.quantity', '5')             // row 0 untouched, row 1 has a quantity and nothing else
        ->call('save');

    $form->assertHasErrors(['interests.0.description']);
    expect($form->get('interests'))->toHaveCount(1);                         // the empty row is gone, so the message sits on the right one

    // rows can be added up to the limit and removed again
    $form = Livewire::actingAs($this->bde)->test(LeadForm::class);
    for ($i = 0; $i < LeadService::MAX_INTERESTS + 5; $i++) {
        $form->call('addInterest');
    }
    expect($form->get('interests'))->toHaveCount(LeadService::MAX_INTERESTS);
    $form->call('removeInterest', 0);
    expect($form->get('interests'))->toHaveCount(LeadService::MAX_INTERESTS - 1);
});

it('offers a manager only owners they may choose, who work in the lead’s branch', function () {
    $form = Livewire::actingAs($this->manager)->test(LeadForm::class)->assertSet('lead.assigned_to', (string) $this->manager->id)->assertSee('Unassigned — decide later');

    $form->set('lead.location_id', (string) $this->ahd->id);
    expect(array_keys($form->viewData('owners')))->toEqualCanonicalizing([$this->manager->id, $this->bde->id, $this->bde2->id]);

    $form->set('lead.location_id', (string) $this->mum->id);
    expect(array_keys($form->viewData('owners')))->toBe([$this->manager->id]);          // the team works in Ahmedabad only

    // a tampered owner is refused by the service
    $form->set('lead.name', 'Shree Ganesh Traders')->set('lead.mobile', '9876543210')->set('lead.lead_source_id', (string) leadSourceId($this->acme))
        ->set('lead.assigned_to', (string) $this->loner->id)->call('save')->assertHasErrors(['lead.assigned_to']);

    $form->set('lead.location_id', (string) $this->ahd->id)->set('lead.assigned_to', (string) $this->bde->id)->call('save')->assertHasNoErrors();
    $lead = Lead::withoutGlobalScopes(Tenancy::SCOPES)->latest('id')->firstOrFail();
    $form->assertRedirect(route('crm.leads.show', $lead));
    expect($lead->assigned_to)->toBe($this->bde->id);
});

it('sends a tele-caller who entered a lead for the pool… to their own lead', function () {
    // someone without lead.assign always owns what they enter, so they can always open it afterwards
    $form = Livewire::actingAs($this->telecaller)->test(LeadForm::class)
        ->set('lead.location_id', (string) $this->ahd->id)->set('lead.name', 'Walk-in')->set('lead.mobile', '9876543210')->set('lead.lead_source_id', (string) leadSourceId($this->acme, 'TELECALLER'))
        ->set('lead.assigned_to', '')->call('save')->assertHasNoErrors();

    $lead = Lead::withoutGlobalScopes(Tenancy::SCOPES)->latest('id')->firstOrFail();
    $form->assertRedirect(route('crm.leads.show', $lead));
    expect($lead->assigned_to)->toBe($this->telecaller->id);
});

it('edits a lead without letting its branch, owner or identity be changed', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Old name', 'pin_code' => '380001'], [['id' => '', 'item_id' => '', 'description' => 'Bolts', 'quantity' => '10', 'unit_id' => '']]);

    $form = Livewire::actingAs($this->bde)->test(LeadForm::class, ['editing' => $lead])
        ->assertSet('lead.name', 'Old name')->assertSet('lead.pin_code', '380001')->assertSet('lead.district_id', (string) $this->geo['ahmedabad']->id)
        ->assertSet('interests.0.description', 'Bolts')->assertSet('interests.0.quantity', '10')
        ->assertSee($lead->lead_number)->assertSee('The branch numbers the lead')
        ->set('lead.name', 'New name')->set('lead.location_id', (string) $this->mum->id)->set('lead.assigned_to', (string) $this->bde2->id)
        ->call('save')->assertHasNoErrors()->assertRedirect(route('crm.leads.show', $lead));

    expect($lead->fresh()->name)->toBe('New name')->and($lead->fresh()->location_id)->toBe($this->ahd->id)->and($lead->fresh()->assigned_to)->toBe($this->bde->id);

    // which lead is being edited is the server's to say
    expect(fn () => Livewire::actingAs($this->bde)->test(LeadForm::class, ['editing' => $lead])->set('leadId', 1))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('opens the edit form only for a lead the person may change', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $lost = makeLead($this->acme, $this->bde, $this->ahd);
    ($this->stage)($lost, LeadStage::Lost, null, lostReasonId($this->acme));

    Livewire::actingAs($this->loner)->test(LeadForm::class, ['editing' => $lead])->assertForbidden();
    Livewire::actingAs($this->otherManager)->test(LeadForm::class, ['editing' => $lead])->assertForbidden();
    Livewire::actingAs($this->bde)->test(LeadForm::class, ['editing' => $lost->fresh()])->assertForbidden();   // a lost lead is re-opened first
    Livewire::actingAs($this->accounts)->test(LeadForm::class)->assertForbidden();

    // the lead is lost while the form is open: the save is refused
    $form = Livewire::actingAs($this->bde)->test(LeadForm::class, ['editing' => $lead]);
    ($this->stage)($lead, LeadStage::Lost, null, lostReasonId($this->acme));
    $form->set('lead.name', 'Too late')->call('save')->assertForbidden();

    expect($lead->fresh()->name)->not->toBe('Too late');
});

// ======================================================================================================
// The lead's page
// ======================================================================================================

it('shows a lead with its details, what it is interested in and its history', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd, [
        'name' => 'Shree Ganesh Traders', 'contact_person' => 'Meera Shah', 'email' => 'meera@example.test', 'pin_code' => '380001',
        'requirement' => 'M10 hex bolts', 'expected_value' => '250000', 'remarks' => 'Met at the trade fair',
    ], [['id' => '', 'item_id' => '', 'description' => 'Stainless fasteners', 'quantity' => '12.5', 'unit_id' => '']]);

    $this->actingAs($this->bde)->get(route('crm.leads.show', $lead))->assertOk()
        ->assertSee('Shree Ganesh Traders')->assertSee($lead->lead_number)->assertSee('Meera Shah')->assertSee($lead->mobile)->assertSee('meera@example.test')
        ->assertSee('Daskroi, Ahmedabad, Gujarat')->assertSee('₹2,50,000.00')->assertSee('M10 hex bolts')->assertSee('Met at the trade fair')
        ->assertSee('Stainless fasteners')->assertSee('12.5')->assertSee('Not in the item master')
        ->assertSee('Lead created')->assertSee('With '.$this->bde->name)->assertSee('Nothing is planned for this lead')
        ->assertSee('Plan a follow-up')->assertSee('Move to Contacted')->assertSee('Mark as lost')->assertSee('Edit')
        ->assertDontSee('Transfer…')->assertDontSee('Audit trail');          // not a salesperson's to do or see

    $this->actingAs($this->manager)->get(route('crm.leads.show', $lead))->assertOk()->assertSee('Transfer…')->assertSee('Delete')->assertDontSee('Audit trail');
    $this->actingAs($this->admin)->get(route('crm.leads.show', $lead))->assertOk()->assertSee('Audit trail');
});

it('moves a lead between stages from its page', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);

    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])->call('moveToStage', 'contacted')->assertHasNoErrors()->assertRedirect(route('crm.leads.show', $lead->id));
    expect($lead->fresh()->stage)->toBe(LeadStage::Contacted);

    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])->assertSee('Move to Qualified')->assertSee('Move to New')->call('moveToStage', 'qualified');
    expect($lead->fresh()->stage)->toBe(LeadStage::Qualified);

    // a stage that only a conversion sets, or one that needs a reason, is not reachable this way
    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])->call('moveToStage', 'won')->assertHasErrors(['workflow'])->assertSee('cannot be moved to won');
    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])->call('moveToStage', 'lost')->assertNotFound();
    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])->call('moveToStage', 'banana')->assertNotFound();
    expect($lead->fresh()->stage)->toBe(LeadStage::Qualified);

    // nobody else moves it
    Livewire::actingAs($this->manager)->test(LeadShow::class, ['lead' => $lead])->call('moveToStage', 'contacted')->assertHasNoErrors();
    expect($lead->fresh()->stage)->toBe(LeadStage::Contacted);
});

it('marks a lead lost with a reason and re-opens it with a comment', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    makeFollowup($this->acme, $this->bde, $lead);

    $page = Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])
        ->call('openDialog', 'lost')->assertSet('dialog', 'lost')->assertSee('Why was it lost?')->assertSee('Price too high');

    $page->call('markLost')->assertHasErrors(['lost_reason_id'])->assertSee('Choose why the lead was lost.');
    expect($lead->fresh()->stage)->toBe(LeadStage::New);

    $page->set('lostReasonId', (string) lostReasonId($this->acme, 'PRICE'))->set('comment', 'Quoted 12% above the competitor')->call('markLost')
        ->assertHasNoErrors()->assertRedirect(route('crm.leads.show', $lead->id));

    expect($lead->fresh()->stage)->toBe(LeadStage::Lost)->and($lead->fresh()->lost_remarks)->toBe('Quoted 12% above the competitor');

    $lostPage = Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])
        ->assertSee('Price too high')->assertSee('Quoted 12% above the competitor')->assertSee('Marked as lost')->assertSee('Re-open…')
        ->assertDontSee('Plan a follow-up')->assertDontSee('Move to Contacted')->assertDontSee('Mark as lost…');

    $lostPage->call('openDialog', 'reopen')->assertSee('Why is it being re-opened?')->call('reopen')->assertHasErrors(['comment']);
    $lostPage->set('reopenTo', 'qualified')->set('comment', 'They came back with a bigger order')->call('reopen')->assertHasNoErrors();

    expect($lead->fresh()->stage)->toBe(LeadStage::Qualified)->and($lead->fresh()->lost_reason_id)->toBeNull();
});

it('plans a follow-up, records what came of it with the next step, moves it and calls it off — all from the lead’s page', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);

    // ---- plan ----
    $page = Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])
        ->call('openDialog', 'plan')->assertSet('dialog', 'plan')->assertSet('plan.assigned_to', (string) $this->bde->id)->assertSee('Who will do it');

    $page->call('planFollowup')->assertHasErrors(['plan.scheduled_at']);
    $page->set('plan.scheduled_at', '2026-10-13T11:00')->set('plan.type', 'visit')->set('plan.notes', 'Take the catalogue')->call('planFollowup')
        ->assertHasNoErrors()->assertRedirect(route('crm.leads.show', $lead->id));

    $first = Followup::withoutGlobalScopes(Tenancy::SCOPES)->where('subject_id', $lead->id)->firstOrFail();
    expect($first->scheduled_at->toDateTimeString())->toBe('2026-10-13 11:00:00')->and($first->type->value)->toBe('visit')->and($first->assigned_to)->toBe($this->bde->id);

    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])
        ->assertSee('Visit')->assertSee('Tue, 13 Oct 2026, 11:00')->assertSee('Take the catalogue')->assertSee('Done…')->assertSee('Move…')->assertSee('Call off…')
        ->assertSee('Next follow-up 13 Oct, 11:00');

    // ---- move ----
    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])
        ->call('openDialog', 'move', $first->id)->assertSet('when', '2026-10-13T11:00')
        ->set('when', '2026-10-01T10:00')->call('moveFollowup')->assertHasErrors(['when'])
        ->set('when', '2026-10-14T16:30')->call('moveFollowup')->assertHasNoErrors();
    expect($first->fresh()->scheduled_at->toDateTimeString())->toBe('2026-10-14 16:30:00');

    // ---- done, with an outcome that needs a next date ----
    Carbon::setTestNow('2026-10-14 17:00:00');
    $done = Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])
        ->assertSee('Overdue')
        ->call('openDialog', 'done', $first->id)->assertSee('What came of it?')->assertSee('Call Back Later')->assertSee('It is required for');

    $done->call('completeFollowup')->assertHasErrors(['done.outcome_id']);
    $done->set('done.outcome_id', (string) outcomeId($this->acme, 'CALL_BACK_LATER'))->call('completeFollowup')->assertHasErrors(['done.next_at']);
    $done->set('done.next_at', '2026-10-16T10:00')->set('done.next_type', 'call')->set('done.notes', 'Owner was busy')->call('completeFollowup')->assertHasNoErrors();

    $next = Followup::withoutGlobalScopes(Tenancy::SCOPES)->where('previous_id', $first->id)->firstOrFail();
    expect($first->fresh()->status)->toBe(FollowupStatus::Done)
        ->and($next->scheduled_at->toDateTimeString())->toBe('2026-10-16 10:00:00')
        ->and($lead->fresh()->stage)->toBe(LeadStage::Contacted);            // the first real contact

    // ---- call the next one off ----
    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])
        ->assertSee('Call Back Later')->assertSee('Owner was busy')->assertSee('New → Contacted')
        ->call('openDialog', 'calloff', $next->id)->call('callOffFollowup')->assertHasErrors(['reason'])
        ->set('reason', 'They are travelling')->call('callOffFollowup')->assertHasNoErrors();
    expect($next->fresh()->status)->toBe(FollowupStatus::Cancelled)->and($lead->fresh()->next_followup_at)->toBeNull();

    // ---- log something that was not planned ----
    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])
        ->call('openDialog', 'log')->call('logFollowup')->assertHasErrors(['log.outcome_id'])
        ->set('log.type', 'whatsapp')->set('log.outcome_id', (string) outcomeId($this->acme, 'INTERESTED'))->set('log.notes', 'Sent the price list')->call('logFollowup')->assertHasNoErrors();

    $logged = Followup::withoutGlobalScopes(Tenancy::SCOPES)->where('subject_id', $lead->id)->latest('id')->firstOrFail();
    expect($logged->status)->toBe(FollowupStatus::Done)->and($logged->type->value)->toBe('whatsapp')->and($logged->completed_by)->toBe($this->bde->id);
});

it('acts only on a follow-up that belongs to the lead on the page and to the person', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $other = makeLead($this->acme, $this->bde, $this->ahd);
    $onOther = makeFollowup($this->acme, $this->bde, $other);
    $managers = makeFollowup($this->acme, $this->manager, $lead, ['assigned_to' => (string) $this->manager->id]);

    // another lead's follow-up, passed to this lead's page
    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])->call('openDialog', 'done', $onOther->id)->assertNotFound();
    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])->set('followupId', $onOther->id)->set('done.outcome_id', (string) outcomeId($this->acme))->call('completeFollowup')->assertNotFound();

    // the manager's follow-up on the salesperson's lead: visible there, but not the salesperson's to finish
    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])->assertSeeHtml(($this->fuRow)($managers))->assertDontSee('Done…')
        ->call('openDialog', 'done', $managers->id)->assertForbidden();
    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])->set('followupId', $managers->id)->set('reason', 'Not needed any more')->call('callOffFollowup')->assertForbidden();

    expect($onOther->fresh()->status)->toBe(FollowupStatus::Pending)->and($managers->fresh()->status)->toBe(FollowupStatus::Pending);

    // an unknown dialog is not a dialog
    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])->call('openDialog', 'approve')->assertNotFound();
});

it('lets someone asked to follow up another person’s lead open it, do it, and then lose sight of it', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Shree Ganesh Traders']);
    $this->actingAs($this->telecaller)->get(route('crm.leads.show', $lead))->assertForbidden();

    // the administrator asks the tele-caller to ring this lead
    $call = makeFollowup($this->acme, $this->admin, $lead, ['assigned_to' => (string) $this->telecaller->id]);

    $this->actingAs($this->telecaller)->get(route('crm.leads.show', $lead))->assertOk()
        ->assertSee('Shree Ganesh Traders')->assertSee('Done…')->assertSee('Log what was done')
        ->assertDontSee('Move to Contacted')->assertDontSee('Mark as lost')->assertDontSee('Transfer…');     // seeing is not working

    $this->actingAs($this->telecaller)->get(route('crm.leads.edit', $lead))->assertForbidden();
    Livewire::actingAs($this->telecaller)->test(LeadShow::class, ['lead' => $lead])->call('moveToStage', 'qualified')->assertForbidden();
    Livewire::actingAs($this->telecaller)->test(LeadList::class)->assertSeeHtml(($this->row)($lead));

    // the call is made; with nothing more planned for them, the lead is no longer theirs to open
    Livewire::actingAs($this->telecaller)->test(LeadShow::class, ['lead' => $lead])
        ->call('openDialog', 'done', $call->id)->set('done.outcome_id', (string) outcomeId($this->acme, 'INTERESTED'))->set('done.notes', 'Wants a visit')
        ->call('completeFollowup')->assertHasNoErrors()->assertRedirect(route('crm.followups.index'));

    expect($call->fresh()->status)->toBe(FollowupStatus::Done)->and($lead->fresh()->stage)->toBe(LeadStage::Contacted);
    $this->actingAs($this->telecaller)->get(route('crm.leads.show', $lead))->assertForbidden();
});

it('transfers a lead from its page, to the people the manager may choose', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);

    $page = Livewire::actingAs($this->manager)->test(LeadShow::class, ['lead' => $lead])->call('openDialog', 'transfer')->assertSet('dialog', 'transfer');

    expect(array_keys($page->viewData('transferTargets')))->toEqualCanonicalizing([$this->manager->id, $this->bde2->id]);    // not its present owner

    $page->call('transfer')->assertHasErrors(['to_user_id']);
    $page->set('transferTo', (string) $this->bde2->id)->call('transfer')->assertHasErrors(['reason']);
    $page->set('transferTo', (string) $this->loner->id)->set('reason', 'Rebalancing')->call('transfer')->assertHasErrors(['to_user_id']);
    $page->set('transferTo', (string) $this->bde2->id)->call('transfer')->assertHasNoErrors()->assertRedirect(route('crm.leads.show', $lead->id));

    expect($lead->fresh()->assigned_to)->toBe($this->bde2->id);

    $this->actingAs($this->manager)->get(route('crm.leads.show', $lead))->assertSee("{$this->bde->name} → {$this->bde2->name}")->assertSee('Rebalancing')->assertSee('Ownership history');

    // not for a salesperson, even on their own lead
    Livewire::actingAs($this->bde2)->test(LeadShow::class, ['lead' => $lead->fresh()])->call('openDialog', 'transfer')->assertForbidden();
    Livewire::actingAs($this->bde2)->test(LeadShow::class, ['lead' => $lead->fresh()])->set('transferTo', (string) $this->bde->id)->set('reason', 'Take it back')->call('transfer')->assertForbidden();
    expect($lead->fresh()->assigned_to)->toBe($this->bde2->id);
});

it('deletes a lead only for those who may, and never a converted one', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $converted = makeLead($this->acme, $this->bde, $this->ahd);
    Lead::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($converted->id)->update(['stage' => 'deal']);

    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])->call('delete')->assertForbidden();
    Livewire::actingAs($this->manager)->test(LeadShow::class, ['lead' => $converted])->assertDontSee('Delete')->assertSee('has been converted')->call('delete')->assertForbidden();

    Livewire::actingAs($this->manager)->test(LeadShow::class, ['lead' => $lead])->call('delete')->assertRedirect(route('crm.leads.index'));

    expect(Lead::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($lead->id)->exists())->toBeFalse()
        ->and(Lead::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($converted->id)->exists())->toBeTrue();
    $this->actingAs($this->manager)->get(route('crm.leads.show', $lead))->assertNotFound();
});

it('lets people who work a lead comment on it', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);

    Livewire::actingAs($this->bde)->test('shared.comments', ['type' => 'lead', 'id' => $lead->id])->set('body', 'Prefers WhatsApp to calls')->call('add')->assertHasNoErrors();
    $this->actingAs($this->manager)->get(route('crm.leads.show', $lead))->assertSee('Prefers WhatsApp to calls');

    Livewire::actingAs($this->loner)->test('shared.comments', ['type' => 'lead', 'id' => $lead->id])->assertForbidden();
});

// ======================================================================================================
// Follow-ups: the list
// ======================================================================================================

it('lists follow-ups by reach, with what is overdue first and the numbers on the tabs', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Shree Ganesh Traders', 'mobile' => '9876543210']);
    $colleagueLead = makeLead($this->acme, $this->bde2, $this->ahd, ['name' => 'Patel Polymers']);
    $lonerLead = makeLead($this->acme, $this->loner, $this->ahd, ['name' => 'Lonely Traders']);
    $today = makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-12T15:00', 'notes' => 'Ask about volumes']);
    $friday = makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-16T10:00', 'type' => 'visit']);
    $faraway = makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-11-20T10:00']);
    $colleague = makeFollowup($this->acme, $this->bde2, $colleagueLead, ['scheduled_at' => '2026-10-12T12:00']);
    $loners = makeFollowup($this->acme, $this->loner, $lonerLead, ['scheduled_at' => '2026-10-12T12:00']);
    $done = makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-12T10:00']);
    inCompany($this->acme, fn () => app(FollowupService::class)->complete($done, ['done' => ['outcome_id' => (string) outcomeId($this->acme, 'NOT_AVAILABLE')]], $this->bde));

    Carbon::setTestNow('2026-10-12 16:00:00');     // the three o'clock call has been missed

    $list = Livewire::actingAs($this->bde)->test(FollowupList::class);

    expect(collect($list->viewData('tabs'))->mapWithKeys(fn ($tab) => [$tab[0] === '' ? 'all' : $tab[0] => $tab[2]])->all())
        ->toBe(['open' => 3, 'overdue' => 1, 'today' => 1, 'week' => 2, 'done' => null, 'cancelled' => null, 'all' => null]);

    $list->assertSeeHtml(($this->fuRow)($today))->assertSeeHtml(($this->fuRow)($friday))->assertSeeHtml(($this->fuRow)($faraway))
        ->assertDontSeeHtml(($this->fuRow)($colleague))->assertDontSeeHtml(($this->fuRow)($loners))->assertDontSeeHtml(($this->fuRow)($done))
        ->assertSee('Shree Ganesh Traders')->assertSee('9876543210')->assertSee('Ask about volumes')->assertSee('Overdue')
        ->assertSeeInOrder([($this->fuRow)($today), ($this->fuRow)($friday), ($this->fuRow)($faraway)], false)
        ->assertSeeHtml('href="'.route('crm.leads.show', $lead).'#follow-ups"');

    $list->call('setShow', 'overdue')->assertSeeHtml(($this->fuRow)($today))->assertDontSeeHtml(($this->fuRow)($friday));
    $list->call('setShow', 'done')->assertSeeHtml(($this->fuRow)($done))->assertSee('Not Available')->assertDontSeeHtml(($this->fuRow)($today));
    $list->call('setShow', 'week')->set('type', 'visit')->assertSeeHtml(($this->fuRow)($friday))->assertDontSeeHtml(($this->fuRow)($today));
    $list->call('setShow', 'nonsense')->assertSet('show', 'open');

    // the manager sees the team's, can narrow to one person or team, and never sees a stranger's
    $managers = Livewire::actingAs($this->manager)->test(FollowupList::class)
        ->assertSeeHtml(($this->fuRow)($today))->assertSeeHtml(($this->fuRow)($colleague))->assertDontSeeHtml(($this->fuRow)($loners));
    expect(array_keys($managers->viewData('teams')))->toBe([$this->team->id]);

    $managers->set('person', (string) $this->bde2->id)->assertSeeHtml(($this->fuRow)($colleague))->assertDontSeeHtml(($this->fuRow)($today));
    $managers->set('person', (string) $this->loner->id)->assertDontSeeHtml(($this->fuRow)($loners))->assertSee('No follow-ups match.');
    $managers->set('person', '')->set('team', (string) $this->westTeam->id)->assertDontSeeHtml(($this->fuRow)($today))->assertSee('No follow-ups match.');
    $managers->set('team', '')->set('search', 'patel')->assertSeeHtml(($this->fuRow)($colleague))->assertDontSeeHtml(($this->fuRow)($today));

    Livewire::actingAs($this->accounts)->test(FollowupList::class)->assertForbidden();
});

it('names the lead on a follow-up but links to it only when the person can open it', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Shree Ganesh Traders']);
    $call = makeFollowup($this->acme, $this->admin, $lead, ['assigned_to' => (string) $this->telecaller->id]);
    inCompany($this->acme, fn () => app(FollowupService::class)->complete($call, ['done' => ['outcome_id' => (string) outcomeId($this->acme)]], $this->telecaller));

    // their own finished call is on their record, but the lead it was on is no longer theirs to open
    Livewire::actingAs($this->telecaller)->test(FollowupList::class)->call('setShow', 'done')
        ->assertSeeHtml(($this->fuRow)($call))->assertSee('Shree Ganesh Traders')
        ->assertDontSeeHtml('href="'.route('crm.leads.show', $lead).'#follow-ups"')->assertDontSee('Open lead');
});

// ======================================================================================================
// Follow-ups: the calendar
// ======================================================================================================

it('serves the calendar page with what the browser needs to draw it', function () {
    $this->actingAs($this->bde)->get(route('crm.followups.calendar'))->assertOk()
        ->assertSee('Follow-up calendar')->assertSee('followupCalendar({ offsetMinutes: 330 })', false)     // the application's clock is IST
        ->assertSee('x-ref="calendar"', false)->assertSee('wire:ignore', false)
        ->assertSee('drag a planned one')->assertSee('Overdue')->assertSee('Planned')->assertSee('Done');

    Livewire::actingAs($this->accounts)->test(FollowupCalendar::class)->assertForbidden();
});

it('hands the calendar the follow-ups of the window it shows, in the person’s reach', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Shree Ganesh Traders', 'mobile' => '9876543210']);
    $colleagueLead = makeLead($this->acme, $this->bde2, $this->ahd, ['name' => 'Patel Polymers']);
    $lonerLead = makeLead($this->acme, $this->loner, $this->ahd);
    $missed = makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-12T10:00']);
    $planned = makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-15T14:30', 'type' => 'visit']);
    $done = makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-13T09:45']);
    $nextWeek = makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-21T10:00']);
    $colleague = makeFollowup($this->acme, $this->bde2, $colleagueLead, ['scheduled_at' => '2026-10-14T10:00']);
    makeFollowup($this->acme, $this->loner, $lonerLead, ['scheduled_at' => '2026-10-14T10:00']);
    Carbon::setTestNow('2026-10-13 10:00:00');
    inCompany($this->acme, fn () => app(FollowupService::class)->complete($done, ['done' => ['outcome_id' => (string) outcomeId($this->acme, 'INTERESTED')]], $this->bde));

    $events = function ($user, string $start = '2026-10-12T00:00:00', string $end = '2026-10-19T00:00:00', array $filters = []) {
        $calendar = Livewire::actingAs($user)->test(FollowupCalendar::class);
        foreach ($filters as $property => $value) {
            $calendar->set($property, $value);
        }

        $returned = null;
        $calendar->call('events', $start, $end)->assertReturned(function ($data) use (&$returned) {
            $returned = $data;

            return is_array($data);
        });

        return collect($returned)->keyBy('id');
    };

    $mine = $events($this->bde);

    expect($mine->keys()->all())->toBe([$missed->id, $done->id, $planned->id])            // this week, oldest first; not next week's
        ->and($mine[$missed->id])->toMatchArray(['id' => (string) $missed->id, 'title' => 'Call · Shree Ganesh Traders', 'start' => '2026-10-12T10:00:00', 'end' => '2026-10-12T10:30:00', 'editable' => true])
        ->and($mine[$missed->id]['url'])->toBe('/crm/leads/'.$lead->id.'#follow-ups')
        ->and($mine[$missed->id]['extendedProps']['state'])->toBe('overdue')
        ->and($mine[$planned->id]['extendedProps']['state'])->toBe('planned')
        ->and($mine[$planned->id]['title'])->toBe('Visit · Shree Ganesh Traders')
        ->and($mine[$done->id]['extendedProps'])->toMatchArray(['state' => 'done', 'outcome' => 'Interested', 'person' => $this->bde->name])
        ->and($mine[$done->id]['editable'])->toBeFalse()                                 // what happened does not move
        ->and($mine[$done->id]['extendedProps']['summary'])->toContain('Done')->toContain('Interested')->toContain($lead->lead_number)->toContain('9876543210');

    // the manager sees the team's, can ask for one person, and gets nothing for a stranger
    expect($events($this->manager)->keys()->all())->toEqualCanonicalizing([$missed->id, $done->id, $planned->id, $colleague->id])
        ->and($events($this->manager, filters: ['person' => (string) $this->bde2->id])->keys()->all())->toBe([$colleague->id])
        ->and($events($this->manager, filters: ['person' => (string) $this->loner->id])->keys()->all())->toBe([])
        ->and($events($this->manager, filters: ['team' => (string) $this->westTeam->id])->keys()->all())->toBe([])
        ->and($events($this->bde, filters: ['person' => (string) $this->bde2->id])->keys()->all())->toBe([]);

    // other windows, and windows that make no sense
    expect($events($this->bde, '2026-10-19T00:00:00', '2026-10-26T00:00:00')->keys()->all())->toBe([$nextWeek->id])
        ->and($events($this->bde, 'yesterday', 'tomorrow')->all())->toBe([])
        ->and($events($this->bde, '2026-10-12T00:00:00Z', '2026-10-19T00:00:00+05:30')->keys()->all())->toHaveCount(3);     // an offset is ignored, not trusted

    // changing the person tells the calendar to ask again
    Livewire::actingAs($this->manager)->test(FollowupCalendar::class)->set('person', (string) $this->bde->id)->assertDispatched('followups-filter-changed');
});

it('moves a planned follow-up when it is dragged, and puts it back when the server says no', function () {
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $planned = makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-15T14:30']);
    $finished = makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-13T09:45']);
    inCompany($this->acme, fn () => app(FollowupService::class)->complete($finished, ['done' => ['outcome_id' => (string) outcomeId($this->acme)]], $this->bde));
    $lonerLead = makeLead($this->acme, $this->loner, $this->ahd);
    $strangers = makeFollowup($this->acme, $this->loner, $lonerLead, ['scheduled_at' => '2026-10-15T14:30']);

    $move = function ($user, int $id, string $to) {
        $result = null;
        Livewire::actingAs($user)->test(FollowupCalendar::class)->call('move', $id, $to)->assertReturned(function ($data) use (&$result) {
            $result = $data;

            return true;
        });

        return $result;
    };

    $ok = $move($this->bde, $planned->id, '2026-10-16T11:15:00');
    expect($ok['ok'])->toBeTrue()->and($ok['message'])->toContain('Fri, 16 Oct 2026, 11:15')
        ->and($planned->fresh()->scheduled_at->toDateTimeString())->toBe('2026-10-16 11:15:00');

    // into the past, a finished one, a stranger's, one that does not exist: refused, with a reason, and nothing moves
    expect($move($this->bde, $planned->id, '2026-10-01T10:00:00'))->toMatchArray(['ok' => false, 'message' => 'The new time must be in the future.'])
        ->and($move($this->bde, $planned->id, 'whenever')['ok'])->toBeFalse()
        ->and($move($this->bde, $finished->id, '2026-10-20T10:00:00'))->toMatchArray(['ok' => false, 'message' => 'That follow-up cannot be moved.'])
        ->and($move($this->bde, $strangers->id, '2026-10-20T10:00:00')['ok'])->toBeFalse()
        ->and($move($this->bde, 999999, '2026-10-20T10:00:00')['ok'])->toBeFalse()
        ->and($planned->fresh()->scheduled_at->toDateTimeString())->toBe('2026-10-16 11:15:00')
        ->and($strangers->fresh()->scheduled_at->toDateTimeString())->toBe('2026-10-15 14:30:00');

    // a manager may move their team's
    expect($move($this->manager, $planned->id, '2026-10-17T09:00:00')['ok'])->toBeTrue()
        ->and($move($this->otherManager, $planned->id, '2026-10-18T09:00:00')['ok'])->toBeFalse();
});

// ======================================================================================================
// Lead masters: sources, outcomes, lost reasons
// ======================================================================================================

it('gives every company the sources, outcomes and lost reasons the brief names', function () {
    $this->actingAs($this->admin)->get(route('crm.masters.sources'))->assertOk()
        ->assertSee('Lead sources')->assertSee('Reference')->assertSee('IndiaMART')->assertSee('Just Dial')->assertSee('Excel Import')->assertSee('Tele-caller')
        ->assertSee('Follow-up outcomes')->assertSee('Lost reasons');                 // the tabs

    $this->actingAs($this->admin)->get(route('crm.masters.outcomes'))->assertOk()
        ->assertSee('Call Disconnected')->assertSee('Not Available')->assertSee('Interested')->assertSee('Not Interested')->assertSee('Call Back Later')
        ->assertSee('Meeting Fixed')->assertSee('Store Visited')->assertSee('Person reached')->assertSee('Needs a next date');

    $this->actingAs($this->admin)->get(route('crm.masters.lost-reasons'))->assertOk()->assertSee('Price too high')->assertSee('Went to a competitor');
});

it('lets an administrator add a source of their own, and keeps the standard ones in place', function () {
    $excel = LeadSource::withoutGlobalScopes(Tenancy::SCOPES)->whereKey(leadSourceId($this->acme, 'EXCEL'))->firstOrFail();

    Livewire::actingAs($this->admin)->test(LeadSourceList::class)
        ->call('create')->set('values.code', 'exhibition')->set('values.name', 'Trade exhibition')->call('save')->assertHasNoErrors();

    $own = LeadSource::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('code', 'EXHIBITION')->firstOrFail();
    expect($own->is_system)->toBeFalse()->and($own->name)->toBe('Trade exhibition');

    // a standard source can be renamed and switched off…
    Livewire::actingAs($this->admin)->test(LeadSourceList::class)->call('edit', $excel->id)->set('values.name', 'Spreadsheet')->call('save')->assertHasNoErrors();
    Livewire::actingAs($this->admin)->test(LeadSourceList::class)->call('toggleStatus', $excel->id);
    expect($excel->fresh()->name)->toBe('Spreadsheet')->and($excel->fresh()->status)->toBe('inactive')->and($excel->fresh()->is_system)->toBeTrue();

    // …but its code is what the import and the integrations look for, and it is never deleted
    Livewire::actingAs($this->admin)->test(LeadSourceList::class)->call('edit', $excel->id)->set('values.code', 'XLS')->call('save')->assertHasErrors(['values.code']);
    Livewire::actingAs($this->admin)->test(LeadSourceList::class)->call('delete', $excel->id)->assertForbidden();
    expect($excel->fresh()->code)->toBe('EXCEL')->and($excel->fresh()->trashed())->toBeFalse();

    // one of their own goes, unless leads came from it
    makeLead($this->acme, $this->bde, $this->ahd, ['lead_source_id' => (string) $own->id]);
    Livewire::actingAs($this->admin)->test(LeadSourceList::class)->call('delete', $own->id)->assertHasErrors(['list'])->assertSee('1 lead(s) came from this source');
    expect($own->fresh()->trashed())->toBeFalse();
});

it('keeps an outcome’s meaning with it, and a lost reason that was used', function () {
    Livewire::actingAs($this->admin)->test(FollowupOutcomeList::class)
        ->call('create')->set('values.code', 'QUOTE_REQUESTED')->set('values.name', 'Quote requested')->set('values.counts_as_contact', true)->set('values.needs_next_followup', true)
        ->call('save')->assertHasNoErrors()->assertSee('Quote requested');

    $outcome = FollowupOutcome::withoutGlobalScopes(Tenancy::SCOPES)->where('code', 'QUOTE_REQUESTED')->firstOrFail();
    expect($outcome->counts_as_contact)->toBeTrue()->and($outcome->needs_next_followup)->toBeTrue()->and($outcome->company_id)->toBe($this->acme->id);

    // and it then behaves like the standard ones: no next date, no "done"
    $lead = makeLead($this->acme, $this->bde, $this->ahd);
    $followup = makeFollowup($this->acme, $this->bde, $lead);
    Livewire::actingAs($this->bde)->test(LeadShow::class, ['lead' => $lead])->call('openDialog', 'done', $followup->id)
        ->assertSee('Quote requested')->set('done.outcome_id', (string) $outcome->id)->call('completeFollowup')->assertHasErrors(['done.next_at']);

    // an outcome that follow-ups ended with stays
    inCompany($this->acme, fn () => app(FollowupService::class)->complete($followup, ['done' => ['outcome_id' => (string) $outcome->id, 'next_at' => '2026-10-20T10:00']], $this->bde));
    Livewire::actingAs($this->admin)->test(FollowupOutcomeList::class)->call('delete', $outcome->id)->assertHasErrors(['list']);

    // a lost reason that leads were lost for stays too; an unused one goes
    ($this->stage)($lead, LeadStage::Lost, null, lostReasonId($this->acme, 'PRICE'));
    Livewire::actingAs($this->admin)->test(LostReasonList::class)->call('delete', lostReasonId($this->acme, 'PRICE'))->assertHasErrors(['list']);
    Livewire::actingAs($this->admin)->test(LostReasonList::class)->call('delete', lostReasonId($this->acme, 'DUPLICATE'))->assertHasNoErrors();

    expect(LostReason::withoutGlobalScopes(Tenancy::SCOPES)->whereKey(lostReasonId($this->acme, 'PRICE'))->exists())->toBeTrue()
        ->and(LostReason::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('code', 'DUPLICATE')->exists())->toBeFalse();
});

it('keeps the lead masters to the administrators, and each company’s to itself', function () {
    foreach ([LeadSourceList::class, FollowupOutcomeList::class, LostReasonList::class] as $list) {
        Livewire::actingAs($this->manager)->test($list)->assertForbidden();
        Livewire::actingAs($this->bde)->test($list)->assertForbidden();
    }

    // another company's row cannot be edited or deleted through this company's screen
    $foreign = leadSourceId($this->globex, 'REFERENCE');
    Livewire::actingAs($this->admin)->test(LeadSourceList::class)->call('edit', $foreign)->assertNotFound();
    Livewire::actingAs($this->admin)->test(LeadSourceList::class)->call('toggleStatus', $foreign)->assertNotFound();

    // and the same code can exist in both
    Livewire::actingAs($this->globexAdmin)->test(LeadSourceList::class)->call('create')->set('values.code', 'EXHIBITION')->set('values.name', 'Expo')->call('save')->assertHasNoErrors();
    Livewire::actingAs($this->admin)->test(LeadSourceList::class)->call('create')->set('values.code', 'EXHIBITION')->set('values.name', 'Trade exhibition')->call('save')->assertHasNoErrors();
    Livewire::actingAs($this->admin)->test(LeadSourceList::class)->call('create')->set('values.code', 'REFERENCE')->set('values.name', 'Again')->call('save')->assertHasErrors(['values.code']);
});

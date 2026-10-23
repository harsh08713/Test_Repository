<?php

use App\Modules\CRM\Enums\RoutePlanStatus;
use App\Modules\CRM\Exceptions\AttendanceRefused;
use App\Modules\CRM\Http\Livewire\Attendance\AttendanceList;
use App\Modules\CRM\Http\Livewire\Attendance\ViolationList;
use App\Modules\CRM\Http\Livewire\Field\MyDay;
use App\Modules\CRM\Http\Livewire\RoutePlans\RoutePlanForm;
use App\Modules\CRM\Http\Livewire\RoutePlans\RoutePlanList;
use App\Modules\CRM\Http\Livewire\RoutePlans\RoutePlanShow;
use App\Modules\CRM\Http\Livewire\Targets\TargetForm;
use App\Modules\CRM\Http\Livewire\Targets\TargetList;
use App\Modules\CRM\Http\Livewire\Teams\SalesTeamForm;
use App\Modules\CRM\Http\Livewire\Teams\SalesTeamList;
use App\Modules\CRM\Http\Livewire\Teams\SalesTeamShow;
use App\Modules\CRM\Models\AttendanceRecord;
use App\Modules\CRM\Models\GeofenceViolation;
use App\Modules\CRM\Models\RoutePlan;
use App\Modules\CRM\Models\SalesTarget;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Models\SalesTeamMember;
use App\Modules\CRM\Services\AttendanceService;
use App\Modules\CRM\Services\RoutePlanService;
use App\Modules\CRM\Services\TargetService;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');

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
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);

    $this->setting = fn (string $key, $value) => inCompany($this->acme, fn () => app(SettingsService::class)->set($key, $value));
    $this->target = fn (array $target = [], $actor = null) => inCompany($this->acme, fn () => app(TargetService::class)->save(null, ['target' => [
        'subject' => 'user', 'user_id' => (string) $this->bde->id, 'period_type' => 'monthly', 'period_start' => '2026-10-01', 'target_value' => '500000',
        'target_quantity' => '', 'item_group_id' => '', 'location_id' => '', 'allow_overlap' => false, 'status' => 'active', 'remarks' => '', ...$target,
    ]], $actor ?? $this->manager));

    // an attempt that the server refuses, quietly: the log is what we look at afterwards
    $this->attempt = function ($user, $lat, $lng, $accuracy = 9) {
        inCompany($this->acme, function () use ($user, $lat, $lng, $accuracy) {
            try {
                app(AttendanceService::class)->mark($user, $lat, $lng, $accuracy);
            } catch (AttendanceRefused) {
                // refused: that is what the log records
            }
        });
    };
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// Who can open what
// ======================================================================================================

it('lets each role open only the field-sales screens that are its own', function () {
    $plan = makePlan($this->acme, $this->manager, $this->bde, $this->ahd);
    $target = ($this->target)();

    $status = fn ($user, string $route, $model = null) => $this->actingAs($user)->get($model ? route($route, $model) : route($route))->status();

    // the salesperson: their day, their own plan, attendance and target — and nothing of anybody else's
    expect($status($this->bde, 'field.today'))->toBe(200)->and($status($this->bde, 'field.plans.index'))->toBe(200)->and($status($this->bde, 'field.plans.show', $plan))->toBe(200)
        ->and($status($this->bde, 'field.attendance.index'))->toBe(200)->and($status($this->bde, 'field.violations.index'))->toBe(200)->and($status($this->bde, 'field.targets.index'))->toBe(200)
        ->and($status($this->bde, 'field.plans.create'))->toBe(403)->and($status($this->bde, 'field.plans.edit', $plan))->toBe(403)
        ->and($status($this->bde, 'field.targets.create'))->toBe(403)->and($status($this->bde, 'field.targets.edit', $target))->toBe(403)
        ->and($status($this->bde, 'field.teams.index'))->toBe(403)->and($status($this->bde, 'field.teams.show', $this->team))->toBe(403);

    // a salesperson of nobody's team is a stranger to this plan
    expect($status($this->bde2, 'field.plans.show', $plan))->toBe(403);

    // the manager runs their team
    expect($status($this->manager, 'field.plans.index'))->toBe(200)->and($status($this->manager, 'field.plans.create'))->toBe(200)->and($status($this->manager, 'field.plans.edit', $plan))->toBe(200)
        ->and($status($this->manager, 'field.plans.show', $plan))->toBe(200)->and($status($this->manager, 'field.teams.show', $this->team))->toBe(200)
        ->and($status($this->manager, 'field.teams.edit', $this->team))->toBe(200)->and($status($this->manager, 'field.targets.edit', $target))->toBe(200)
        ->and($status($this->manager, 'field.attendance.index'))->toBe(200)->and($status($this->manager, 'field.today'))->toBe(403);   // a manager marks no attendance

    // another manager has no reach over this team
    expect($status($this->otherManager, 'field.plans.show', $plan))->toBe(403)->and($status($this->otherManager, 'field.plans.edit', $plan))->toBe(403)
        ->and($status($this->otherManager, 'field.teams.show', $this->team))->toBe(403)->and($status($this->otherManager, 'field.teams.edit', $this->team))->toBe(403)
        ->and($status($this->otherManager, 'field.targets.edit', $target))->toBe(403);

    // an administrator sees everyone
    expect($status($this->admin, 'field.plans.show', $plan))->toBe(200)->and($status($this->admin, 'field.teams.show', $this->team))->toBe(200)->and($status($this->admin, 'field.targets.edit', $target))->toBe(200);

    // others have nothing to do here
    foreach (['field.today', 'field.plans.index', 'field.attendance.index', 'field.violations.index', 'field.teams.index', 'field.targets.index'] as $route) {
        expect($status($this->telecaller, $route))->toBe(403)->and($status($this->accounts, $route))->toBe(403, "{$route} for Accounts");
    }
});

it('keeps guests out and other companies’ records unreachable', function () {
    $plan = makePlan($this->acme, $this->manager, $this->bde, $this->ahd);

    foreach (['field.today', 'field.plans.index', 'field.attendance.index', 'field.violations.index', 'field.teams.index', 'field.targets.index'] as $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    foreach ([['field.plans.show', $plan], ['field.plans.edit', $plan], ['field.teams.show', $this->team], ['field.teams.edit', $this->team]] as [$route, $model]) {
        $this->actingAs($this->globexAdmin)->get(route($route, $model))->assertNotFound();
    }
});

it('links the field-sales menu only for those who may use it', function () {
    $href = fn (string $route) => 'href="'.route($route).'"';

    $this->actingAs($this->bde)->get(route('dashboard'))->assertOk()->assertSee($href('field.today'), false)->assertSee($href('field.plans.index'), false)
        ->assertDontSee($href('field.teams.index'), false);
    $this->actingAs($this->manager)->get(route('dashboard'))->assertOk()->assertSee($href('field.teams.index'), false)->assertSee($href('field.targets.index'), false)
        ->assertDontSee($href('field.today'), false);
    $this->actingAs($this->accounts)->get(route('dashboard'))->assertOk()->assertDontSee($href('field.plans.index'), false)->assertDontSee($href('field.attendance.index'), false);
});

// ======================================================================================================
// Teams
// ======================================================================================================

it('lets a manager start a team of their own and put people on it', function () {
    $form = Livewire::actingAs($this->manager)->test(SalesTeamForm::class)
        ->assertSet('team.manager_user_id', (string) $this->manager->id)
        ->set('team.code', 'srt')->set('team.name', 'Surat field team')->call('save');

    $team = SalesTeam::query()->where('code', 'SRT')->firstOrFail();
    $form->assertRedirect(route('field.teams.show', $team));
    expect($team->manager_user_id)->toBe($this->manager->id);

    Livewire::actingAs($this->manager)->test(SalesTeamShow::class, ['team' => $team])
        ->set('newMember', (string) $this->bde2->id)->call('addMember')->assertHasNoErrors()->assertSee($this->bde2->name);

    expect(SalesTeamMember::query()->where('sales_team_id', $team->id)->count())->toBe(1);
});

it('offers a manager only themselves as manager, and an administrator everyone', function () {
    Livewire::actingAs($this->manager)->test(SalesTeamForm::class)->assertSee($this->manager->name)->assertDontSee($this->otherManager->name);
    Livewire::actingAs($this->admin)->test(SalesTeamForm::class)->assertSee($this->manager->name)->assertSee($this->otherManager->name);

    // and a forged manager id is refused all the same
    Livewire::actingAs($this->manager)->test(SalesTeamForm::class)
        ->set('team.code', 'X1')->set('team.name', 'Forged')->set('team.manager_user_id', (string) $this->otherManager->id)->call('save')->assertHasErrors(['team.manager_user_id']);
});

it('says who a person is already on a team with, and does not offer them again', function () {
    $second = makeTeam($this->acme, $this->otherManager, [], 'SRT', 'Surat field team');

    $show = Livewire::actingAs($this->otherManager)->test(SalesTeamShow::class, ['team' => $second])->assertDontSee($this->bde->name);

    // forced anyway
    $show->set('newMember', (string) $this->bde->id)->call('addMember')->assertHasErrors(['newMember']);
    expect(SalesTeamMember::query()->where('user_id', $this->bde->id)->count())->toBe(1);
});

it('takes someone off a team as of today, keeping the row', function () {
    $member = SalesTeamMember::query()->where('user_id', $this->bde->id)->firstOrFail();

    Livewire::actingAs($this->manager)->test(SalesTeamShow::class, ['team' => $this->team])->call('removeMember', $member->id)->assertHasNoErrors();

    expect($member->fresh()->left_on->toDateString())->toBe('2026-10-12')->and(SalesTeamMember::query()->count())->toBe(1);
});

it('lists only the teams a manager runs', function () {
    makeTeam($this->acme, $this->otherManager, [], 'OTHER', 'Another team');

    Livewire::actingAs($this->manager)->test(SalesTeamList::class)->assertSee('Field team')->assertDontSee('Another team');
    Livewire::actingAs($this->admin)->test(SalesTeamList::class)->assertSee('Field team')->assertSee('Another team');
});

it('refuses every team action to someone who does not run the team', function () {
    Livewire::actingAs($this->otherManager)->test(SalesTeamShow::class, ['team' => $this->team])->assertForbidden();
    Livewire::actingAs($this->otherManager)->test(SalesTeamForm::class, ['editing' => $this->team])->assertForbidden();
    Livewire::actingAs($this->bde)->test(SalesTeamList::class)->assertForbidden();

    // a manager's team page cannot be pointed at another team's member
    $other = makeTeam($this->acme, $this->otherManager, [$this->bde2], 'OTHER', 'Another team');
    $foreign = SalesTeamMember::query()->where('sales_team_id', $other->id)->firstOrFail();
    $page = Livewire::actingAs($this->manager)->test(SalesTeamShow::class, ['team' => $this->team]);

    $page->call('removeMember', $foreign->id)->assertNotFound();
    expect($foreign->fresh()->left_on)->toBeNull();
});

it('deletes a team that never had members, and keeps one that did', function () {
    $empty = makeTeam($this->acme, $this->manager, [], 'EMPTY', 'Empty team');

    // a manager may not delete (Sales Manager holds no team-delete right); an administrator may
    Livewire::actingAs($this->manager)->test(SalesTeamShow::class, ['team' => $empty])->assertDontSeeHtml('wire:click="delete"')->call('delete')->assertForbidden();
    Livewire::actingAs($this->admin)->test(SalesTeamShow::class, ['team' => $empty])->assertSeeHtml('wire:click="delete"')->call('delete')->assertRedirect(route('field.teams.index'));
    expect(SalesTeam::query()->whereKey($empty->id)->exists())->toBeFalse();

    Livewire::actingAs($this->admin)->test(SalesTeamShow::class, ['team' => $this->team])->assertDontSeeHtml('wire:click="delete"')->call('delete')->assertHasErrors(['delete']);
    expect(SalesTeam::query()->whereKey($this->team->id)->exists())->toBeTrue();
});

// ======================================================================================================
// Targets
// ======================================================================================================

it('sets a monthly target from a year and a month, and a quarterly one from a financial year and a quarter', function () {
    Livewire::actingAs($this->manager)->test(TargetForm::class)
        ->set('target.user_id', (string) $this->bde->id)->set('year', '2026')->set('slot', '11')->set('target.target_value', '750000')
        ->call('save')->assertHasNoErrors()->assertRedirect(route('field.targets.index'));

    $monthly = SalesTarget::query()->firstOrFail();
    expect($monthly->period_start->toDateString())->toBe('2026-11-01')->and($monthly->period_end->toDateString())->toBe('2026-11-30');

    // Q4 of the financial year that starts in April 2026 is January–March 2027
    Livewire::actingAs($this->manager)->test(TargetForm::class)
        ->set('target.user_id', (string) $this->bde->id)->set('target.period_type', 'quarterly')->set('year', '2026')->set('slot', '4')->set('target.target_quantity', '1200')
        ->assertSee('Q4 FY26-27 (Jan–Mar 2027)')->call('save')->assertHasNoErrors();

    $quarterly = SalesTarget::query()->where('period_type', 'quarterly')->firstOrFail();
    expect($quarterly->period_start->toDateString())->toBe('2027-01-01')->and($quarterly->period_end->toDateString())->toBe('2027-03-31');
});

it('starts a quarterly target from the quarter that is running now', function () {
    Livewire::actingAs($this->manager)->test(TargetForm::class)->set('target.period_type', 'quarterly')->assertSet('year', '2026')->assertSet('slot', '3');
});

it('shows what is wrong with a target, including an overlap, and saves nothing', function () {
    ($this->target)();

    Livewire::actingAs($this->manager)->test(TargetForm::class)->call('save')->assertHasErrors(['target.user_id']);
    Livewire::actingAs($this->manager)->test(TargetForm::class)
        ->set('target.user_id', (string) $this->bde->id)->set('year', '2026')->set('slot', '10')->set('target.target_value', '1000')->call('save')
        ->assertHasErrors(['target.period_start'])->assertSee('overlaps an existing target');

    expect(SalesTarget::query()->count())->toBe(1);
});

it('offers a manager only their own people and teams for a target', function () {
    Livewire::actingAs($this->manager)->test(TargetForm::class)->assertSee($this->bde->name)->assertDontSee($this->bde2->name)->assertDontSee($this->manager->name);
    Livewire::actingAs($this->admin)->test(TargetForm::class)->assertSee($this->bde2->name);
});

it('shows each person their own targets and their team’s, and a manager those of their people', function () {
    ($this->target)(['target_value' => '111111']);
    ($this->target)(['subject' => 'team', 'user_id' => '', 'sales_team_id' => (string) $this->team->id, 'target_value' => '222222']);
    ($this->target)(['user_id' => (string) $this->bde2->id, 'target_value' => '333333'], $this->admin);

    Livewire::actingAs($this->bde)->test(TargetList::class)->assertSee('1,11,111.00')->assertSee('2,22,222.00')->assertDontSee('3,33,333.00');
    Livewire::actingAs($this->manager)->test(TargetList::class)->assertSee('1,11,111.00')->assertSee('2,22,222.00')->assertDontSee('3,33,333.00');
    Livewire::actingAs($this->otherManager)->test(TargetList::class)->assertDontSee('1,11,111.00')->assertDontSee('2,22,222.00')->assertDontSee('3,33,333.00');
    Livewire::actingAs($this->admin)->test(TargetList::class)->assertSee('1,11,111.00')->assertSee('3,33,333.00');
});

it('shows zero per cent while no sales order has been approved for the target', function () {
    ($this->target)();

    Livewire::actingAs($this->manager)->test(TargetList::class)->assertDontSee('No sales orders yet')->assertSee('0.0%');
});

it('filters targets by when they run', function () {
    ($this->target)(['period_start' => '2026-10-01', 'target_value' => '111111']);
    ($this->target)(['period_start' => '2026-12-01', 'target_value' => '222222']);
    ($this->target)(['period_start' => '2026-08-01', 'target_value' => '333333']);

    Livewire::actingAs($this->manager)->test(TargetList::class)->assertSee('1,11,111.00')->assertDontSee('2,22,222.00')->assertDontSee('3,33,333.00')
        ->set('when', 'upcoming')->assertSee('2,22,222.00')->assertDontSee('1,11,111.00')
        ->set('when', 'past')->assertSee('3,33,333.00')->assertDontSee('1,11,111.00')
        ->set('when', '')->assertSee('1,11,111.00')->assertSee('2,22,222.00')->assertSee('3,33,333.00');
});

it('removes a running target, and refuses to touch a finished one', function () {
    $running = ($this->target)(['period_start' => '2026-10-01']);
    $past = ($this->target)(['period_start' => '2026-08-01']);

    // removing needs the target-delete right, which a Sales Manager does not hold (they can edit or switch it off); an administrator holds it
    Livewire::actingAs($this->manager)->test(TargetList::class)->call('delete', $running->id)->assertForbidden();
    Livewire::actingAs($this->admin)->test(TargetList::class)->set('when', '')->call('delete', $running->id)->assertHasNoErrors();
    expect(SalesTarget::query()->whereKey($running->id)->exists())->toBeFalse();

    Livewire::actingAs($this->manager)->test(TargetForm::class, ['editing' => $past])->assertForbidden();
    Livewire::actingAs($this->admin)->test(TargetList::class)->call('delete', $past->id)->assertForbidden();
});

it('refuses a salesperson any change to their own targets', function () {
    $target = ($this->target)(['period_start' => '2027-01-01']);

    Livewire::actingAs($this->bde)->test(TargetList::class)->call('delete', $target->id)->assertForbidden();
    Livewire::actingAs($this->bde)->test(TargetForm::class, ['editing' => $target])->assertForbidden();

    expect(SalesTarget::query()->whereKey($target->id)->exists())->toBeTrue();
});

// ======================================================================================================
// Route plans
// ======================================================================================================

it('plans a day through the form, then publishes it from the plan’s page', function () {
    $form = Livewire::actingAs($this->manager)->test(RoutePlanForm::class)
        ->set('plan.user_id', (string) $this->bde->id)->set('plan.location_id', (string) $this->ahd->id)->set('plan.plan_date', '2026-10-13')
        ->set('stops.0.label', 'Naroda GIDC')->set('stops.0.latitude', '23.0225')->set('stops.0.longitude', '72.5714')
        ->call('addStop')->set('stops.1.label', 'Vatva')->set('stops.1.latitude', '22.9700')->set('stops.1.longitude', '72.6300')->set('stops.1.visit_from', '14:00')->set('stops.1.visit_to', '15:00')
        ->call('save')->assertHasNoErrors();

    $plan = RoutePlan::query()->firstOrFail();
    $form->assertRedirect(route('field.plans.show', $plan));
    expect($plan->status)->toBe(RoutePlanStatus::Draft)->and($plan->stops()->count())->toBe(2);

    Livewire::actingAs($this->manager)->test(RoutePlanShow::class, ['plan' => $plan])->assertSee('Naroda GIDC')->assertSee('Vatva')->assertSee('cannot be marked until the plan is published')
        ->call('publish');

    expect($plan->fresh()->status)->toBe(RoutePlanStatus::Published);
});

it('shows what is wrong with a plan, field by field', function () {
    Livewire::actingAs($this->manager)->test(RoutePlanForm::class)->call('save')->assertHasErrors(['plan.user_id', 'plan.location_id'])->assertSee('Some details need attention');

    Livewire::actingAs($this->manager)->test(RoutePlanForm::class)
        ->set('plan.user_id', (string) $this->bde->id)->set('plan.location_id', (string) $this->ahd->id)
        ->set('stops.0.label', 'Somewhere')->set('stops.0.latitude', '95')->set('stops.0.longitude', '72')->call('save')->assertHasErrors(['stops.0.latitude']);

    expect(RoutePlan::query()->count())->toBe(0);
});

it('offers a manager only the people they manage when planning a day', function () {
    Livewire::actingAs($this->manager)->test(RoutePlanForm::class)->assertSee($this->bde->name)->assertDontSee($this->bde2->name)->assertDontSee($this->otherManager->name);

    Livewire::actingAs($this->manager)->test(RoutePlanForm::class)
        ->set('plan.user_id', (string) $this->bde2->id)->set('plan.location_id', (string) $this->ahd->id)->set('stops.0.label', 'X')->call('save')->assertHasErrors(['plan.user_id']);
});

it('finds a block or a customer to refer to, names the stop after it, and clears it when the kind changes', function () {
    $customer = makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Zenith Alloys']], true);
    makeLedger($this->acme, $this->accounts, 'customer', ['ledger' => ['name' => 'Zebra Draft Customer']]);

    $form = Livewire::actingAs($this->manager)->test(RoutePlanForm::class)
        ->set('stops.0.stop_type', 'block')->set('stops.0.search', 'Dask')->assertSee('Daskroi — Ahmedabad')
        ->call('pick', 0, $this->geo['daskroi']->id)->assertSet('stops.0.label', 'Daskroi, Ahmedabad')->assertSet('stops.0.block_id', (string) $this->geo['daskroi']->id)
        ->set('stops.0.stop_type', 'customer')->assertSet('stops.0.block_id', '')->assertSet('stops.0.label', 'Daskroi, Ahmedabad')
        ->set('stops.0.search', 'Zenith')->assertSee('Zenith Alloys')->call('pick', 0, $customer->id)->assertSet('stops.0.ledger_id', (string) $customer->id)->assertSet('stops.0.label', 'Zenith Alloys');

    // an unapproved customer is never offered
    $form->set('stops.0.search', 'Zebra')->assertDontSee('Zebra Draft Customer');
});

it('names a PIN code stop as soon as a known PIN is typed', function () {
    Livewire::actingAs($this->manager)->test(RoutePlanForm::class)->set('stops.0.stop_type', 'pin_code')->set('stops.0.pin_code', '380001')->assertSet('stops.0.label', 'PIN 380001');
});

it('moves and removes stops in the form', function () {
    Livewire::actingAs($this->manager)->test(RoutePlanForm::class)
        ->set('stops.0.label', 'One')->call('addStop')->set('stops.1.label', 'Two')->call('addStop')->set('stops.2.label', 'Three')
        ->call('moveStop', 2, -1)->assertSet('stops.1.label', 'Three')->assertSet('stops.2.label', 'Two')
        ->call('moveStop', 0, -1)->assertSet('stops.0.label', 'One')
        ->call('removeStop', 0)->assertSet('stops.0.label', 'Three')->assertCount('stops', 2);
});

it('keeps the id of the plan being edited out of the browser’s hands', function () {
    $mine = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, ['plan_date' => '2026-10-14']);
    $other = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, ['plan_date' => '2026-10-15']);

    expect(fn () => Livewire::actingAs($this->manager)->test(RoutePlanForm::class, ['editing' => $mine])->set('planId', $other->id))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => Livewire::actingAs($this->manager)->test(RoutePlanShow::class, ['plan' => $mine])->set('planId', $other->id))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('cancels a plan from its page with a reason, and reinstates it', function () {
    $plan = makePlan($this->acme, $this->manager, $this->bde, $this->ahd);

    $page = Livewire::actingAs($this->manager)->test(RoutePlanShow::class, ['plan' => $plan])->call('openCancel')->assertSee('cannot mark attendance');
    $page->call('cancel')->assertHasErrors(['reason']);
    $page->set('reason', 'The customer postponed')->call('cancel')->assertRedirect(route('field.plans.show', $plan));

    expect($plan->fresh()->status)->toBe(RoutePlanStatus::Cancelled);

    Livewire::actingAs($this->manager)->test(RoutePlanShow::class, ['plan' => $plan->fresh()])->assertSee('Cancelled')->call('reinstate');
    expect($plan->fresh()->status)->toBe(RoutePlanStatus::Draft);
});

it('lets a salesperson look at their own plan but not act on it', function () {
    $plan = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, [], null, 'draft');

    Livewire::actingAs($this->bde)->test(RoutePlanShow::class, ['plan' => $plan])->assertOk()->assertDontSeeHtml('wire:click="publish"')->assertDontSee('Edit');

    foreach (['publish', 'delete', 'openCancel', 'reinstate'] as $action) {
        Livewire::actingAs($this->bde)->test(RoutePlanShow::class, ['plan' => $plan])->call($action)->assertForbidden();
    }

    expect($plan->fresh()->status)->toBe(RoutePlanStatus::Draft);
});

it('refuses another team’s manager the plan altogether', function () {
    $plan = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, [], null, 'draft');

    Livewire::actingAs($this->otherManager)->test(RoutePlanShow::class, ['plan' => $plan])->assertForbidden();
    Livewire::actingAs($this->otherManager)->test(RoutePlanForm::class, ['editing' => $plan])->assertForbidden();
});

it('lists plans only for the people a manager manages, from today on', function () {
    makePlan($this->acme, $this->manager, $this->bde, $this->ahd, ['title' => 'Mine to see']);
    makeTeam($this->acme, $this->otherManager, [$this->bde2], 'OTHER', 'Another team');
    makePlan($this->acme, $this->otherManager, $this->bde2, $this->ahd, ['title' => 'Somebody else']);
    Carbon::setTestNow('2026-09-30 09:00:00');
    makePlan($this->acme, $this->manager, $this->bde, $this->ahd, ['plan_date' => '2026-10-01', 'title' => 'Long past'], null, 'draft');
    Carbon::setTestNow('2026-10-12 09:30:00');

    Livewire::actingAs($this->manager)->test(RoutePlanList::class)->assertSee('Mine to see')->assertDontSee('Somebody else')->assertDontSee('Long past')
        ->set('from', '2026-09-01')->assertSee('Long past');
    Livewire::actingAs($this->bde)->test(RoutePlanList::class)->assertSee('Mine to see')->assertDontSee('Somebody else');
    Livewire::actingAs($this->admin)->test(RoutePlanList::class)->assertSee('Mine to see')->assertSee('Somebody else');
});

// ======================================================================================================
// My day
// ======================================================================================================

it('tells a salesperson with no plan that attendance cannot be marked, and offers no button', function () {
    Livewire::actingAs($this->bde)->test(MyDay::class)->assertSee('no route plan for today')->assertSee('published route plan')->assertDontSee('Mark my attendance');

    // …and the rule does not depend on the button: asking the server directly is refused and logged
    Livewire::actingAs($this->bde)->test(MyDay::class)->call('markAttendance', HQ_LAT, HQ_LNG, 10)->assertSet('outcomeKind', 'error')->assertSee('no route plan for today');

    expect(AttendanceRecord::query()->count())->toBe(0)->and(GeofenceViolation::query()->where('reason', 'no_route_plan')->count())->toBe(1);
});

it('offers the button only for a published plan, and says why not for a draft or a cancelled one', function () {
    $plan = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, [], null, 'draft');

    Livewire::actingAs($this->bde)->test(MyDay::class)->assertSee('not been published yet')->assertDontSee('Mark my attendance');

    inCompany($this->acme, fn () => app(RoutePlanService::class)->publish($plan, $this->manager));
    Livewire::actingAs($this->bde)->test(MyDay::class)->assertSee('Mark my attendance')->assertSee('Naroda GIDC');

    inCompany($this->acme, fn () => app(RoutePlanService::class)->cancel($plan->fresh(), 'Called off', $this->manager));
    Livewire::actingAs($this->bde)->test(MyDay::class)->assertSee('has been cancelled')->assertDontSee('Mark my attendance');
});

it('marks attendance from inside the geofence and shows the result', function () {
    makePlan($this->acme, $this->manager, $this->bde, $this->ahd);

    Livewire::actingAs($this->bde)->test(MyDay::class)
        ->call('markAttendance', 23.0240, HQ_LNG, 14.0)->assertSet('outcomeKind', 'success')->assertSee('Attendance marked at 09:30')->assertSee('Naroda GIDC')->assertDontSee('Mark my attendance');

    $record = AttendanceRecord::query()->sole();
    expect($record->user_id)->toBe($this->bde->id)->and($record->source)->toBe('web');

    // a second tap is told, not logged as a violation
    Livewire::actingAs($this->bde)->test(MyDay::class)->call('markAttendance', 23.0240, HQ_LNG, 14.0)->assertSet('outcomeKind', 'info')->assertSee('already marked');
    expect(GeofenceViolation::query()->count())->toBe(0);
});

it('refuses a position outside the geofence on the screen, says how far, and lists the refused attempt', function () {
    makePlan($this->acme, $this->manager, $this->bde, $this->ahd);

    Livewire::actingAs($this->bde)->test(MyDay::class)
        ->call('markAttendance', 23.0300, HQ_LNG, 9.0)->assertSet('outcomeKind', 'error')->assertSee('from the nearest stop')->assertSee('Your attempts today that were refused');

    expect(AttendanceRecord::query()->count())->toBe(0)->and(GeofenceViolation::query()->where('reason', 'outside_geofence')->count())->toBe(1);
});

it('answers a browser that could not read its position without calling the server’s rules', function () {
    makePlan($this->acme, $this->manager, $this->bde, $this->ahd);

    Livewire::actingAs($this->bde)->test(MyDay::class)->call('locationUnavailable', 'User denied Geolocation')->assertSet('outcomeKind', 'error')->assertSee('could not be read')->assertSee('Allow location access');

    expect(GeofenceViolation::query()->count())->toBe(0);
});

it('lets only someone with the attendance right mark it, and only for themselves', function () {
    makePlan($this->acme, $this->manager, $this->bde, $this->ahd);

    Livewire::actingAs($this->manager)->test(MyDay::class)->assertForbidden();
    Livewire::actingAs($this->accounts)->test(MyDay::class)->assertForbidden();

    // an administrator may open the screen, but it is always their own day: with no plan of their own there is nothing to mark
    Livewire::actingAs($this->admin)->test(MyDay::class)->call('markAttendance', HQ_LAT, HQ_LNG, 10)->assertSet('outcomeKind', 'error');
    expect(AttendanceRecord::query()->count())->toBe(0);
});

// ======================================================================================================
// Attendance and the violation log
// ======================================================================================================

it('shows each person their own attendance, a manager their team’s, and no one anyone else’s', function () {
    makeTeam($this->acme, $this->otherManager, [$this->bde2], 'OTHER', 'Another team');
    makePlan($this->acme, $this->manager, $this->bde, $this->ahd);
    makePlan($this->acme, $this->otherManager, $this->bde2, $this->ahd);
    inCompany($this->acme, fn () => app(AttendanceService::class)->mark($this->bde, HQ_LAT, HQ_LNG, 10));
    inCompany($this->acme, fn () => app(AttendanceService::class)->mark($this->bde2, HQ_LAT, HQ_LNG, 10));

    // (the filter lists people by name, so rows are told apart by their keys)
    $mine = 'wire:key="rec-'.AttendanceRecord::query()->where('user_id', $this->bde->id)->value('id').'"';
    $theirs = 'wire:key="rec-'.AttendanceRecord::query()->where('user_id', $this->bde2->id)->value('id').'"';

    Livewire::actingAs($this->bde)->test(AttendanceList::class)->assertSeeHtml($mine)->assertDontSeeHtml($theirs);
    Livewire::actingAs($this->manager)->test(AttendanceList::class)->assertSeeHtml($mine)->assertDontSeeHtml($theirs);
    Livewire::actingAs($this->otherManager)->test(AttendanceList::class)->assertSeeHtml($theirs)->assertDontSeeHtml($mine);
    Livewire::actingAs($this->admin)->test(AttendanceList::class)->assertSeeHtml($mine)->assertSeeHtml($theirs)
        ->set('person', (string) $this->bde2->id)->assertSeeHtml($theirs)->assertDontSeeHtml($mine)
        ->set('person', '')->set('decision', 'accepted_override')->assertDontSeeHtml($mine)->assertDontSeeHtml($theirs);
});

it('shows the violation log to the person it is about and their managers only', function () {
    makePlan($this->acme, $this->manager, $this->bde, $this->ahd);
    ($this->attempt)($this->bde, 23.0300, HQ_LNG);

    $row = 'wire:key="viol-'.GeofenceViolation::query()->value('id').'"';

    foreach ([$this->bde, $this->manager, $this->admin] as $user) {
        Livewire::actingAs($user)->test(ViolationList::class)->assertSeeHtml($row);
    }

    Livewire::actingAs($this->otherManager)->test(ViolationList::class)->assertDontSeeHtml($row);
    Livewire::actingAs($this->manager)->test(ViolationList::class)->set('reason', 'no_route_plan')->assertDontSeeHtml($row)->set('reason', 'outside_geofence')->assertSeeHtml($row);
});

it('lets a manager accept a refused attempt from the log, once the company allows it', function () {
    makePlan($this->acme, $this->manager, $this->bde, $this->ahd);
    ($this->attempt)($this->bde, 23.0300, HQ_LNG);
    $violation = GeofenceViolation::query()->firstOrFail();

    // off by default: no button, and the action is refused
    Livewire::actingAs($this->manager)->test(ViolationList::class)->assertDontSee('Accept attendance…')->call('openAccept', $violation->id)->assertForbidden();

    ($this->setting)('geofence.manager_override', true);

    $page = Livewire::actingAs($this->manager)->test(ViolationList::class)->assertSee('Accept attendance…')->call('openAccept', $violation->id)->assertSee('accepted by you');
    $page->call('accept')->assertHasErrors(['why']);
    $page->set('why', 'GPS drifts at the customer’s yard')->call('accept')->assertHasNoErrors()->assertSee('Accepted by '.$this->manager->name);

    $record = AttendanceRecord::query()->sole();
    expect($record->decision->value)->toBe('accepted_override')->and($record->violation_id)->toBe($violation->id);

    // the button is gone, and the attendance list says who accepted it
    Livewire::actingAs($this->manager)->test(ViolationList::class)->assertDontSee('Accept attendance…');
    Livewire::actingAs($this->manager)->test(AttendanceList::class)->assertSee('Accepted by a manager');
});

it('never offers the override to the person themselves, to another team’s manager, or for a refusal that cannot be overridden', function () {
    ($this->setting)('geofence.manager_override', true);
    makePlan($this->acme, $this->manager, $this->bde, $this->ahd);
    ($this->attempt)($this->bde, 23.0300, HQ_LNG);
    ($this->attempt)($this->bde, 0, 0);
    $outside = GeofenceViolation::query()->where('reason', 'outside_geofence')->firstOrFail();
    $unreadable = GeofenceViolation::query()->where('reason', 'invalid_gps')->firstOrFail();

    Livewire::actingAs($this->bde)->test(ViolationList::class)->assertDontSee('Accept attendance…')->call('openAccept', $outside->id)->assertForbidden();
    Livewire::actingAs($this->otherManager)->test(ViolationList::class)->assertDontSee('Accept attendance…');
    Livewire::actingAs($this->otherManager)->test(ViolationList::class)->call('openAccept', $outside->id)->assertForbidden();
    Livewire::actingAs($this->manager)->test(ViolationList::class)->call('openAccept', $unreadable->id)->assertForbidden();

    expect(AttendanceRecord::query()->count())->toBe(0);
});

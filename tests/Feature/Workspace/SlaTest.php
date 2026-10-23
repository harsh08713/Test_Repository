<?php

use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\FollowupService;
use App\Modules\CRM\Services\LeadTransferService;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Workspace\Enums\SlaKind;
use App\Modules\Workspace\Models\Holiday;
use App\Modules\Workspace\Models\SlaBreach;
use App\Modules\Workspace\Models\SlaClock;
use App\Modules\Workspace\Models\SlaPolicy;
use App\Modules\Workspace\Notifications\SlaBreachedNotification;
use App\Modules\Workspace\Services\BusinessClock;
use App\Modules\Workspace\Services\SlaPolicies;
use App\Modules\Workspace\Services\SlaSweep;
use App\Modules\Workspace\Services\TaskService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
 * Service levels: working-hours arithmetic, which policy applies, and the sweep that starts, settles and notices clocks — answered in time, answered
 * late, never answered, closed, handed over, moved — with every breach logged once and told once.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');   // a Monday
    Notification::fake();

    $this->acme = makeCompany('ACME', ['state_code' => '24']);
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->bde2]);

    $this->policy = function (array $over = []): SlaPolicy {
        return inCompany($this->acme, function () use ($over) {
            $p = new SlaPolicy;
            $p->fill(['name' => 'First response', 'kind' => 'first_response', 'target_minutes' => 60, 'business_hours' => false, 'escalate_to_manager' => true, ...array_diff_key($over, ['lead_source_id' => 1, 'sales_team_id' => 1, 'created_at' => 1])]);
            $p->forceFill(array_intersect_key($over, ['lead_source_id' => 1, 'sales_team_id' => 1]))->save();
            $p->forceFill(['created_at' => $over['created_at'] ?? Carbon::now()->subDay()])->saveQuietly();

            return $p;
        });
    };
    $this->sweep = fn () => inCompany($this->acme, fn () => app(SlaSweep::class)->run());
    $this->clocks = fn () => SlaClock::query()->orderBy('id')->get();
    $this->newLead = fn ($by = null, array $lead = []) => makeLead($this->acme, $by ?? $this->bde, $this->ahd, $lead);
});

it('adds working minutes, skipping nights, days off and holidays', function () {
    $clock = app(BusinessClock::class);
    $settings = app(SettingsService::class);
    $settings->set('sla.business_days', 'mon_fri', $this->acme->id);
    $settings->set('sla.business_start', '09:30', $this->acme->id);
    $settings->set('sla.business_end', '18:30', $this->acme->id);

    $at = fn (string $from, int $minutes, bool $bh = true) => $clock->after(Carbon::parse($from), $minutes, $bh, $this->acme->id)->format('D Y-m-d H:i');

    expect($at('2026-10-12 10:00', 120))->toBe('Mon 2026-10-12 12:00')                       // inside the day
        ->and($at('2026-10-12 17:30', 120))->toBe('Tue 2026-10-13 10:30')                   // carries over the night
        ->and($at('2026-10-12 07:00', 60))->toBe('Mon 2026-10-12 10:30')                    // before opening: starts at 9:30
        ->and($at('2026-10-16 17:30', 240))->toBe('Mon 2026-10-19 12:30')                   // Friday evening → Monday (Saturday is off)
        ->and($at('2026-10-17 11:00', 30))->toBe('Mon 2026-10-19 10:00')                    // made on a Saturday
        ->and($at('2026-10-12 18:00', 1))->toBe('Mon 2026-10-12 18:01')
        ->and($at('2026-10-12 18:29', 5))->toBe('Tue 2026-10-13 09:34')
        ->and($at('2026-10-17 11:00', 90, false))->toBe('Sat 2026-10-17 12:30');           // round the clock: plain minutes

    // a holiday on Tuesday is skipped as well
    inCompany($this->acme, fn () => (new Holiday)->fill(['holiday_date' => '2026-10-13', 'name' => 'Festival'])->save());
    expect($at('2026-10-12 17:30', 120))->toBe('Wed 2026-10-14 10:30');

    // Saturday counts when the company works it
    $settings->set('sla.business_days', 'mon_sat', $this->acme->id);
    expect($at('2026-10-17 11:00', 30))->toBe('Sat 2026-10-17 11:30');
});

it('chooses the most specific policy and applies a policy only to things made after it', function () {
    $any = ($this->policy)(['name' => 'Any', 'target_minutes' => 240]);
    $source = leadSourceId($this->acme, 'REFERENCE');
    $bySource = ($this->policy)(['name' => 'Reference', 'target_minutes' => 120, 'lead_source_id' => $source]);
    $byBoth = ($this->policy)(['name' => 'Reference, field team', 'target_minutes' => 30, 'lead_source_id' => $source, 'sales_team_id' => $this->team->id]);
    $resolver = app(SlaPolicies::class);
    $find = fn (?int $s, ?int $t, DateTimeInterface $made) => inCompany($this->acme, fn () => $resolver->find($this->acme->id, SlaKind::FirstResponse, $s, $t, null, $made)?->name);
    $resolver->forget();

    expect($find($source, $this->team->id, now()))->toBe('Reference, field team')
        ->and($find($source, null, now()))->toBe('Reference')
        ->and($find(leadSourceId($this->acme, 'WEBSITE'), $this->team->id, now()))->toBe('Any')
        ->and($find(null, null, now()->subDays(5)))->toBeNull();   // made before any policy existed

    inCompany($this->acme, fn () => $bySource->forceFill(['status' => 'inactive'])->save());
    $resolver->forget();
    expect($find($source, null, now()))->toBe('Any');   // a switched-off policy is not used
});

it('puts a clock on a new lead only when a policy covers it, and only once', function () {
    Carbon::withTestNow('2026-10-12 07:00:00', fn () => ($this->newLead)(null, ['name' => 'Before the policy']));
    expect(($this->sweep)()['started'])->toBe(0)->and(($this->clocks)())->toHaveCount(0);

    ($this->policy)(['target_minutes' => 90, 'created_at' => now()->subHour()]);
    $lead2 = ($this->newLead)(null, ['name' => 'After the policy']);
    Carbon::setTestNow('2026-10-12 09:40:00');

    expect(($this->sweep)()['started'])->toBe(1)->and(($this->sweep)()['started'])->toBe(0);

    $clock = ($this->clocks)()->sole();
    expect($clock->subject_id)->toBe($lead2->id)->and($clock->kind)->toBe(SlaKind::FirstResponse)->and($clock->owner_id)->toBe($this->bde->id)->and($clock->state)->toBe('running')
        ->and($clock->started_at->toDateTimeString())->toBe('2026-10-12 09:30:00')->and($clock->due_at->toDateTimeString())->toBe('2026-10-12 11:00:00');
});

it('meets a clock when the lead is contacted in time, and records a breach when it was contacted late', function () {
    ($this->policy)(['target_minutes' => 60]);
    $inTime = ($this->newLead)(null, ['name' => 'In time']);
    $tooLate = ($this->newLead)(null, ['name' => 'Too late']);
    ($this->sweep)();

    Carbon::setTestNow('2026-10-12 10:00:00');
    Lead::query()->whereKey($inTime->id)->update(['last_contacted_at' => now()]);

    Carbon::setTestNow('2026-10-12 11:15:00');   // the other one was never answered within the hour
    Lead::query()->whereKey($tooLate->id)->update(['last_contacted_at' => now()]);
    $result = ($this->sweep)();

    $met = ($this->clocks)()->firstWhere('subject_id', $inTime->id);
    $late = ($this->clocks)()->firstWhere('subject_id', $tooLate->id);

    expect($result['settled'])->toBe(2)->and($result['breached'])->toBe(0)
        ->and($met->state)->toBe('met')->and($met->breached_at)->toBeNull()->and($met->stopped_at->toDateTimeString())->toBe('2026-10-12 10:00:00')
        ->and($late->state)->toBe('met')->and($late->breached_at->toDateTimeString())->toBe('2026-10-12 10:30:00');

    $breach = SlaBreach::query()->sole();
    expect($breach->sla_clock_id)->toBe($late->id)->and($breach->answered_late)->toBeTrue()->and($breach->escalated_to)->toBeNull();
    Notification::assertNothingSent();   // answered already: nobody needs telling
});

it('records a breach once when a lead is never answered, and tells the person and their manager once', function () {
    ($this->policy)(['target_minutes' => 60]);
    $lead = ($this->newLead)();
    ($this->sweep)();

    Carbon::setTestNow('2026-10-12 10:29:00');
    expect(($this->sweep)()['breached'])->toBe(0);

    Carbon::setTestNow('2026-10-12 10:31:00');
    expect(($this->sweep)()['breached'])->toBe(1)->and(($this->sweep)()['breached'])->toBe(0);

    $clock = ($this->clocks)()->sole();
    expect($clock->state)->toBe('running')->and($clock->breached_at->toDateTimeString())->toBe('2026-10-12 10:31:00')->and($clock->escalated_at)->not->toBeNull();

    $breach = SlaBreach::query()->sole();
    expect($breach->owner_id)->toBe($this->bde->id)->and($breach->escalated_to)->toBe($this->manager->id)->and($breach->answered_late)->toBeFalse()
        ->and($breach->due_at->toDateTimeString())->toBe('2026-10-12 10:30:00');

    Notification::assertSentToTimes($this->bde, SlaBreachedNotification::class, 1);
    Notification::assertSentToTimes($this->manager, SlaBreachedNotification::class, 1);
    Notification::assertSentTo($this->manager, SlaBreachedNotification::class, fn ($n) => $n->person === $this->bde->name && str_contains($n->what, $lead->lead_number));
    Notification::assertSentTo($this->bde, SlaBreachedNotification::class, fn ($n) => $n->person === null);

    expect(ActivityLog::query()->where('log_name', 'sla')->where('event', 'breached')->count())->toBe(1);   // auditable

    // answered afterwards: the clock is met (late) and no second breach is logged
    Lead::query()->whereKey($lead->id)->update(['last_contacted_at' => now()]);
    ($this->sweep)();
    expect($clock->fresh()->state)->toBe('met')->and(SlaBreach::query()->count())->toBe(1);
});

it('does not escalate to a manager when the policy says the person only', function () {
    ($this->policy)(['target_minutes' => 10, 'escalate_to_manager' => false]);
    ($this->newLead)();
    ($this->sweep)();
    Carbon::setTestNow('2026-10-12 10:00:00');
    ($this->sweep)();

    Notification::assertSentToTimes($this->bde, SlaBreachedNotification::class, 1);
    Notification::assertNothingSentTo($this->manager);
    expect(SlaBreach::query()->sole()->escalated_to)->toBeNull();
});

it('stops a closed lead’s clock and restarts a handed-over lead’s clock for the new owner', function () {
    ($this->policy)(['target_minutes' => 60]);
    $closing = ($this->newLead)(null, ['name' => 'Closing']);
    $moving = ($this->newLead)(null, ['name' => 'Moving']);
    ($this->sweep)();

    Carbon::setTestNow('2026-10-12 09:45:00');
    // closed: the lead is lost (the stage is data; the sweep reads it)
    Lead::query()->whereKey($closing->id)->update(['stage' => 'lost']);
    // handed over through the real service
    inCompany($this->acme, fn () => app(LeadTransferService::class)->transfer([$moving->id], $this->bde2->id, 'Better placed', $this->manager));

    $result = ($this->sweep)();
    $byLead = fn (int $id) => ($this->clocks)()->where('subject_id', $id)->values();

    expect($byLead($closing->id)->sole()->state)->toBe('stopped')->and($byLead($closing->id)->sole()->stop_reason)->toBe('closed');

    [$old, $new] = [$byLead($moving->id)[0], $byLead($moving->id)[1]];
    expect($old->state)->toBe('stopped')->and($old->stop_reason)->toBe('transferred')
        ->and($new->state)->toBe('running')->and($new->owner_id)->toBe($this->bde2->id)->and($new->started_at->toDateTimeString())->toBe('2026-10-12 09:45:00')
        ->and($new->due_at->toDateTimeString())->toBe('2026-10-12 10:45:00')
        ->and($result['breached'])->toBe(0);

    // the new owner’s clock runs out on its own time; the old one never does
    Carbon::setTestNow('2026-10-12 11:00:00');
    ($this->sweep)();
    expect(SlaBreach::query()->count())->toBe(1)->and(SlaBreach::query()->sole()->owner_id)->toBe($this->bde2->id);
});

it('times a planned follow-up from the moment it was planned for, and settles it when it is done, called off or moved', function () {
    ($this->policy)(['name' => 'Follow-ups', 'kind' => 'followup', 'target_minutes' => 30]);
    $lead = ($this->newLead)();
    $done = makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-12T11:00']);
    $called = makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-12T12:00']);
    $moved = makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-12T13:00']);
    $missed = makeFollowup($this->acme, $this->bde, $lead, ['scheduled_at' => '2026-10-12T14:00']);

    expect(($this->sweep)()['started'])->toBe(4);
    $followClocks = fn () => SlaClock::query()->where('kind', 'followup')->orderBy('id')->get();
    expect($followClocks()[0]->due_at->toDateTimeString())->toBe('2026-10-12 11:30:00');

    Carbon::setTestNow('2026-10-12 11:10:00');
    inCompany($this->acme, fn () => app(FollowupService::class)->complete($done->fresh(), ['done' => ['outcome_id' => (string) outcomeId($this->acme, 'INTERESTED'), 'notes' => 'ok']], $this->bde));
    inCompany($this->acme, fn () => app(FollowupService::class)->cancel($called->fresh(), 'Customer away', $this->bde));
    inCompany($this->acme, fn () => app(FollowupService::class)->reschedule($moved->fresh(), '2026-10-13T10:00', $this->bde));
    ($this->sweep)();

    $states = $followClocks()->mapWithKeys(fn ($c) => [$c->subject_id.'/'.$c->state => $c->stop_reason])->all();
    expect($states)->toMatchArray([$done->id.'/met' => 'responded', $called->id.'/stopped' => 'cancelled', $moved->id.'/stopped' => 'rescheduled', $missed->id.'/running' => null])
        ->and($followClocks()->where('subject_id', $moved->id)->where('state', 'running'))->toHaveCount(1);   // the moved one has a new clock for its new time

    Carbon::setTestNow('2026-10-12 14:31:00');
    ($this->sweep)();
    expect(SlaBreach::query()->where('kind', 'followup')->sole()->subject_id)->toBe($missed->id);
});

it('times a task from its deadline and settles it when it is done, called off or moved', function () {
    ($this->policy)(['name' => 'Tasks', 'kind' => 'task', 'target_minutes' => 120, 'priority' => 'urgent']);
    $make = fn (string $title, string $priority, string $due) => inCompany($this->acme, fn () => app(TaskService::class)->create(['title' => $title, 'priority' => $priority, 'due_at' => $due], $this->bde));

    $urgentDone = $make('Urgent done', 'urgent', '2026-10-12T12:00');
    $urgentMissed = $make('Urgent missed', 'urgent', '2026-10-12T13:00');
    $urgentMoved = $make('Urgent moved', 'urgent', '2026-10-12T14:00');
    $normal = $make('Normal', 'normal', '2026-10-12T10:00');

    expect(($this->sweep)()['started'])->toBe(3);   // the policy covers urgent tasks only
    $clock = SlaClock::query()->where('subject_id', $urgentDone->id)->sole();
    expect($clock->due_at->toDateTimeString())->toBe('2026-10-12 14:00:00')->and($clock->started_at->toDateTimeString())->toBe('2026-10-12 12:00:00');

    inCompany($this->acme, fn () => app(TaskService::class)->complete($urgentDone, 'Done', $this->bde));
    inCompany($this->acme, fn () => app(TaskService::class)->update($urgentMoved->fresh(), ['title' => 'Urgent moved', 'priority' => 'urgent', 'due_at' => '2026-10-14T14:00'], $this->bde));
    ($this->sweep)();

    expect(SlaClock::query()->where('subject_id', $urgentDone->id)->sole()->state)->toBe('met')
        ->and(SlaClock::query()->where('subject_id', $urgentMoved->id)->orderBy('id')->first()->stop_reason)->toBe('rescheduled')
        ->and(SlaClock::query()->where('subject_id', $urgentMoved->id)->where('state', 'running')->count())->toBe(1);

    Carbon::setTestNow('2026-10-12 15:30:00');
    ($this->sweep)();
    $breach = SlaBreach::query()->sole();
    expect($breach->subject_id)->toBe($urgentMissed->id)->and($breach->kind)->toBe(SlaKind::Task);
    Notification::assertSentTo($this->bde, SlaBreachedNotification::class, fn ($n) => $n->what === 'Urgent missed');
    expect($normal->fresh())->not->toBeNull();
});

it('keeps the breach log and the clocks as records', function () {
    ($this->policy)(['target_minutes' => 5]);
    ($this->newLead)();
    ($this->sweep)();
    Carbon::setTestNow('2026-10-12 10:00:00');
    ($this->sweep)();

    $breach = SlaBreach::query()->sole();
    $clock = SlaClock::query()->sole();

    expect(fn () => $breach->forceFill(['answered_late' => true])->save())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $breach->delete())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $clock->delete())->toThrow(LogicException::class, 'cannot be deleted');
});

it('keeps each company’s clocks to itself', function () {
    $globex = makeCompany('GLOBEX');
    seedDefaults($globex);
    $gx = makeLocation($globex, 'GX', ['state_code' => '24']);
    $gAdmin = makeUser(['Company Admin'], [$globex]);

    ($this->policy)(['target_minutes' => 30]);                                 // ACME’s policy only
    makeLead($globex, $gAdmin, $gx, ['name' => 'Foreign Works']);
    ($this->newLead)(null, ['name' => 'Ours']);
    ($this->sweep)();

    expect(SlaClock::withoutGlobalScopes()->count())->toBe(1)->and(SlaClock::withoutGlobalScopes()->sole()->company_id)->toBe($this->acme->id);
});

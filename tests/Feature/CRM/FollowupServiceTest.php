<?php

use App\Modules\CRM\Enums\FollowupStatus;
use App\Modules\CRM\Enums\FollowupType;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\FollowupOutcome;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadStageEntry;
use App\Modules\CRM\Notifications\FollowupDueNotification;
use App\Modules\CRM\Notifications\FollowupOverdueNotification;
use App\Modules\CRM\Services\FollowupAgenda;
use App\Modules\CRM\Services\FollowupService;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');   // a Monday
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->otherManager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->mumBde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->mum]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->bde2]);

    $this->lead = makeLead($this->acme, $this->bde, $this->ahd);

    $this->service = fn () => app(FollowupService::class);
    $this->setting = fn (string $key, $value) => inCompany($this->acme, fn () => app(SettingsService::class)->set($key, $value));

    // run a service call and hand back what it refused, by field (or the rule that was broken, under "rule")
    $this->refusal = function (callable $call): array {
        try {
            inCompany($this->acme, $call);
        } catch (ValidationException $e) {
            return $e->errors();
        } catch (FieldRuleException $e) {
            return ['rule' => [$e->getMessage()]];
        }

        return [];
    };

    $this->complete = fn (Followup $followup, array $done = [], $actor = null) => inCompany($this->acme, fn () => app(FollowupService::class)
        ->complete($followup, ['done' => ['outcome_id' => (string) outcomeId($this->acme, 'INTERESTED'), 'notes' => '', 'next_at' => '', 'next_type' => '', ...$done]], $actor ?? $this->bde));
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// Planning
// ======================================================================================================

it('plans a follow-up for the lead’s owner and shows it as the lead’s next step', function () {
    $followup = makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-13T11:00', 'type' => 'visit', 'notes' => 'Take the catalogue']);

    expect($followup->status)->toBe(FollowupStatus::Pending)
        ->and($followup->type)->toBe(FollowupType::Visit)
        ->and($followup->scheduled_at->toDateTimeString())->toBe('2026-10-13 11:00:00')
        ->and($followup->assigned_to)->toBe($this->bde->id)
        ->and($followup->notes)->toBe('Take the catalogue')
        ->and($followup->company_id)->toBe($this->acme->id)
        ->and($followup->location_id)->toBe($this->ahd->id)             // the lead's branch
        ->and($followup->subject_type)->toBe('lead')
        ->and($followup->subject_id)->toBe($this->lead->id)
        ->and($followup->created_by)->toBe($this->bde->id)
        ->and($this->lead->fresh()->next_followup_at->toDateTimeString())->toBe('2026-10-13 11:00:00');

    // an earlier one becomes the next step; the lead always shows the soonest
    makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-12T16:00']);
    expect($this->lead->fresh()->next_followup_at->toDateTimeString())->toBe('2026-10-12 16:00:00');
});

it('refuses a follow-up in the past, too far ahead, of no known type, or on a lead that is closed', function () {
    $plan = fn (array $over, $lead = null) => fn () => app(FollowupService::class)->schedule($lead ?? $this->lead, ['plan' => ['scheduled_at' => '2026-10-13T11:00', 'type' => 'call', 'assigned_to' => '', 'notes' => '', ...$over]], $this->bde);

    expect(($this->refusal)($plan(['scheduled_at' => '2026-10-12T09:00'])))->toHaveKey('plan.scheduled_at')
        ->and(($this->refusal)($plan(['scheduled_at' => ''])))->toHaveKey('plan.scheduled_at')
        ->and(($this->refusal)($plan(['scheduled_at' => 'tomorrow'])))->toHaveKey('plan.scheduled_at')
        ->and(($this->refusal)($plan(['scheduled_at' => '2026-02-31T10:00'])))->toHaveKey('plan.scheduled_at')        // no such day
        ->and(($this->refusal)($plan(['scheduled_at' => '2029-01-01T10:00'])))->toHaveKey('plan.scheduled_at')
        ->and(($this->refusal)($plan(['type' => 'telepathy'])))->toHaveKey('plan.type')
        ->and(($this->refusal)($plan(['notes' => str_repeat('x', 2001)])))->toHaveKey('plan.notes');

    inCompany($this->acme, fn () => app(LeadService::class)->changeStage($this->lead, LeadStage::Lost, $this->bde, null, lostReasonId($this->acme)));

    $refused = ($this->refusal)($plan([], $this->lead->fresh()));
    expect($refused)->toHaveKey('rule')->and($refused['rule'][0])->toContain('This lead is lost');

    expect(Followup::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

it('gives a follow-up only to the planner, the lead’s owner or someone the planner manages — who works in the branch', function () {
    $assignable = fn ($actor) => inCompany($this->acme, fn () => array_keys(app(FollowupService::class)->assignable($this->lead, $actor)));

    expect($assignable($this->bde))->toBe([$this->bde->id])
        ->and($assignable($this->manager))->toEqualCanonicalizing([$this->manager->id, $this->bde->id, $this->bde2->id])
        ->and($assignable($this->admin))->toContain($this->telecaller->id, $this->bde2->id)->not->toContain($this->mumBde->id);   // Mumbai-only: not in the lead's branch

    $plan = fn ($to, $actor) => fn () => app(FollowupService::class)->schedule($this->lead, ['plan' => ['scheduled_at' => '2026-10-13T11:00', 'type' => 'call', 'assigned_to' => (string) $to->id, 'notes' => '']], $actor);

    expect(($this->refusal)($plan($this->bde2, $this->bde)))->toHaveKey('plan.assigned_to')                 // a colleague, by a salesperson
        ->and(($this->refusal)($plan($this->telecaller, $this->manager)))->toHaveKey('plan.assigned_to')     // not on the manager's team
        ->and(($this->refusal)($plan($this->mumBde, $this->admin)))->toHaveKey('plan.assigned_to')           // does not work in the branch
        ->and(($this->refusal)($plan($this->bde2, $this->manager)))->toBe([])
        ->and(($this->refusal)($plan($this->telecaller, $this->admin)))->toBe([]);
});

// ======================================================================================================
// Done, with an outcome
// ======================================================================================================

it('records what came of a follow-up, and the first real contact makes a new lead “contacted”', function () {
    $followup = makeFollowup($this->acme, $this->bde, $this->lead, ['notes' => 'Ask about volumes']);
    Carbon::setTestNow('2026-10-13 11:20:00');

    $done = ($this->complete)($followup, ['notes' => 'Wants a quote for 5,000 a month']);

    expect($done->status)->toBe(FollowupStatus::Done)
        ->and($done->outcome_id)->toBe(outcomeId($this->acme, 'INTERESTED'))
        ->and($done->completed_at->toDateTimeString())->toBe('2026-10-13 11:20:00')
        ->and($done->completed_by)->toBe($this->bde->id)
        ->and($done->notes)->toBe("Ask about volumes\nWants a quote for 5,000 a month");   // the plan's note is kept

    $lead = $this->lead->fresh();
    expect($lead->stage)->toBe(LeadStage::Contacted)
        ->and($lead->last_contacted_at->toDateTimeString())->toBe('2026-10-13 11:20:00')
        ->and($lead->next_followup_at)->toBeNull();

    $entry = LeadStageEntry::withoutGlobalScopes(Tenancy::SCOPES)->where('lead_id', $lead->id)->latest('id')->first();
    expect($entry->label())->toBe('New → Contacted')->and($entry->comment)->toBe('After a follow-up: Interested');
});

it('leaves the lead new when nobody was reached', function () {
    $followup = makeFollowup($this->acme, $this->bde, $this->lead);

    ($this->complete)($followup, ['outcome_id' => (string) outcomeId($this->acme, 'NOT_AVAILABLE')]);

    expect($this->lead->fresh()->stage)->toBe(LeadStage::New)->and($this->lead->fresh()->last_contacted_at)->toBeNull();
});

it('does not move a lead back when a later follow-up reaches a qualified lead', function () {
    inCompany($this->acme, fn () => app(LeadService::class)->changeStage($this->lead, LeadStage::Qualified, $this->bde));
    $followup = makeFollowup($this->acme, $this->bde, $this->lead);

    ($this->complete)($followup);

    expect($this->lead->fresh()->stage)->toBe(LeadStage::Qualified)->and($this->lead->fresh()->last_contacted_at)->not->toBeNull();
});

it('will not record “call back later” without a date, and plans the next follow-up in the same step', function () {
    $followup = makeFollowup($this->acme, $this->bde, $this->lead, ['type' => 'call']);
    $callBack = (string) outcomeId($this->acme, 'CALL_BACK_LATER');

    $refused = ($this->refusal)(fn () => app(FollowupService::class)->complete($followup, ['done' => ['outcome_id' => $callBack, 'notes' => '', 'next_at' => '', 'next_type' => '']], $this->bde));
    expect($refused)->toHaveKey('done.next_at')->and($refused['done.next_at'][0])->toContain('Call Back Later')
        ->and($followup->fresh()->status)->toBe(FollowupStatus::Pending);                    // nothing half-done

    $done = ($this->complete)($followup, ['outcome_id' => $callBack, 'next_at' => '2026-10-15T16:30', 'next_type' => 'whatsapp']);

    $next = Followup::withoutGlobalScopes(Tenancy::SCOPES)->where('previous_id', $done->id)->firstOrFail();

    expect($next->status)->toBe(FollowupStatus::Pending)
        ->and($next->type)->toBe(FollowupType::WhatsApp)
        ->and($next->scheduled_at->toDateTimeString())->toBe('2026-10-15 16:30:00')
        ->and($next->assigned_to)->toBe($this->bde->id)                 // the same person carries on
        ->and($this->lead->fresh()->next_followup_at->toDateTimeString())->toBe('2026-10-15 16:30:00');
});

it('refuses an outcome that is unknown, switched off or another company’s, and a next date that is wrong', function () {
    $followup = makeFollowup($this->acme, $this->bde, $this->lead);
    $done = fn (array $over) => fn () => app(FollowupService::class)->complete($followup, ['done' => ['outcome_id' => (string) outcomeId($this->acme), 'notes' => '', 'next_at' => '', 'next_type' => '', ...$over]], $this->bde);

    FollowupOutcome::withoutGlobalScopes(Tenancy::SCOPES)->whereKey(outcomeId($this->acme, 'STORE_VISITED'))->update(['status' => 'inactive']);

    expect(($this->refusal)($done(['outcome_id' => ''])))->toHaveKey('done.outcome_id')
        ->and(($this->refusal)($done(['outcome_id' => 'abc'])))->toHaveKey('done.outcome_id')
        ->and(($this->refusal)($done(['outcome_id' => (string) outcomeId($this->globex)])))->toHaveKey('done.outcome_id')
        ->and(($this->refusal)($done(['outcome_id' => (string) outcomeId($this->acme, 'STORE_VISITED')])))->toHaveKey('done.outcome_id')
        ->and(($this->refusal)($done(['next_at' => 'soon'])))->toHaveKey('done.next_at')
        ->and(($this->refusal)($done(['next_at' => '2026-10-11T10:00'])))->toHaveKey('done.next_at')
        ->and(($this->refusal)($done(['notes' => str_repeat('x', 2001)])))->toHaveKey('done.notes')
        ->and($followup->fresh()->status)->toBe(FollowupStatus::Pending);
});

it('records a follow-up only once', function () {
    $followup = makeFollowup($this->acme, $this->bde, $this->lead);
    ($this->complete)($followup);

    $again = ($this->refusal)(fn () => app(FollowupService::class)->complete($followup, ['done' => ['outcome_id' => (string) outcomeId($this->acme)]], $this->bde));
    $moved = ($this->refusal)(fn () => app(FollowupService::class)->reschedule($followup, '2026-10-20T10:00', $this->bde));
    $calledOff = ($this->refusal)(fn () => app(FollowupService::class)->cancel($followup, 'Changed my mind', $this->bde));

    expect($again['rule'][0])->toBe('This follow-up is already done.')
        ->and($moved['rule'][0])->toBe('Only a planned follow-up can be moved.')
        ->and($calledOff['rule'][0])->toBe('Only a planned follow-up can be called off.');
});

// ======================================================================================================
// Logging something that was not planned
// ======================================================================================================

it('logs something that was just done, as done now by the person who did it', function () {
    $logged = inCompany($this->acme, fn () => app(FollowupService::class)->log($this->lead, ['log' => [
        'type' => 'visit', 'outcome_id' => (string) outcomeId($this->acme, 'MEETING_FIXED'), 'notes' => 'Met the owner at the shop', 'next_at' => '2026-10-16T12:00', 'next_type' => 'meeting',
    ]], $this->bde));

    expect($logged->status)->toBe(FollowupStatus::Done)
        ->and($logged->type)->toBe(FollowupType::Visit)
        ->and($logged->assigned_to)->toBe($this->bde->id)
        ->and($logged->completed_by)->toBe($this->bde->id)
        ->and($logged->scheduled_at->toDateTimeString())->toBe('2026-10-12 09:30:00')
        ->and($this->lead->fresh()->stage)->toBe(LeadStage::Contacted)
        ->and($this->lead->fresh()->next_followup_at->toDateTimeString())->toBe('2026-10-16 12:00:00');

    $next = Followup::withoutGlobalScopes(Tenancy::SCOPES)->where('previous_id', $logged->id)->firstOrFail();
    expect($next->type)->toBe(FollowupType::Meeting)->and($next->status)->toBe(FollowupStatus::Pending);
});

it('logs nothing without a type and an outcome', function () {
    $log = fn (array $over) => fn () => app(FollowupService::class)->log($this->lead, ['log' => ['type' => 'call', 'outcome_id' => (string) outcomeId($this->acme), 'notes' => '', 'next_at' => '', 'next_type' => '', ...$over]], $this->bde);

    expect(($this->refusal)($log(['type' => ''])))->toHaveKey('log.type')
        ->and(($this->refusal)($log(['outcome_id' => ''])))->toHaveKey('log.outcome_id')
        ->and(($this->refusal)($log(['outcome_id' => (string) outcomeId($this->acme, 'CALL_BACK_LATER')])))->toHaveKey('log.next_at')
        ->and(Followup::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

// ======================================================================================================
// Moving and calling off
// ======================================================================================================

it('moves a planned follow-up, re-arming its reminder and its escalation', function () {
    $followup = makeFollowup($this->acme, $this->bde, $this->lead);
    $followup->forceFill(['reminder_sent_at' => now(), 'escalated_at' => now()])->save();

    $moved = inCompany($this->acme, fn () => app(FollowupService::class)->reschedule($followup, '2026-10-20T14:15', $this->bde));

    expect($moved->scheduled_at->toDateTimeString())->toBe('2026-10-20 14:15:00')
        ->and($moved->reminder_sent_at)->toBeNull()
        ->and($moved->escalated_at)->toBeNull()
        ->and($moved->updated_by)->toBe($this->bde->id)
        ->and($this->lead->fresh()->next_followup_at->toDateTimeString())->toBe('2026-10-20 14:15:00');

    expect(($this->refusal)(fn () => app(FollowupService::class)->reschedule($followup, '2026-10-01T10:00', $this->bde)))->toHaveKey('when')
        ->and(($this->refusal)(fn () => app(FollowupService::class)->reschedule($followup, '', $this->bde)))->toHaveKey('when');
});

it('calls a follow-up off with a reason, keeps it on record and clears the lead’s next step', function () {
    $followup = makeFollowup($this->acme, $this->bde, $this->lead, ['notes' => 'Ring after lunch']);

    expect(($this->refusal)(fn () => app(FollowupService::class)->cancel($followup, 'no', $this->bde)))->toHaveKey('reason');

    $cancelled = inCompany($this->acme, fn () => app(FollowupService::class)->cancel($followup, 'They are travelling this week', $this->bde));

    expect($cancelled->status)->toBe(FollowupStatus::Cancelled)
        ->and($cancelled->notes)->toBe("Ring after lunch\nCalled off: They are travelling this week")
        ->and($this->lead->fresh()->next_followup_at)->toBeNull()
        ->and(Followup::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($followup->id)->exists())->toBeTrue();
});

// ======================================================================================================
// Who may see and work a follow-up
// ======================================================================================================

it('lets a person work their own follow-ups, a manager their team’s, and nobody a finished one', function () {
    $followup = makeFollowup($this->acme, $this->bde, $this->lead);
    $can = fn ($user, string $ability, $model) => Gate::forUser($user)->allows($ability, $model);

    expect($can($this->bde, 'view', $followup))->toBeTrue()
        ->and($can($this->bde, 'update', $followup))->toBeTrue()
        ->and($can($this->manager, 'update', $followup))->toBeTrue()
        ->and($can($this->admin, 'update', $followup))->toBeTrue()
        ->and($can($this->bde2, 'view', $followup))->toBeFalse()
        ->and($can($this->otherManager, 'view', $followup))->toBeFalse()
        ->and($can($this->mumBde, 'view', $followup))->toBeFalse()
        ->and($can($this->manager, 'delete', $followup))->toBeFalse();     // never deleted, only called off

    ($this->complete)($followup);

    expect($can($this->bde, 'update', $followup->fresh()))->toBeFalse()->and($can($this->bde, 'view', $followup->fresh()))->toBeTrue();
});

it('lists follow-ups by reach, and narrows by person and team without ever widening', function () {
    $mine = makeFollowup($this->acme, $this->bde, $this->lead);
    $colleagueLead = makeLead($this->acme, $this->bde2, $this->ahd);
    $colleague = makeFollowup($this->acme, $this->bde2, $colleagueLead);
    $mumbaiLead = makeLead($this->acme, $this->mumBde, $this->mum);
    $mumbai = makeFollowup($this->acme, $this->mumBde, $mumbaiLead);
    $otherTeam = makeTeam($this->acme, $this->otherManager, [$this->mumBde], 'WEST', 'West team');

    $ids = function ($user, ?int $person = null, ?int $team = null) {
        $this->actingAs($user);
        app(TenantContext::class)->flush();

        return app(FollowupAgenda::class)->query($user, $person, $team)->orderBy('id')->pluck('id')->all();
    };

    expect($ids($this->bde))->toBe([$mine->id])
        ->and($ids($this->manager))->toBe([$mine->id, $colleague->id])
        ->and($ids($this->otherManager))->toBe([$mumbai->id])
        ->and($ids($this->admin))->toBe([$mine->id, $colleague->id, $mumbai->id])
        // narrowing
        ->and($ids($this->manager, $this->bde2->id))->toBe([$colleague->id])
        ->and($ids($this->admin, null, $otherTeam->id))->toBe([$mumbai->id])
        ->and($ids($this->admin, null, $this->team->id))->toBe([$mine->id, $colleague->id])
        // asking for somebody out of reach returns nothing, not theirs
        ->and($ids($this->bde, $this->bde2->id))->toBe([])
        ->and($ids($this->manager, $this->mumBde->id))->toBe([])
        ->and($ids($this->manager, null, $otherTeam->id))->toBe([])
        ->and($ids($this->bde, null, $this->team->id))->toBe([]);
});

it('gives the calendar a bounded window of planned and done follow-ups, never the ones called off', function () {
    $monday = makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-12T15:00']);
    $friday = makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-16T10:00']);
    $nextMonth = makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-11-20T10:00']);
    $nextYear = makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2027-06-01T10:00']);
    $cancelled = makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-14T10:00']);
    inCompany($this->acme, fn () => app(FollowupService::class)->cancel($cancelled, 'Not needed', $this->bde));
    ($this->complete)($monday);

    $this->actingAs($this->bde);
    $agenda = app(FollowupAgenda::class);
    $between = fn (string $from, string $to) => $agenda->between($this->bde, Carbon::parse($from), Carbon::parse($to))->pluck('id')->all();

    expect($between('2026-10-12 00:00', '2026-10-19 00:00'))->toBe([$monday->id, $friday->id])       // done and planned; not the cancelled one
        ->and($between('2026-10-16 10:00', '2026-10-16 10:01'))->toBe([$friday->id])
        ->and($between('2026-10-17 00:00', '2026-10-18 00:00'))->toBe([])
        // a window of years is cut to two months: next June is not handed over
        ->and($between('2026-10-01 00:00', '2030-01-01 00:00'))->toBe([$monday->id, $friday->id, $nextMonth->id]);

    expect($nextYear->exists)->toBeTrue();
});

// ======================================================================================================
// Reminders and escalation (the scheduler's command)
// ======================================================================================================

it('reminds the person shortly before a follow-up is due — once', function () {
    $soon = makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-12T09:50']);     // in 20 minutes
    $later = makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-12T11:00']);    // in 90 minutes

    $this->artisan('crm:followup-reminders')->expectsOutputToContain('Follow-up reminders sent: 1')->assertSuccessful();

    Notification::assertSentToTimes($this->bde, FollowupDueNotification::class, 1);
    Notification::assertSentTo($this->bde, FollowupDueNotification::class, fn ($n) => $n->what === 'Call' && ! $n->late
        && str_contains($n->with, $this->lead->lead_number) && $n->when === '12-10-2026 09:50' && $n->url === '/crm/leads/'.$this->lead->id);

    expect($soon->fresh()->reminder_sent_at)->not->toBeNull()->and($later->fresh()->reminder_sent_at)->toBeNull();

    // running it again — or twice at once — tells nobody a second time
    $this->artisan('crm:followup-reminders')->expectsOutputToContain('Follow-up reminders sent: 0');
    Notification::assertSentToTimes($this->bde, FollowupDueNotification::class, 1);

    // an hour later the second one comes within the lead time
    Carbon::setTestNow('2026-10-12 10:35:00');
    $this->artisan('crm:followup-reminders')->expectsOutputToContain('Follow-up reminders sent: 1');
    Notification::assertSentToTimes($this->bde, FollowupDueNotification::class, 2);
});

it('uses each company’s own lead time for reminders', function () {
    ($this->setting)('followups.remind_minutes_before', 120);
    makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-12T11:00']);    // 90 minutes away: inside two hours

    $this->artisan('crm:followup-reminders')->expectsOutputToContain('Follow-up reminders sent: 1');

    ($this->setting)('followups.remind_minutes_before', 0);
    makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-12T09:40']);    // ten minutes away: not yet, at zero lead time
    $this->artisan('crm:followup-reminders')->expectsOutputToContain('Follow-up reminders sent: 0');
});

it('reminds late, re-arms a moved follow-up, and stays quiet about closed leads and stale follow-ups', function () {
    $missed = makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-12T10:00']);
    $stale = makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-12T10:30']);
    $lostLead = makeLead($this->acme, $this->bde2, $this->ahd);
    makeFollowup($this->acme, $this->bde2, $lostLead, ['scheduled_at' => '2026-10-12T10:00']);
    $cancelled = makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-12T10:05']);
    inCompany($this->acme, fn () => app(FollowupService::class)->cancel($cancelled, 'Not needed', $this->bde));

    // the server was down: it is now three hours after the first, and the lead of the third has been lost meanwhile
    Carbon::setTestNow('2026-10-12 13:00:00');
    Followup::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($stale->id)->update(['scheduled_at' => '2026-10-10 10:30:00']);   // two days late
    Lead::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($lostLead->id)->update(['stage' => LeadStage::Lost->value]);

    $this->artisan('crm:followup-reminders')->expectsOutputToContain('Follow-up reminders sent: 1');

    Notification::assertSentTo($this->bde, FollowupDueNotification::class, fn ($n) => $n->late && $n->when === '12-10-2026 10:00');
    Notification::assertNotSentTo($this->bde2, FollowupDueNotification::class);
    expect($stale->fresh()->reminder_sent_at)->toBeNull();

    // moved to tomorrow: it will be reminded about again
    inCompany($this->acme, fn () => app(FollowupService::class)->reschedule($missed, '2026-10-13T13:10', $this->bde));
    Carbon::setTestNow('2026-10-13 12:50:00');
    $this->artisan('crm:followup-reminders')->expectsOutputToContain('Follow-up reminders sent: 1');
    Notification::assertSentToTimes($this->bde, FollowupDueNotification::class, 2);
});

it('tells the manager once when a follow-up has been left overdue for a day', function () {
    $followup = makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-12T10:00']);

    Carbon::setTestNow('2026-10-13 09:00:00');      // 23 hours late: not yet
    $this->artisan('crm:followup-reminders')->expectsOutputToContain('Overdue follow-ups escalated: 0');
    Notification::assertNotSentTo($this->manager, FollowupOverdueNotification::class);

    Carbon::setTestNow('2026-10-13 10:05:00');      // a day late
    $this->artisan('crm:followup-reminders')->expectsOutputToContain('Overdue follow-ups escalated: 1');

    Notification::assertSentToTimes($this->manager, FollowupOverdueNotification::class, 1);
    Notification::assertSentTo($this->manager, FollowupOverdueNotification::class, fn ($n) => $n->person === $this->bde->name && $n->what === 'Call'
        && str_contains($n->with, $this->lead->lead_number) && $n->when === '12-10-2026 10:00');
    Notification::assertNotSentTo($this->bde, FollowupOverdueNotification::class);
    expect($followup->fresh()->escalated_at)->not->toBeNull();

    $this->artisan('crm:followup-reminders')->expectsOutputToContain('Overdue follow-ups escalated: 0');
    Notification::assertSentToTimes($this->manager, FollowupOverdueNotification::class, 1);
});

it('escalates by the company’s own threshold, not at all when it is switched off, and never to the person themselves', function () {
    makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-12T10:00']);
    // the manager's own overdue follow-up has nobody above it in the team
    $managersLead = makeLead($this->acme, $this->manager, $this->ahd, ['assigned_to' => (string) $this->manager->id]);
    makeFollowup($this->acme, $this->manager, $managersLead, ['scheduled_at' => '2026-10-12T10:00']);
    // someone on no team has nobody to be escalated to
    $loner = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $lonerLead = makeLead($this->acme, $loner, $this->ahd);
    makeFollowup($this->acme, $loner, $lonerLead, ['scheduled_at' => '2026-10-12T10:00']);

    ($this->setting)('followups.escalate_after_hours', 0);
    Carbon::setTestNow('2026-10-14 10:00:00');
    $this->artisan('crm:followup-reminders')->expectsOutputToContain('Overdue follow-ups escalated: 0');
    Notification::assertNothingSentTo($this->manager);

    ($this->setting)('followups.escalate_after_hours', 4);
    $this->artisan('crm:followup-reminders')->expectsOutputToContain('Overdue follow-ups escalated: 1');
    Notification::assertSentToTimes($this->manager, FollowupOverdueNotification::class, 1);
});

it('keeps one company’s reminders to its own people', function () {
    $globexBranch = makeLocation($this->globex, 'NYC');
    $globexUser = makeUser(['BDE / Field Salesperson'], [$this->globex]);
    $globexLead = inCompany($this->globex, fn () => app(LeadService::class)->create(leadInput($this->globex, $globexBranch, ['mobile' => nextMobile(), 'name' => 'Globex Prospect']), $globexUser));
    inCompany($this->globex, fn () => app(FollowupService::class)->schedule($globexLead, ['plan' => ['scheduled_at' => '2026-10-12T09:45', 'type' => 'call', 'assigned_to' => '', 'notes' => '']], $globexUser));
    makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-12T09:45']);

    $this->artisan('crm:followup-reminders')->expectsOutputToContain('Follow-up reminders sent: 2');

    Notification::assertSentTo($globexUser, FollowupDueNotification::class, fn ($n) => str_contains($n->with, 'Globex Prospect') && $n->url === '/crm/leads/'.$globexLead->id);
    Notification::assertSentTo($this->bde, FollowupDueNotification::class, fn ($n) => ! str_contains($n->with, 'Globex'));
    Notification::assertSentToTimes($this->bde, FollowupDueNotification::class, 1);
});

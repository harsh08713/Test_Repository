<?php

use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Services\FollowupService;
use App\Modules\CRM\Services\LeadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->nyc = makeLocation($this->globex, 'NYC');

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde, $this->telecaller]);

    $this->lead = makeLead($this->acme, $this->bde, $this->ahd);
    $this->plan = fn (array $over = [], $as = null, $lead = null) => apiCall('POST', '/api/v1/leads/'.($lead ?? $this->lead)->id.'/followups', [
        'scheduled_at' => '2026-10-14T11:00:00+05:30', 'type' => 'call', ...$over,
    ], $as ?? $this->bde, idem());
    $this->ids = fn ($response) => collect($response->json('data'))->pluck('id')->all();
});

afterEach(fn () => Carbon::setTestNow());

// ---- planning and logging -----------------------------------------------------------------------------

it('plans a follow-up, reading a time with an offset as the instant it names and one without as company wall-clock time', function () {
    $made = ($this->plan)(['notes' => 'Ask about the bolts'])->assertCreated();

    expect($made->json('data.scheduled_at'))->toBe('2026-10-14T11:00:00+05:30')->and($made->json('data.status.value'))->toBe('pending')
        ->and($made->json('data.type'))->toBe(['value' => 'call', 'label' => 'Call'])
        ->and($made->json('data.assigned_to.id'))->toBe($this->bde->id)->and($made->json('data.lead.lead_number'))->toBe($this->lead->lead_number);

    // 05:30 UTC is 11:00 in India; a bare "11:00" is already India time
    expect(($this->plan)(['scheduled_at' => '2026-10-15T05:30:00Z'])->json('data.scheduled_at'))->toBe('2026-10-15T11:00:00+05:30')
        ->and(($this->plan)(['scheduled_at' => '2026-10-16T11:00'])->json('data.scheduled_at'))->toBe('2026-10-16T11:00:00+05:30')
        ->and(Followup::query()->orderBy('id')->pluck('scheduled_at')->map->toDateTimeString()->all())->toBe(['2026-10-14 11:00:00', '2026-10-15 11:00:00', '2026-10-16 11:00:00']);

    expect($this->lead->fresh()->next_followup_at->toDateTimeString())->toBe('2026-10-14 11:00:00');
});

it('checks what was sent: the time, the kind, who it is for, and that the lead is open and the caller’s to follow up', function () {
    $fields = fn ($response) => array_keys($response->assertStatus(422)->json('error.fields'));

    expect($fields(($this->plan)(['scheduled_at' => '2026-10-10T11:00:00+05:30'])))->toBe(['scheduled_at'])    // in the past
        ->and($fields(($this->plan)(['scheduled_at' => 'tomorrow'])))->toBe(['scheduled_at'])
        ->and($fields(($this->plan)(['scheduled_at' => '2026-02-31T10:00:00'])))->toBe(['scheduled_at'])
        ->and($fields(($this->plan)(['type' => 'smoke-signal'])))->toBe(['type'])
        ->and($fields(($this->plan)(['assigned_to' => $this->bde2->id])))->toBe(['assigned_to']);              // not someone they manage

    apiCall('POST', "/api/v1/leads/{$this->lead->id}/followups", ['scheduled_at' => '2026-10-14T11:00:00+05:30', 'type' => 'call'], $this->bde)
        ->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');

    $other = makeLead($this->acme, $this->bde2, $this->ahd);
    ($this->plan)([], $this->bde, $other)->assertNotFound();

    inCompany($this->acme, fn () => app(LeadService::class)->changeStage($this->lead, LeadStage::Lost, $this->bde, null, lostReasonId($this->acme)));
    ($this->plan)()->assertStatus(409)->assertJsonPath('error.code', 'state_conflict');
});

it('lets a manager plan a call for someone on their team, who then sees it', function () {
    ($this->plan)(['assigned_to' => $this->telecaller->id], $this->manager)->assertCreated()->assertJsonPath('data.assigned_to.id', $this->telecaller->id);

    $mine = apiCall('GET', '/api/v1/followups', as: $this->telecaller)->assertOk();
    expect($mine->json('data'))->toHaveCount(1)->and($mine->json('data.0.lead.name'))->toBe('Shree Ganesh Traders');
});

it('records something already done as a logged follow-up, moving a new lead to contacted', function () {
    $response = apiCall('POST', "/api/v1/leads/{$this->lead->id}/followups/log", [
        'type' => 'visit', 'outcome_id' => outcomeId($this->acme, 'INTERESTED'), 'notes' => 'Showed the catalogue',
    ], $this->bde, idem())->assertCreated();

    expect($response->json('data.status.value'))->toBe('done')->and($response->json('data.outcome.name'))->toBe('Interested')
        ->and($response->json('data.completed_at'))->toBe('2026-10-12T09:30:00+05:30')
        ->and($this->lead->fresh()->stage)->toBe(LeadStage::Contacted);
});

it('asks for the next step when the outcome needs one, and plans it linked to the follow-up that led to it', function () {
    $log = fn (array $over = []) => apiCall('POST', "/api/v1/leads/{$this->lead->id}/followups/log", [
        'type' => 'call', 'outcome_id' => outcomeId($this->acme, 'CALL_BACK_LATER'), ...$over,
    ], $this->bde, idem());

    expect(array_keys($log()->assertStatus(422)->json('error.fields')))->toBe(['next_at']);

    $done = $log(['next_at' => '2026-10-13T16:00:00+05:30'])->assertCreated();
    $next = Followup::query()->where('previous_id', $done->json('data.id'))->sole();

    expect($next->scheduled_at->toDateTimeString())->toBe('2026-10-13 16:00:00')->and($next->status->value)->toBe('pending')->and($next->type->value)->toBe('call');
});

// ---- working a planned follow-up --------------------------------------------------------------------

it('completes a planned follow-up with an outcome, once', function () {
    $planned = ($this->plan)()->json('data.id');
    $complete = fn (array $over = [], $as = null) => apiCall('POST', "/api/v1/followups/{$planned}/complete", ['outcome_id' => outcomeId($this->acme, 'NOT_AVAILABLE'), 'notes' => 'Phone off', ...$over], $as ?? $this->bde, idem());

    expect(array_keys($complete(['outcome_id' => 999999])->assertStatus(422)->json('error.fields')))->toBe(['outcome_id']);

    $done = $complete()->assertOk();
    expect($done->json('data.status.value'))->toBe('done')->and($done->json('data.notes'))->toContain('Phone off')->and($done->json('data.outcome.name'))->toBe('Not Available');

    $complete()->assertStatus(409)->assertJsonPath('error.code', 'state_conflict');
    expect($this->lead->fresh()->next_followup_at)->toBeNull();
});

it('moves a planned follow-up and calls one off with a reason', function () {
    $planned = ($this->plan)()->json('data.id');

    apiCall('POST', "/api/v1/followups/{$planned}/reschedule", ['scheduled_at' => '2026-10-20T15:30:00+05:30'], $this->bde, idem())
        ->assertOk()->assertJsonPath('data.scheduled_at', '2026-10-20T15:30:00+05:30');
    apiCall('POST', "/api/v1/followups/{$planned}/reschedule", ['scheduled_at' => '2026-10-01T10:00:00+05:30'], $this->bde, idem())->assertStatus(422);

    apiCall('POST', "/api/v1/followups/{$planned}/cancel", ['reason' => 'x'], $this->bde, idem())->assertStatus(422);
    $cancelled = apiCall('POST', "/api/v1/followups/{$planned}/cancel", ['reason' => 'Customer is away'], $this->bde, idem())->assertOk();

    expect($cancelled->json('data.status.value'))->toBe('cancelled')->and($cancelled->json('data.notes'))->toContain('Called off: Customer is away');
    apiCall('POST', "/api/v1/followups/{$planned}/reschedule", ['scheduled_at' => '2026-10-21T15:30:00+05:30'], $this->bde, idem())->assertStatus(409);
});

it('keeps follow-ups to their owner’s line: another person’s is not found, a manager’s team member’s is workable', function () {
    $planned = ($this->plan)()->json('data.id');
    $theirs = Followup::query()->whereKey(makeFollowup($this->acme, $this->bde2, makeLead($this->acme, $this->bde2, $this->ahd))->id)->sole();

    apiCall('POST', "/api/v1/followups/{$theirs->id}/cancel", ['reason' => 'Not mine to cancel'], $this->bde, idem())->assertNotFound();
    apiCall('POST', "/api/v1/followups/{$planned}/cancel", ['reason' => 'Not theirs either'], $this->bde2, idem())->assertNotFound();
    expect($theirs->fresh()->status->value)->toBe('pending');

    apiCall('POST', "/api/v1/followups/{$planned}/reschedule", ['scheduled_at' => '2026-10-22T10:00:00+05:30'], $this->manager, idem())->assertOk();
});

it('does not reach into another company', function () {
    $foreignUser = makeUser(['Sales Manager'], [$this->globex]);
    $foreignLead = makeLead($this->globex, $foreignUser, $this->nyc, ['assigned_to' => (string) $foreignUser->id]);
    $foreign = makeFollowup($this->globex, $foreignUser, $foreignLead);

    ($this->plan)([], $this->manager, $foreignLead)->assertNotFound();
    apiCall('POST', "/api/v1/followups/{$foreign->id}/cancel", ['reason' => 'Not ours to touch'], $this->manager, idem())->assertNotFound();
    expect(apiCall('GET', '/api/v1/followups', as: $this->manager)->json('data'))->toBe([])
        ->and($foreign->fresh()->status->value)->toBe('pending');
});

// ---- reading ------------------------------------------------------------------------------------------

it('lists a person’s follow-ups by state, kind and lead — and a manager’s team’s, narrowed on request', function () {
    $second = makeLead($this->acme, $this->bde, $this->ahd);
    $overdue = makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-12T10:00']);
    $today = makeFollowup($this->acme, $this->bde, $second, ['scheduled_at' => '2026-10-12T17:00', 'type' => 'visit']);
    $later = makeFollowup($this->acme, $this->bde, $second, ['scheduled_at' => '2026-10-20T11:00']);
    $theirs = makeFollowup($this->acme, $this->bde2, makeLead($this->acme, $this->bde2, $this->ahd));

    Carbon::setTestNow('2026-10-12 12:00:00');   // the 10:00 one is now late

    $ids = fn (array $q, $as = null) => ($this->ids)(apiCall('GET', '/api/v1/followups', $q, $as ?? $this->bde)->assertOk());

    expect($ids(['status' => 'pending']))->toBe([$overdue->id, $today->id, $later->id])                  // what is next, first
        ->and($ids(['status' => 'overdue']))->toBe([$overdue->id])
        ->and($ids(['status' => 'today']))->toBe([$overdue->id, $today->id])
        ->and($ids(['type' => 'visit']))->toBe([$today->id])
        ->and($ids(['lead_id' => $second->id, 'status' => 'pending']))->toBe([$today->id, $later->id])
        ->and($ids(['from' => '2026-10-15T00:00:00+05:30', 'to' => '2026-10-25T00:00:00+05:30']))->toBe([$later->id])
        ->and($ids(['person' => $this->bde2->id]))->toBe([]);                                           // asking cannot widen it

    expect($ids([], $this->manager))->toContain($overdue->id)->not->toContain($theirs->id)
        ->and($ids(['person' => $this->bde->id], $this->manager))->toBe([$later->id, $today->id, $overdue->id])
        ->and($ids(['team' => $this->team->id], $this->manager))->toBe([$later->id, $today->id, $overdue->id]);
    apiCall('GET', '/api/v1/followups', ['status' => 'soon'], $this->bde)->assertStatus(422);
});

it('syncs follow-ups changed since a moment, called-off ones included', function () {
    $keep = makeFollowup($this->acme, $this->bde, $this->lead);
    Carbon::setTestNow('2026-10-12 11:00:00');
    $off = makeFollowup($this->acme, $this->bde, makeLead($this->acme, $this->bde, $this->ahd));
    Carbon::setTestNow('2026-10-12 12:00:00');
    apiCall('POST', "/api/v1/followups/{$off->id}/cancel", ['reason' => 'Not needed now'], $this->bde, idem())->assertOk();

    $sync = apiCall('GET', '/api/v1/followups', ['updated_since' => '2026-10-12T10:00:00+05:30'], $this->bde)->assertOk();

    expect(collect($sync->json('data'))->pluck('id')->all())->toBe([$off->id])
        ->and($sync->json('data.0.status.value'))->toBe('cancelled')->and($sync->json('meta.server_time'))->toBe('2026-10-12T12:00:00+05:30');
});

it('shows a calendar window, without called-off follow-ups, capped at two months', function () {
    $in = makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-14T11:00']);
    $outside = makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-11-20T11:00']);
    $off = makeFollowup($this->acme, $this->bde, $this->lead, ['scheduled_at' => '2026-10-15T11:00']);
    inCompany($this->acme, fn () => app(FollowupService::class)->cancel($off, 'Not needed', $this->bde));

    $week = apiCall('GET', '/api/v1/followups/calendar', ['from' => '2026-10-12T00:00:00+05:30', 'to' => '2026-10-19T00:00:00+05:30'], $this->bde)->assertOk();

    expect(collect($week->json('data'))->pluck('id')->all())->toBe([$in->id])
        ->and($week->json('meta.window'))->toBe(['from' => '2026-10-12T00:00:00+05:30', 'to' => '2026-10-19T00:00:00+05:30', 'capped' => false]);

    $wide = apiCall('GET', '/api/v1/followups/calendar', ['from' => '2026-10-01T00:00:00+05:30', 'to' => '2027-10-01T00:00:00+05:30'], $this->bde)->assertOk();
    expect($wide->json('meta.window.capped'))->toBeTrue()->and($wide->json('meta.window.to'))->toBe('2026-12-02T00:00:00+05:30')
        ->and(collect($wide->json('data'))->pluck('id')->all())->toBe([$in->id, $outside->id]);

    expect(array_keys(apiCall('GET', '/api/v1/followups/calendar', as: $this->bde)->assertStatus(422)->json('error.fields')))->toBe(['from', 'to']);
});

it('keeps the follow-up screens and the API in step: what is planned over the API is what the lead page shows', function () {
    $made = ($this->plan)()->json('data');

    expect(DB::table('followups')->where('id', $made['id'])->value('subject_type'))->toBe('lead')
        ->and(apiCall('GET', "/api/v1/leads/{$this->lead->id}", as: $this->bde)->json('data.followups.0.id'))->toBe($made['id']);
});

<?php

use App\Modules\Api\Services\SyncRunner;
use App\Modules\Api\Support\ApiTime;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\GeofenceViolation;
use App\Modules\CRM\Models\Lead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->geo = seedGeography($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->nyc = makeLocation($this->globex, 'NYC');

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);

    // one queued change, as an app would write it
    $this->op = fn (string $id, string $method, string $path, ?array $body = null, ?string $ref = null) => array_filter(
        ['id' => $id, 'method' => $method, 'path' => $path, 'body' => $body, 'ref' => $ref], fn ($v) => $v !== null,
    );
    $this->newLead = fn (array $over = []) => ['name' => 'Shree Ganesh Traders', 'mobile' => nextMobile(), 'location_id' => $this->ahd->id, 'lead_source_id' => leadSourceId($this->acme, 'REFERENCE'), ...$over];
    $this->followup = ['scheduled_at' => '2026-10-14T11:00:00+05:30', 'type' => 'call'];
    $this->sync = fn (array $operations, $as = null) => apiCall('POST', '/api/v1/sync', ['operations' => $operations], $as ?? $this->telecaller);
    $this->statuses = fn ($response) => collect($response->json('data.results'))->pluck('status')->all();
});

afterEach(fn () => Carbon::setTestNow());

// ---- a queue, in order, through the real endpoints -------------------------------------------------------------

it('runs a queued lead and the follow-up planned for it, in order, naming the lead by its ref', function () {
    $response = ($this->sync)([
        ($this->op)('op-lead-0001', 'POST', '/leads', ($this->newLead)(), 'lead-1'),
        ($this->op)('op-plan-0001', 'POST', '/leads/@lead-1/followups', $this->followup),
    ])->assertOk();

    $lead = Lead::query()->sole();

    expect(($this->statuses)($response))->toBe(['applied', 'applied'])
        ->and($response->json('data.summary.applied'))->toBe(2)->and($response->json('data.summary.conflict'))->toBe(0)
        ->and($response->json('data.results.0.id'))->toBe('op-lead-0001')->and($response->json('data.results.0.ref'))->toBe('lead-1')
        ->and($response->json('data.results.0.http_status'))->toBe(201)->and($response->json('data.results.0.data.id'))->toBe($lead->id)
        ->and(Followup::query()->sole()->subject_id)->toBe($lead->id)->and($response->json('data.results.1.http_status'))->toBe(201)
        ->and($lead->created_by)->toBe($this->telecaller->id)->and($lead->entry_channel->value)->toBe('manual');
});

it('is safe to send again: the same queue is replayed, and nothing runs twice', function () {
    $queue = [
        ($this->op)('op-lead-0002', 'POST', '/leads', ($this->newLead)(), 'lead-1'),
        ($this->op)('op-plan-0002', 'POST', '/leads/@lead-1/followups', $this->followup),
    ];

    $first = ($this->sync)($queue)->assertOk();
    $again = ($this->sync)($queue)->assertOk();

    expect(($this->statuses)($again))->toBe(['replayed', 'replayed'])->and($again->json('data.summary.replayed'))->toBe(2)
        ->and($again->json('data.results.0.data.id'))->toBe($first->json('data.results.0.data.id'))      // the same lead, with the same number
        ->and($again->json('data.results.1.data.id'))->toBe($first->json('data.results.1.data.id'))
        ->and(Lead::query()->count())->toBe(1)->and(Followup::query()->count())->toBe(1);
});

it('lets a half-finished queue be sent again from the start: what went through is replayed, what did not now runs', function () {
    $queue = [
        ($this->op)('op-lead-0003', 'POST', '/leads', ($this->newLead)(), 'lead-1'),
        ($this->op)('op-plan-0003', 'POST', '/leads/@lead-1/followups', ['scheduled_at' => 'next tuesday', 'type' => 'call']),
    ];

    $first = ($this->sync)($queue)->assertOk();

    expect(($this->statuses)($first))->toBe(['applied', 'rejected'])->and($first->json('data.results.1.fields'))->toHaveKey('scheduled_at');

    $queue[1]['body'] = $this->followup;   // the person corrected the date; the same operation id is fine, the request is different but it never ran

    $second = ($this->sync)($queue)->assertOk();

    expect(($this->statuses)($second))->toBe(['replayed', 'applied'])->and(Lead::query()->count())->toBe(1)->and(Followup::query()->count())->toBe(1);
});

it('keeps going past a change that is refused or in conflict, and says which and why', function () {
    $lead = makeLead($this->acme, $this->telecaller, $this->ahd);
    $loaded = ApiTime::iso($lead->updated_at);

    Carbon::setTestNow('2026-10-12 09:45:00');
    apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['remarks' => 'Changed on the web meanwhile'], $this->telecaller)->assertOk();

    $response = ($this->sync)([
        ($this->op)('op-edit-0004', 'PATCH', "/leads/{$lead->id}", ['remarks' => 'My offline note', 'expected_updated_at' => $loaded]),
        ($this->op)('op-lead-0004', 'POST', '/leads', ['mobile' => nextMobile()]),                              // no name
        ($this->op)('op-lead-0005', 'POST', '/leads', ($this->newLead)(['name' => 'Fine Traders'])),
    ])->assertOk();

    expect(($this->statuses)($response))->toBe(['conflict', 'rejected', 'applied'])
        ->and($response->json('data.results.0.code'))->toBe('version_conflict')->and($response->json('data.results.0.current.remarks'))->toBe('Changed on the web meanwhile')
        ->and($response->json('data.results.1.http_status'))->toBe(422)->and($response->json('data.results.1.fields'))->toHaveKey('name')
        ->and($response->json('data.summary'))->toMatchArray(['applied' => 1, 'conflict' => 1, 'rejected' => 1, 'skipped' => 0])
        ->and($lead->fresh()->remarks)->toBe('Changed on the web meanwhile')                                       // the server's version stands; nothing was merged
        ->and(Lead::query()->where('name', 'Fine Traders')->exists())->toBeTrue();
});

it('does not send what depends on a change that did not go through', function () {
    $response = ($this->sync)([
        ($this->op)('op-lead-0006', 'POST', '/leads', ['mobile' => nextMobile()], 'lead-1'),                       // refused: no name
        ($this->op)('op-plan-0006', 'POST', '/leads/@lead-1/followups', $this->followup),
        ($this->op)('op-lead-0007', 'POST', '/leads', ($this->newLead)(['name' => 'Independent Traders'])),          // does not depend on it
    ])->assertOk();

    expect(($this->statuses)($response))->toBe(['rejected', 'skipped', 'applied'])->and($response->json('data.results.1.code'))->toBe('depends_on_failed')
        ->and(Followup::query()->count())->toBe(0)->and(Lead::query()->count())->toBe(1);
});

it('refuses a ref that no earlier operation made, and does not guess an id', function () {
    $response = ($this->sync)([($this->op)('op-plan-0008', 'POST', '/leads/@ghost/followups', $this->followup)])->assertOk();

    expect(($this->statuses)($response))->toBe(['rejected'])->and($response->json('data.results.0.code'))->toBe('unknown_ref')->and(Followup::query()->count())->toBe(0);
});

// ---- only what can be sent again safely ---------------------------------------------------------------------------

it('queues only changes the app may send again safely: not signing in or out, not a nested queue, not a path that does not exist', function () {
    $token = apiToken($this->telecaller);

    $response = apiCall('POST', '/api/v1/sync', ['operations' => [
        ($this->op)('op-out-00001', 'POST', '/auth/logout'),
        ($this->op)('op-ref-00001', 'POST', '/auth/refresh'),
        ($this->op)('op-nest-0001', 'POST', '/sync', ['operations' => []]),
        ($this->op)('op-none-0001', 'POST', '/nothing/here'),
        ($this->op)('op-wrong-001', 'POST', '/leads/12'),             // a path that exists only for GET/PATCH/DELETE
    ]], $token)->assertOk();

    expect(($this->statuses)($response))->toBe(['rejected', 'rejected', 'rejected', 'rejected', 'rejected'])
        ->and(collect($response->json('data.results'))->pluck('code')->unique()->all())->toBe(['not_syncable']);

    // the logout inside the queue did not happen: the device is still signed in
    apiCall('GET', '/api/v1/me', as: $token)->assertOk();
});

it('reads the shape of the queue strictly', function () {
    $good = ($this->op)('op-lead-0009', 'POST', '/leads', ($this->newLead)());
    $bad = fn (array $operations, string $field) => expect(array_keys(($this->sync)($operations)->assertStatus(422)->json('error.fields')))->toContain($field);

    $bad([], 'operations');
    $bad(array_map(fn ($i) => ($this->op)(sprintf('op-many-%04d', $i), 'POST', '/leads', ($this->newLead)()), range(1, SyncRunner::MAX_OPERATIONS + 1)), 'operations');
    $bad([$good, $good], 'operations.1.id');                                                              // an id is used once
    $bad([($this->op)('short', 'POST', '/leads')], 'operations.0.id');
    $bad([($this->op)('op-get-00001', 'GET', '/leads')], 'operations.0.method');
    $bad([($this->op)('op-dots-0001', 'POST', '/leads/../me')], 'operations.0.path');
    $bad([($this->op)('op-query-001', 'POST', '/leads?x=1')], 'operations.0.path');
    $bad([($this->op)('op-host-0001', 'POST', 'https://evil.example/leads')], 'operations.0.path');
    $bad([[...$good, 'ref' => 'has space']], 'operations.0.ref');
    $bad([($this->op)('op-body-0001', 'POST', '/leads', null) + ['body' => 'text']], 'operations.0.body');
    $bad([[...$good, 'extra' => 'not allowed']], 'operations.0');

    expect(Lead::query()->count())->toBe(0);
});

// ---- nothing is trusted more because it was queued ------------------------------------------------------------------------

it('keeps the geofence: a field lead saved from outside it is refused and logged, exactly as sent live', function () {
    makePlan($this->acme, $this->manager, $this->bde, $this->ahd);

    $response = ($this->sync)([
        ($this->op)('op-field-0001', 'POST', '/leads', [...($this->newLead)(), 'position' => ['latitude' => HQ_LAT + 0.0075, 'longitude' => HQ_LNG, 'accuracy_m' => 12]]),
    ], $this->bde)->assertOk();

    $entry = GeofenceViolation::query()->sole();

    expect(($this->statuses)($response))->toBe(['rejected'])->and($response->json('data.results.0.http_status'))->toBe(422)->and($response->json('data.results.0.code'))->toBe('outside_geofence')
        ->and($entry->attempt_type)->toBe('lead_save')->and($entry->user_id)->toBe($this->bde->id)->and(Lead::query()->count())->toBe(0);
});

it('keeps the permissions and the reach of the person: a lead that is not theirs is not found, a right they lack is refused', function () {
    $theirs = makeLead($this->acme, $this->bde2, $this->ahd);

    Role::create(['name' => 'Looks only'])->givePermissionTo(['api.access', 'lead.view']);
    $looker = makeUser(['Looks only'], [$this->acme]);

    $notMine = ($this->sync)([($this->op)('op-edit-0010', 'PATCH', "/leads/{$theirs->id}", ['name' => 'Taken over'])], $this->bde)->assertOk();
    $noRight = ($this->sync)([($this->op)('op-lead-0010', 'POST', '/leads', ($this->newLead)())], $looker)->assertOk();

    expect(($this->statuses)($notMine))->toBe(['rejected'])->and($notMine->json('data.results.0.http_status'))->toBe(404)
        ->and($theirs->fresh()->name)->not->toBe('Taken over')
        ->and(($this->statuses)($noRight))->toBe(['rejected'])->and($noRight->json('data.results.0.http_status'))->toBe(403)->and(Lead::query()->count())->toBe(1);
});

it('refuses the whole queue to a device that may only read — and to somebody who is not signed in', function () {
    $queue = [($this->op)('op-lead-0011', 'POST', '/leads', ($this->newLead)())];

    apiCall('POST', '/api/v1/sync', ['operations' => $queue], apiToken($this->telecaller, ['read']))->assertForbidden()->assertJsonPath('error.code', 'scope_missing');
    apiCall('POST', '/api/v1/sync', ['operations' => $queue])->assertUnauthorized();

    expect(Lead::query()->count())->toBe(0);
});

it('does not let another company’s work in: the queue runs in the company the device named', function () {
    $outsider = makeUser(['Tele-caller'], [$this->acme]);
    $globexLead = makeLead($this->globex, makeUser(['Company Admin'], [$this->globex]), $this->nyc);

    $response = ($this->sync)([($this->op)('op-edit-0012', 'PATCH', "/leads/{$globexLead->id}", ['name' => 'Not yours'])], $outsider)->assertOk();

    expect(($this->statuses)($response))->toBe(['rejected'])->and($response->json('data.results.0.http_status'))->toBe(404)
        ->and(Lead::withoutGlobalScopes()->find($globexLead->id)->name)->not->toBe('Not yours');
});

// ---- what stops a queue -----------------------------------------------------------------------------------------------------

it('stops trying the rest of the queue when the server asks the app to slow down, and says so', function () {
    Route::middleware(['api', 'auth:sanctum', 'api.context'])->post('api/v1/zz-busy', fn () => response()->json(['error' => ['code' => 'too_many_requests', 'message' => 'Slow down.']], 429, ['Retry-After' => '30']))
        ->middleware('idempotent')->name('api.v1.zz.busy');

    // the app keeps what it queued: the same changes go again, byte for byte, so the keys recognise them
    $before = ($this->op)('op-lead-0013', 'POST', '/leads', ($this->newLead)(['name' => 'Before']), 'lead-1');
    $after = ($this->op)('op-lead-0014', 'POST', '/leads', ($this->newLead)(['name' => 'After']));

    $response = ($this->sync)([$before, ($this->op)('op-busy-0013', 'POST', '/zz-busy'), $after])->assertOk();

    expect(($this->statuses)($response))->toBe(['applied', 'retry', 'skipped'])->and($response->json('data.results.1.retry_after'))->toBe(30)
        ->and($response->json('data.results.2.code'))->toBe('batch_stopped')->and($response->json('data.results.2.message'))->toContain('slow down')
        ->and(Lead::query()->count())->toBe(1);

    // sent again later, the first is replayed and the last now goes through
    $retry = ($this->sync)([$before, $after])->assertOk();

    expect(($this->statuses)($retry))->toBe(['replayed', 'applied'])->and(Lead::query()->count())->toBe(2);
});

it('reports a crash as failed, keeps what already went through, and carries on with what does not depend on it', function () {
    Route::middleware(['api', 'auth:sanctum', 'api.context'])->post('api/v1/zz-broken', fn () => throw new RuntimeException('boom'))->middleware('idempotent')->name('api.v1.zz.broken');

    $response = ($this->sync)([
        ($this->op)('op-lead-0015', 'POST', '/leads', ($this->newLead)(['name' => 'First'])),
        ($this->op)('op-broke-0015', 'POST', '/zz-broken'),
        ($this->op)('op-lead-0016', 'POST', '/leads', ($this->newLead)(['name' => 'Third'])),
    ])->assertOk();

    expect(($this->statuses)($response))->toBe(['applied', 'failed', 'applied'])->and($response->json('data.results.1.http_status'))->toBe(500)
        ->and(json_encode($response->json()))->not->toContain('boom')                  // what broke is for the log, not for the phone
        ->and(Lead::query()->count())->toBe(2);
});

it('leaves the request as it found it: the next thing the server does is the caller’s, not the last queued operation’s', function () {
    $response = ($this->sync)([($this->op)('op-lead-0017', 'POST', '/leads', ($this->newLead)())])->assertOk();

    // the envelope belongs to the call that was made, with its own request id, not to the queued operation
    expect($response->json('meta.request_id'))->not->toBeEmpty()->and($response->json('data.results.0.status'))->toBe('applied');

    apiCall('GET', '/api/v1/me', as: $this->telecaller)->assertOk();
});

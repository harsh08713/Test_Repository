<?php

use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');

    $this->acme = makeCompany('ACME');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->token = apiToken($this->telecaller);

    $this->body = fn (array $over = []) => ['name' => 'Shree Ganesh Traders', 'mobile' => '9876543210', 'location_id' => $this->ahd->id, 'lead_source_id' => leadSourceId($this->acme, 'REFERENCE'), ...$over];
    // forget the lead as if the first request had never got as far as saving it
    $this->wipeLeads = function () {
        Schema::disableForeignKeyConstraints();
        foreach (['lead_stage_history', 'lead_transfers', 'lead_interests', 'followups', 'leads'] as $table) {
            DB::table($table)->delete();
        }
        Schema::enableForeignKeyConstraints();
    };
    $this->create = fn (array $body, array $headers) => apiCall('POST', '/api/v1/leads', $body, $this->token, $headers);
});

afterEach(fn () => Carbon::setTestNow());

it('answers a repeat of a successful create with the first answer, marked as a replay, and creates nothing more', function () {
    $key = idem();

    $first = ($this->create)(($this->body)(), $key)->assertCreated();
    $second = ($this->create)(($this->body)(), $key)->assertCreated();

    expect($second->headers->get('Idempotent-Replayed'))->toBe('true')->and($first->headers->get('Idempotent-Replayed'))->toBeNull()
        ->and($second->json('data'))->toBe($first->json('data'))
        ->and(Lead::query()->count())->toBe(1)
        ->and(DB::table('api_idempotency_keys')->sole()->state)->toBe('completed');
});

it('refuses the same key for a different request, rather than quietly replaying the wrong answer', function () {
    $key = idem();
    ($this->create)(($this->body)(), $key)->assertCreated();

    ($this->create)(($this->body)(['mobile' => '9876500000']), $key)->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_reused');

    // another address, another company, another verb: all different requests
    apiCall('PATCH', '/api/v1/leads/'.Lead::query()->value('id'), ['city' => 'Surat'], $this->token, $key)->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_reused');
    expect(Lead::query()->count())->toBe(1);
});

it('treats the same body with its fields in another order as the same request', function () {
    $key = idem();
    $body = ($this->body)();

    ($this->create)($body, $key)->assertCreated();
    ($this->create)(array_reverse($body, true), $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect(Lead::query()->count())->toBe(1);
});

it('keeps one person’s keys apart from another’s', function () {
    $key = idem();
    $other = makeUser(['Tele-caller'], [$this->acme]);

    ($this->create)(($this->body)(), $key)->assertCreated();
    apiCall('POST', '/api/v1/leads', ($this->body)(['mobile' => '9876511111']), $other, $key)->assertCreated();

    expect(Lead::query()->count())->toBe(2)->and(DB::table('api_idempotency_keys')->count())->toBe(2);
});

it('lets a person fix a refused request and send it again under the same key', function () {
    $key = idem();

    ($this->create)(($this->body)(['mobile' => '12345']), $key)->assertStatus(422);
    expect(DB::table('api_idempotency_keys')->count())->toBe(0);       // a refusal does not hold the key

    ($this->create)(($this->body)(), $key)->assertCreated();
    expect(Lead::query()->count())->toBe(1);
});

it('releases the key when the server fails, so the retry can run', function () {
    $key = idem();
    $this->app->bind(LeadService::class, fn () => throw new RuntimeException('boom'));

    ($this->create)(($this->body)(), $key)->assertStatus(500);

    expect(DB::table('api_idempotency_keys')->count())->toBe(0);
});

it('tells a request that arrives while the same one is still running to wait', function () {
    $key = idem();
    ($this->create)(($this->body)(), $key)->assertCreated();
    // as if the first were still in flight
    DB::table('api_idempotency_keys')->update(['state' => 'started', 'response_status' => null, 'response_body' => null, 'completed_at' => null, 'created_at' => now()]);
    (($this->wipeLeads))();

    $busy = ($this->create)(($this->body)(), $key)->assertStatus(409);

    expect($busy->json('error.code'))->toBe('request_in_progress')->and($busy->headers->get('Retry-After'))->toBe('1')->and(Lead::query()->count())->toBe(0);
});

it('takes over a key whose request never finished, after the stale period', function () {
    $key = idem();
    ($this->create)(($this->body)(), $key)->assertCreated();
    DB::table('api_idempotency_keys')->update(['state' => 'started', 'response_status' => null, 'response_body' => null, 'completed_at' => null, 'created_at' => now()->subSeconds(61)]);
    (($this->wipeLeads))();

    ($this->create)(($this->body)(), $key)->assertCreated();

    expect(Lead::query()->count())->toBe(1)->and(DB::table('api_idempotency_keys')->sole()->state)->toBe('completed');
});

it('forgets a key when its time is up, and the prune command removes it', function () {
    $key = idem();
    ($this->create)(($this->body)(), $key)->assertCreated();

    Carbon::setTestNow(now()->addHours(49));
    $this->token = apiToken($this->telecaller);   // the first one has expired by now
    ($this->create)(($this->body)(['mobile' => '9876522222']), $key)->assertCreated();       // a different request under an expired key is a new one
    expect(Lead::query()->count())->toBe(2);

    DB::table('api_idempotency_keys')->update(['expires_at' => now()->subMinute()]);
    Artisan::call('api:prune');
    expect(DB::table('api_idempotency_keys')->count())->toBe(0);
});

it('checks the key’s shape, and where it is optional only uses it when sent', function () {
    foreach (['short', 'has spaces in it', str_repeat('a', 81), 'bad/slash/inside'] as $bad) {
        ($this->create)(($this->body)(), ['Idempotency-Key' => $bad])->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_invalid');
    }

    $lead = makeLead($this->acme, $this->telecaller, $this->ahd);

    // a change does not need a key…
    apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['city' => 'Surat'], $this->token)->assertOk();
    // …but honours one: the repeat is the first answer
    $key = idem();
    $one = apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['city' => 'Rajkot'], $this->token, $key)->assertOk();
    Carbon::setTestNow(now()->addMinute());
    $two = apiCall('PATCH', "/api/v1/leads/{$lead->id}", ['city' => 'Rajkot'], $this->token, $key)->assertOk();

    expect($two->headers->get('Idempotent-Replayed'))->toBe('true')->and($two->json('data.updated_at'))->toBe($one->json('data.updated_at'));
});

it('does not remember reads', function () {
    apiCall('GET', '/api/v1/leads', [], $this->token, idem())->assertOk();

    expect(DB::table('api_idempotency_keys')->count())->toBe(0);
});

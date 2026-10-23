<?php

use App\Modules\Integrations\Enums\ConnectionStatus;
use App\Modules\Integrations\Jobs\PullProviderLeads;
use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Integrations\Models\LeadApiLog;
use App\Modules\Integrations\Services\ProviderSync;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

const SYNC_KEY = 'imkey-SYNC-SECRET-424242';

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 10:00:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->connection = makeConnection($this->acme, $this->admin, $this->ahd);
    app(SettingsService::class)->set('integrations.indiamart_api_key', SYNC_KEY, $this->acme->id);

    $this->pull = fn (?IntegrationConnection $c = null) => inCompany($this->acme, fn () => app(ProviderSync::class)->pull(($c ?? $this->connection)->fresh()));
    $this->state = fn () => $this->connection->fresh();
    // One fake for the whole test (a second Http::fake() does not replace the first: the first stub registered wins), answering
    // from whatever the test has put in $this->wire: a body and status, a message to fail the connection with, or a handler.
    $this->wire = new ArrayObject(['status' => 200, 'body' => indiaMartAnswer([]), 'throw' => null, 'handler' => null]);
    Http::fake(function (Request $request) {
        if ($this->wire['handler'] !== null) {
            return ($this->wire['handler'])($request);
        }
        if ($this->wire['throw'] !== null) {
            throw new ConnectionException($this->wire['throw']);
        }

        return Http::response($this->wire['body'], $this->wire['status']);
    });
    $this->answer = function (array $records, int $code = 200) {
        $this->wire['throw'] = null;
        $this->wire['handler'] = null;
        $this->wire['body'] = indiaMartAnswer($records, $code);
    };
    $this->queryOf = function (): array {
        parse_str((string) parse_url(Http::recorded()->last()[0]->url(), PHP_URL_QUERY), $query);

        return $query;
    };
});
afterEach(fn () => Carbon::setTestNow());

// ---- a pull -----------------------------------------------------------------------------------------------

it('pulls what is new, writes each delivery down, makes the leads, and moves the watermark to the end of the window', function () {
    ($this->answer)([indiaMartRecord(), indiaMartRecord(['UNIQUE_QUERY_ID' => '2026101200002', 'SENDER_MOBILE' => '9811100001'])]);

    $result = ($this->pull)();
    $state = ($this->state)();

    expect($result->succeeded())->toBeTrue()->and($result->fetched)->toBe(2)->and($result->created)->toBe(2)->and($result->alreadyKnown)->toBe(0)
        ->and(LeadApiLog::query()->where('status', 'created')->count())->toBe(2)->and(LeadApiLog::query()->first()->via)->toBe('pull')
        ->and($state->cursor_at->toDateTimeString())->toBe('2026-10-12 10:00:00')->and($state->last_success_at->toDateTimeString())->toBe('2026-10-12 10:00:00')
        ->and($state->consecutive_failures)->toBe(0)->and($state->last_error)->toBeNull()
        ->and($state->next_pull_at->toDateTimeString())->toBe('2026-10-12 10:15:00')       // the connection's own interval
        ->and($state->status())->toBe(ConnectionStatus::Connected);
});

it('asks for the first period as far back as the connection says, then from just before where it stopped', function () {
    ($this->answer)([]);
    ($this->pull)();

    $first = ($this->queryOf)();
    expect($first['start_time'])->toBe('11-Oct-202610:00:00')->and($first['end_time'])->toBe('12-Oct-202610:00:00')->and($first['glusr_crm_key'])->toBe(SYNC_KEY);

    Carbon::setTestNow('2026-10-12 10:20:00');
    ($this->answer)([]);
    ($this->pull)();

    $second = ($this->queryOf)();
    expect($second['start_time'])->toBe('12-Oct-202609:50:00')->and($second['end_time'])->toBe('12-Oct-202610:20:00');   // ten minutes of overlap
});

it('takes a delivery it has seen again — the overlap, or a push — as one, and only counts it', function () {
    ($this->answer)([indiaMartRecord()]);
    ($this->pull)();

    Carbon::setTestNow('2026-10-12 10:20:00');
    ($this->answer)([indiaMartRecord(), indiaMartRecord(['UNIQUE_QUERY_ID' => '2026101200009', 'SENDER_MOBILE' => '9811100009'])]);
    $result = ($this->pull)();

    expect($result->created)->toBe(1)->and($result->alreadyKnown)->toBe(1)->and(LeadApiLog::query()->count())->toBe(2)
        ->and(LeadApiLog::query()->where('provider_lead_id', '2026101200001')->value('received_count'))->toBe(2);
});

it('treats "no leads" as a success', function () {
    ($this->answer)([], 204);

    $result = ($this->pull)();

    expect($result->succeeded())->toBeTrue()->and($result->message)->toBe('Connected. No new leads in that period.')->and(($this->state)()->last_success_at)->not->toBeNull();
});

it('never calls IndiaMART twice within its five-minute gap, even for two workers at once', function () {
    ($this->answer)([]);
    ($this->pull)();

    Carbon::setTestNow('2026-10-12 10:03:00');
    $second = ($this->pull)();

    expect($second->outcome)->toBe('too_soon')->and($second->message)->toContain('one call every 5 minutes');
    Http::assertSentCount(1);

    Carbon::setTestNow('2026-10-12 10:06:00');
    expect(($this->pull)()->succeeded())->toBeTrue();
    Http::assertSentCount(2);
});

it('catches up on a long gap one provider-sized window at a time, coming back as soon as it is allowed', function () {
    ($this->answer)([]);
    $this->connection->forceFill(['cursor_at' => now()->subDays(20)])->saveQuietly();

    ($this->pull)();
    $state = ($this->state)();
    $query = ($this->queryOf)();

    expect($query['start_time'])->toBe('22-Sep-202609:50:00')->and($query['end_time'])->toBe('29-Sep-202609:50:00')     // seven days, the most IndiaMART serves
        ->and($state->cursor_at->toDateTimeString())->toBe('2026-09-29 09:50:00')
        ->and($state->next_pull_at->toDateTimeString())->toBe('2026-10-12 10:05:00');                                      // not the full 15 minutes
});

it('does not ask further back than the provider keeps', function () {
    ($this->answer)([]);
    $this->connection->forceFill(['cursor_at' => now()->subDays(900)])->saveQuietly();

    ($this->pull)();

    expect(($this->queryOf)()['start_time'])->toBe('17-Oct-202510:00:00');   // 360 days back
});

// ---- when the provider says no ------------------------------------------------------------------------------

it('stops asking when the key is refused, says why, and waits for a person', function () {
    ($this->answer)([], 401);

    $result = ($this->pull)();
    $state = ($this->state)();

    expect($result->outcome)->toBe('failed')->and($result->kind)->toBe('auth')
        ->and($state->failure_kind)->toBe('auth')->and($state->consecutive_failures)->toBe(1)->and($state->last_error)->toContain('did not accept the key')
        ->and($state->last_error)->not->toContain(SYNC_KEY)
        ->and($state->next_pull_at->toDateTimeString())->toBe('2026-10-12 11:00:00')->and($state->cursor_at)->toBeNull()
        ->and($state->status())->toBe(ConnectionStatus::Degraded);
});

it('backs off for the time IndiaMART asks when it is asked too often', function () {
    ($this->answer)([], 429);

    ($this->pull)();
    $state = ($this->state)();

    expect($state->failure_kind)->toBe('rate_limited')->and($state->next_pull_at->toDateTimeString())->toBe('2026-10-12 10:16:00');   // the 15-minute block, and a minute
});

it('backs off a little longer after each failure on the provider’s side, up to four hours', function () {
    ($this->answer)([], 500);

    $waits = [];
    foreach (range(1, 7) as $_) {
        Carbon::setTestNow(now()->addHours(5));      // always past the previous wait and the provider's gap
        ($this->pull)();
        $waits[] = (int) now()->diffInMinutes(($this->state)()->next_pull_at);
    }

    expect($waits)->toBe([15, 30, 60, 120, 240, 240, 240])->and(($this->state)()->consecutive_failures)->toBe(7);
});

it('recovers by itself: the next good call clears the failures', function () {
    ($this->answer)([], 500);
    ($this->pull)();
    expect(($this->state)()->status())->toBe(ConnectionStatus::Degraded);

    Carbon::setTestNow('2026-10-12 11:00:00');
    ($this->answer)([indiaMartRecord()]);
    ($this->pull)();
    $state = ($this->state)();

    expect($state->consecutive_failures)->toBe(0)->and($state->failure_kind)->toBeNull()->and($state->last_error)->toBeNull()->and($state->status())->toBe(ConnectionStatus::Connected);
});

it('records a network failure without ever writing the key — which is in the address — into the connection', function () {
    $this->wire['throw'] = 'cURL error 28: Operation timed out for https://mapi.indiamart.com/?glusr_crm_key='.SYNC_KEY;

    ($this->pull)();
    $state = ($this->state)();

    expect($state->failure_kind)->toBe('unavailable')->and($state->last_error)->toBe('IndiaMART could not be reached. It will be tried again.')
        ->and(json_encode($state->toArray()))->not->toContain(SYNC_KEY)
        ->and(ActivityLog::query()->where('subject_id', $state->id)->get()->every(fn ($e) => ! str_contains(json_encode($e->properties), SYNC_KEY)))->toBeTrue();
});

it('says what is missing when no key is saved, without calling the provider', function () {
    app(SettingsService::class)->set('integrations.indiamart_api_key', null, $this->acme->id);
    $result = ($this->pull)();

    expect($result->kind)->toBe('auth')->and($result->message)->toContain('No API key is saved');
    Http::assertNothingSent();
});

it('leaves the watermark where it was when a pull fails, so the same period is asked for again', function () {
    ($this->answer)([indiaMartRecord()]);
    ($this->pull)();
    $before = ($this->state)()->cursor_at->toDateTimeString();

    Carbon::setTestNow('2026-10-12 10:30:00');
    ($this->answer)([], 500);
    ($this->pull)();

    expect(($this->state)()->cursor_at->toDateTimeString())->toBe($before);
});

it('does nothing for a connection that is off, only receives pushes, or belongs to a provider with nothing to pull', function () {
    $this->connection->forceFill(['enabled' => false])->saveQuietly();
    expect(($this->pull)()->outcome)->toBe('skipped');

    $this->connection->forceFill(['enabled' => true, 'mode' => 'webhook'])->saveQuietly();
    expect(($this->pull)()->outcome)->toBe('skipped');

    $justDial = makeConnection($this->acme, $this->admin, $this->ahd, 'justdial', ['mode' => 'both']);
    expect(($this->pull)($justDial)->outcome)->toBe('skipped');

    Http::assertNothingSent();
});

it('can test a connection without saving anything or moving the watermark', function () {
    ($this->answer)([indiaMartRecord(), indiaMartRecord(['UNIQUE_QUERY_ID' => '2'])]);

    $result = inCompany($this->acme, fn () => app(ProviderSync::class)->test($this->connection->fresh()));

    expect($result->succeeded())->toBeTrue()->and($result->fetched)->toBe(2)->and($result->message)->toContain('2 leads came back')
        ->and(LeadApiLog::query()->count())->toBe(0)->and(($this->state)()->cursor_at)->toBeNull()->and(($this->state)()->last_success_at)->toBeNull();

    // it is a real call, so the provider's gap holds
    expect(inCompany($this->acme, fn () => app(ProviderSync::class)->test($this->connection->fresh()))->outcome)->toBe('too_soon');
});

it('reports a test that fails, and explains that Just Dial has nothing to call', function () {
    ($this->answer)([], 401);

    $failed = inCompany($this->acme, fn () => app(ProviderSync::class)->test($this->connection->fresh()));
    expect($failed->outcome)->toBe('failed')->and($failed->kind)->toBe('auth')->and(($this->state)()->consecutive_failures)->toBe(0);    // a test is not a failed pull

    $justDial = makeConnection($this->acme, $this->admin, $this->ahd, 'justdial');
    $note = inCompany($this->acme, fn () => app(ProviderSync::class)->test($justDial->fresh()));
    expect($note->outcome)->toBe('skipped')->and($note->message)->toContain('sends its leads to us');
});

// ---- the schedule -------------------------------------------------------------------------------------------

it('queues a pull only for connections that are on, pull, and due', function () {
    Bus::fake();

    $due = $this->connection;
    $notDue = makeConnection($this->globex, makeUser(['Company Admin'], [$this->globex]), makeLocation($this->globex, 'NYC'), 'indiamart', ['next_pull_at' => now()->addMinutes(10)]);
    $off = makeConnection($this->acme, $this->admin, $this->ahd, 'justdial', ['enabled' => false]);
    $pushOnly = makeConnection(makeCompany('THIRD'), $this->admin, $this->ahd, 'justdial');

    Artisan::call('integrations:pull');

    Bus::assertDispatched(PullProviderLeads::class, 1);
    Bus::assertDispatched(PullProviderLeads::class, fn ($job) => $job->connectionId === $due->id && $job->companyId === $this->acme->id);
    Bus::assertNotDispatched(PullProviderLeads::class, fn ($job) => in_array($job->connectionId, [$notDue->id, $off->id, $pushOnly->id], true));
});

it('runs each pull inside its own company', function () {
    $bUser = makeUser(['Company Admin'], [$this->globex]);
    $branch = makeLocation($this->globex, 'NYC');
    $theirs = makeConnection($this->globex, $bUser, $branch);
    app(SettingsService::class)->set('integrations.indiamart_api_key', 'globex-key-777777', $this->globex->id);

    $this->wire['handler'] = function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(indiaMartAnswer([indiaMartRecord(['UNIQUE_QUERY_ID' => $query['glusr_crm_key'] === 'globex-key-777777' ? 'GLOBEX-1' : 'ACME-1', 'SENDER_MOBILE' => $query['glusr_crm_key'] === 'globex-key-777777' ? '9811100777' : '9811100888'])]));
    };

    Artisan::call('integrations:pull');   // the queue here is synchronous: both pulls have run

    $byCompany = LeadApiLog::withoutGlobalScopes(Tenancy::SCOPES)->orderBy('id')->get()->map(fn ($l) => [$l->company_id, $l->provider_lead_id])->all();

    expect($byCompany)->toBe([[$this->acme->id, 'ACME-1'], [$this->globex->id, 'GLOBEX-1']])
        ->and($theirs->fresh()->last_success_at)->not->toBeNull();
});

it('keeps a pull from running twice at once for one connection', function () {
    $job = new PullProviderLeads($this->acme->id, $this->connection->id);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)->and($job->uniqueId())->toBe((string) $this->connection->id)->and($job->tries)->toBe(1);
    Queue::fake();
    PullProviderLeads::dispatch($this->acme->id, $this->connection->id);
    Queue::assertPushed(PullProviderLeads::class);
});

// ---- status ---------------------------------------------------------------------------------------------

it('works out where a connection stands', function () {
    $c = new IntegrationConnection;
    $c->forceFill(['provider' => 'indiamart', 'mode' => 'pull', 'enabled' => false, 'pull_interval_minutes' => 15, 'consecutive_failures' => 0]);
    expect($c->status())->toBe(ConnectionStatus::Disabled);

    $c->enabled = true;
    expect($c->status())->toBe(ConnectionStatus::NeedsSetup);

    $c->forceFill(['default_location_id' => 1, 'actor_user_id' => 1]);
    expect($c->status())->toBe(ConnectionStatus::Configured);

    $c->last_success_at = now()->subMinutes(10);
    expect($c->status())->toBe(ConnectionStatus::Connected);

    $c->last_success_at = now()->subMinutes(51);       // three intervals and five minutes
    expect($c->status())->toBe(ConnectionStatus::Degraded);

    $c->consecutive_failures = 1;
    $c->last_success_at = now()->subMinutes(1);
    expect($c->status())->toBe(ConnectionStatus::Degraded);

    // a push-only connection is alive as long as it is heard from, however long ago
    $push = new IntegrationConnection;
    $push->forceFill(['provider' => 'justdial', 'mode' => 'webhook', 'enabled' => true, 'default_location_id' => 1, 'actor_user_id' => 1, 'consecutive_failures' => 0, 'last_received_at' => now()->subDays(3)]);
    expect($push->status())->toBe(ConnectionStatus::Connected);
});

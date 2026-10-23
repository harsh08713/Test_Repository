<?php

use App\Modules\CRM\Models\Lead;
use App\Modules\Integrations\Enums\ConnectionStatus;
use App\Modules\Integrations\Http\Controllers\WebhookController;
use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Integrations\Models\LeadApiLog;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

const HOOK_SECRET = 'whsec-SIGNING-secret-31415926';

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 10:00:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->nyc = makeLocation($this->globex, 'NYC');
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->other = makeUser(['Company Admin'], [$this->globex]);

    $this->im = makeConnection($this->acme, $this->admin, $this->ahd, 'indiamart', ['mode' => 'both']);
    $this->jd = makeConnection($this->acme, $this->admin, $this->ahd, 'justdial');
    $this->theirs = makeConnection($this->globex, $this->other, $this->nyc, 'indiamart', ['mode' => 'both']);

    $this->url = fn ($connection, ?string $provider = null) => '/api/webhooks/'.($provider ?? $connection->provider).'/'.$connection->public_key;
    $this->post = fn ($connection, array|string $body, array $headers = [], ?string $provider = null) => $this->call(
        'POST', ($this->url)($connection, $provider), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', ...collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all()],
        is_string($body) ? $body : json_encode($body),
    );
    $this->sign = function (string $raw, ?int $timestamp = null, string $secret = HOOK_SECRET): array {
        $timestamp ??= time();

        return ['X-FormEzy-Timestamp' => (string) $timestamp, 'X-FormEzy-Signature' => 'sha256='.hash_hmac('sha256', "{$timestamp}.{$raw}", $secret)];
    };
});

afterEach(fn () => Carbon::setTestNow());

// ---- taking a lead in -----------------------------------------------------------------------------------

it('accepts a push, writes it down, makes the lead and answers 202 at once', function () {
    $response = ($this->post)($this->im, ['CODE' => 200, 'STATUS' => 'SUCCESS', 'RESPONSE' => indiaMartRecord()])->assertStatus(202);

    $log = LeadApiLog::query()->sole();

    expect($response->json('data'))->toBe(['status' => 'accepted', 'received' => 1, 'new' => 1, 'already_known' => 0])
        ->and($response->json('meta.request_id'))->not->toBeEmpty()
        ->and($log->via)->toBe('webhook')->and($log->provider)->toBe('indiamart')->and($log->status->value)->toBe('created')
        ->and(Lead::query()->count())->toBe(1)->and(Lead::query()->sole()->entry_channel->value)->toBe('indiamart')
        ->and($this->im->fresh()->last_received_at->toDateTimeString())->toBe('2026-10-12 10:00:00')
        ->and($this->im->fresh()->status())->toBe(ConnectionStatus::Connected);
});

it('takes the shapes each provider pushes — one lead, a wrapped lead, a list', function () {
    ($this->post)($this->im, indiaMartRecord(['UNIQUE_QUERY_ID' => 'A1', 'SENDER_MOBILE' => '9811100001']))->assertStatus(202);                       // bare
    ($this->post)($this->im, ['RESPONSE' => [indiaMartRecord(['UNIQUE_QUERY_ID' => 'A2', 'SENDER_MOBILE' => '9811100002']), indiaMartRecord(['UNIQUE_QUERY_ID' => 'A3', 'SENDER_MOBILE' => '9811100003'])]])->assertStatus(202)->assertJsonPath('data.received', 2);
    ($this->post)($this->jd, justDialRecord(['leadid' => 'J1', 'mobile' => '9811100011']))->assertStatus(202);
    ($this->post)($this->jd, ['leads' => [justDialRecord(['leadid' => 'J2', 'mobile' => '9811100012'])]])->assertStatus(202);

    expect(LeadApiLog::query()->orderBy('id')->pluck('provider_lead_id')->all())->toBe(['A1', 'A2', 'A3', 'J1', 'J2'])
        ->and(Lead::query()->count())->toBe(5);
});

it('takes a Just Dial push sent as form fields', function () {
    $this->call('POST', ($this->url)($this->jd), justDialRecord(['leadid' => 'FORM-1']), [], [], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(202);

    expect(LeadApiLog::query()->sole()->provider_lead_id)->toBe('FORM-1')->and(Lead::query()->count())->toBe(1);
});

it('is the same lead when it is sent again — it is counted, not made twice, and the provider is told it is known', function () {
    ($this->post)($this->im, indiaMartRecord())->assertStatus(202)->assertJsonPath('data.new', 1);
    $again = ($this->post)($this->im, indiaMartRecord())->assertStatus(202);

    expect($again->json('data'))->toBe(['status' => 'accepted', 'received' => 1, 'new' => 0, 'already_known' => 1])
        ->and(LeadApiLog::query()->sole()->received_count)->toBe(2)->and(Lead::query()->count())->toBe(1);
});

it('answers 202 even when the lead cannot be made — the provider must not retry what only a person can fix — and keeps it failed', function () {
    $this->im->forceFill(['actor_user_id' => null])->saveQuietly();

    ($this->post)($this->im, indiaMartRecord())->assertStatus(202)->assertJsonPath('data.new', 1);

    $log = LeadApiLog::query()->sole();
    expect($log->status->value)->toBe('failed')->and($log->error)->toContain('no branch or no person')->and(Lead::query()->count())->toBe(0);
});

// ---- the address ----------------------------------------------------------------------------------------

it('says "not found" — the same to everyone — for an address that is wrong, switched off or not for pushes', function () {
    $this->jd->forceFill(['enabled' => false])->saveQuietly();
    $this->im->forceFill(['mode' => 'pull'])->saveQuietly();
    $im = $this->im;
    $key = $this->theirs->public_key;

    $bodies = collect([
        ($this->call('POST', "/api/webhooks/indiamart/{$im->public_key}", [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(indiaMartRecord()))),     // pull-only
        ($this->call('POST', "/api/webhooks/justdial/{$this->jd->public_key}", [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(justDialRecord()))),   // switched off
        ($this->call('POST', '/api/webhooks/indiamart/'.str_repeat('a', 40), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')),                              // never existed
        ($this->call('POST', "/api/webhooks/indiamart/{$im->public_key}x", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')),                                 // wrong length
        ($this->call('POST', "/api/webhooks/justdial/{$key}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')),                                              // another company's key, wrong provider
        ($this->call('POST', '/api/webhooks/zomato/'.str_repeat('a', 40), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')),                                 // unknown provider
    ])->map(fn ($r) => [$r->status(), $r->json('error.code'), $r->json('error.message')]);

    expect($bodies->unique()->values()->all())->toBe([[404, 'not_found', 'There is nothing here, or you are not allowed to see it.']])
        ->and(LeadApiLog::query()->count())->toBe(0);
});

it('files a lead under the company whose address it came to, and no other', function () {
    ($this->post)($this->theirs, indiaMartRecord(['UNIQUE_QUERY_ID' => 'G1']))->assertStatus(202);
    ($this->post)($this->im, indiaMartRecord(['UNIQUE_QUERY_ID' => 'G1']))->assertStatus(202);       // the same provider id, another company: another delivery

    $logs = LeadApiLog::withoutGlobalScopes(Tenancy::SCOPES)->orderBy('id')->get();

    expect($logs->pluck('company_id')->map(fn ($id) => (int) $id)->all())->toBe([$this->globex->id, $this->acme->id])
        ->and(Lead::withoutGlobalScopes(Tenancy::SCOPES)->orderBy('id')->pluck('location_id')->map(fn ($id) => (int) $id)->all())->toBe([$this->nyc->id, $this->ahd->id]);
});

it('lets the address be replaced: the old one stops at once', function () {
    $old = ($this->url)($this->im);
    $this->im->forceFill(['public_key' => IntegrationConnection::newPublicKey()])->saveQuietly();

    $this->call('POST', $old, [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(indiaMartRecord()))->assertNotFound();
    ($this->post)($this->im->fresh(), indiaMartRecord())->assertStatus(202);
});

// ---- the body -------------------------------------------------------------------------------------------

it('refuses what is not a lead, what is not JSON, and what is too much', function () {
    ($this->post)($this->im, '{"CODE": 200, "RESPONSE": ')->assertStatus(400)->assertJsonPath('error.code', 'bad_request');
    ($this->post)($this->im, ['hello' => 'world'])->assertStatus(400)->assertJsonPath('error.message', 'No lead was found in that request.');
    ($this->post)($this->jd, [])->assertStatus(400);
    $this->call('POST', ($this->url)($this->jd), [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'not json at all')->assertStatus(400);

    $big = str_repeat('x', WebhookController::MAX_BYTES + 1);
    ($this->post)($this->im, indiaMartRecord(['QUERY_MESSAGE' => $big]))->assertStatus(413)->assertJsonPath('error.code', 'payload_too_large');

    $many = array_map(fn ($i) => indiaMartRecord(['UNIQUE_QUERY_ID' => "M{$i}", 'SENDER_MOBILE' => '98111'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'QUERY_MESSAGE' => 'x']), range(1, WebhookController::MAX_RECORDS + 1));
    ($this->post)($this->im, ['RESPONSE' => array_map(fn ($r) => ['UNIQUE_QUERY_ID' => $r['UNIQUE_QUERY_ID']], $many)])->assertStatus(413);

    expect(LeadApiLog::query()->count())->toBe(0);
});

// ---- signatures and replays ------------------------------------------------------------------------------

it('needs a valid signature once a signing secret is set, and says which part is wrong', function () {
    app(SettingsService::class)->set('integrations.indiamart_webhook_secret', HOOK_SECRET, $this->acme->id);
    $raw = json_encode(indiaMartRecord());

    ($this->post)($this->im, $raw)->assertStatus(401)->assertJsonPath('error.code', 'stale_timestamp');                                              // no headers at all
    ($this->post)($this->im, $raw, ['X-FormEzy-Timestamp' => (string) time(), 'X-FormEzy-Signature' => 'sha256=deadbeef'])->assertStatus(401)->assertJsonPath('error.code', 'invalid_signature');
    ($this->post)($this->im, $raw, ($this->sign)($raw, secret: 'a-different-secret'))->assertStatus(401)->assertJsonPath('error.code', 'invalid_signature');
    ($this->post)($this->im, $raw, ($this->sign)($raw, time() - 600))->assertStatus(401)->assertJsonPath('error.code', 'stale_timestamp');              // a correct signature, too old
    ($this->post)($this->im, $raw, ($this->sign)($raw, time() + 600))->assertStatus(401)->assertJsonPath('error.code', 'stale_timestamp');              // …or from the future
    ($this->post)($this->im, $raw.' ', ($this->sign)($raw))->assertStatus(401)->assertJsonPath('error.code', 'invalid_signature');                    // the body must be the bytes that were signed

    expect(LeadApiLog::query()->count())->toBe(0);

    ($this->post)($this->im, $raw, ($this->sign)($raw))->assertStatus(202)->assertJsonPath('data.new', 1);
    expect(Lead::query()->count())->toBe(1);
});

it('does not treat a signature for one connection as good for another', function () {
    app(SettingsService::class)->set('integrations.indiamart_webhook_secret', HOOK_SECRET, $this->acme->id);
    app(SettingsService::class)->set('integrations.indiamart_webhook_secret', 'globex-own-secret-1234', $this->globex->id);
    $raw = json_encode(indiaMartRecord());

    ($this->post)($this->theirs, $raw, ($this->sign)($raw))->assertStatus(401);                                                   // signed with acme's secret
    ($this->post)($this->theirs, $raw, ($this->sign)($raw, secret: 'globex-own-secret-1234'))->assertStatus(202);
});

it('answers a signed request that is replayed as already accepted, and does nothing with it', function () {
    app(SettingsService::class)->set('integrations.indiamart_webhook_secret', HOOK_SECRET, $this->acme->id);
    $raw = json_encode(indiaMartRecord());
    $headers = ($this->sign)($raw);

    ($this->post)($this->im, $raw, $headers)->assertStatus(202)->assertJsonPath('data.new', 1);
    $replay = ($this->post)($this->im, $raw, $headers)->assertStatus(202);

    expect($replay->json('data'))->toBe(['status' => 'accepted', 'received' => 0, 'new' => 0, 'already_known' => 0, 'replayed' => true])
        ->and(LeadApiLog::query()->sole()->received_count)->toBe(1)->and(Lead::query()->count())->toBe(1);
});

it('accepts an unsigned push when no secret is set — the address alone is then the secret', function () {
    expect(app(SettingsService::class)->isSet('integrations.justdial_webhook_secret', $this->acme->id))->toBeFalse();

    ($this->post)($this->jd, justDialRecord())->assertStatus(202);
});

// ---- limits and secrecy ------------------------------------------------------------------------------------

it('throttles a flood to one address', function () {
    foreach (range(1, 120) as $i) {
        ($this->post)($this->jd, justDialRecord(['leadid' => "T{$i}", 'mobile' => '98'.str_pad((string) $i, 8, '0', STR_PAD_LEFT)]))->assertStatus(202);
    }

    $over = ($this->post)($this->jd, justDialRecord(['leadid' => 'T121']))->assertStatus(429);

    expect($over->json('error.code'))->toBe('too_many_requests')->and((int) $over->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('never stores a secret that arrives in a payload, and never logs the address', function () {
    $path = storage_path('logs/webhook-test-'.bin2hex(random_bytes(4)).'.log');
    config(['logging.channels.api' => ['driver' => 'single', 'path' => $path, 'level' => 'info']]);
    Log::forgetChannel('api');

    app(SettingsService::class)->set('integrations.indiamart_api_key', 'imkey-in-settings-8888', $this->acme->id);
    $record = indiaMartRecord(['glusr_crm_key' => 'imkey-in-settings-8888', 'QUERY_MESSAGE' => 'my address is '.$this->im->public_key]);

    ($this->post)($this->im, $record)->assertStatus(202);

    $stored = json_encode(LeadApiLog::query()->sole()->payload);
    $log = (string) file_get_contents($path);
    File::delete($path);

    expect($stored)->not->toContain('imkey-in-settings-8888')->not->toContain($this->im->public_key)
        ->and($log)->toContain('api/webhooks/{provider}/{key}')->not->toContain($this->im->public_key)->not->toContain('9876543210');
});

it('has no session, cookie or CSRF requirement — a provider is a machine', function () {
    ($this->post)($this->jd, justDialRecord())->assertStatus(202);

    expect(collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => $r->getName() === 'webhooks.receive')->gatherMiddleware())->not->toContain('web');
});

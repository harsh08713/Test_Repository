<?php

use App\Models\User;
use App\Modules\Api\Models\ApiToken;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

const API_PASSWORD = 'Sturdy-Pass-2026';

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');

    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd], ['email' => 'ravi@example.test', 'password' => API_PASSWORD]);

    $this->login = fn (array $over = [], ?string $as = null) => apiCall('POST', '/api/v1/auth/login', [
        'email' => $as ?? 'ravi@example.test', 'password' => API_PASSWORD, 'device_name' => 'Ravi’s Pixel', ...$over,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

// ---- signing in ------------------------------------------------------------------------------------------

it('signs a device in and hands the token back once, storing only its hash', function () {
    $response = ($this->login)(['device_id' => 'pixel-7-ravi', 'app_version' => '1.4.2'])->assertCreated();

    $token = $response->json('data.token');

    expect($response->json('data.token_type'))->toBe('Bearer')
        ->and($response->json('data.scopes'))->toBe(['read', 'write'])
        ->and($response->json('data.expires_at'))->toBe('2026-10-26T09:30:00+05:30')
        ->and($response->json('data.device.name'))->toBe('Ravi’s Pixel')
        ->and($response->json('data.user.email'))->toBe('ravi@example.test')
        ->and($response->json('data'))->not->toHaveKey('password');

    $row = DB::table('personal_access_tokens')->sole();
    [$id, $secret] = explode('|', $token, 2);

    expect((int) $id)->toBe((int) $row->id)
        ->and($row->token)->toBe(hash('sha256', $secret))->and($row->token)->not->toContain($secret)
        ->and($row->tokenable_type)->toBe('user')
        ->and($row->device_id)->toBe('pixel-7-ravi')->and($row->app_version)->toBe('1.4.2')
        ->and($row->ip_address)->toBe('127.0.0.1');

    // …and the token works
    apiCall('GET', '/api/v1/me', as: $token)->assertOk()->assertJsonPath('data.user.email', 'ravi@example.test');
});

it('answers every API call in one envelope with a correlation id', function () {
    $response = apiCall('GET', '/api/v1/health');

    $response->assertOk()->assertJsonStructure(['data', 'meta' => ['request_id']]);
    expect($response->headers->get('X-Request-Id'))->toBe($response->json('meta.request_id'))->and($response->headers->get('X-Request-Id'))->toHaveLength(26);

    // a sane id sent by the app is kept, so it can follow the request through its own logs
    $kept = apiCall('GET', '/api/v1/health', headers: ['X-Request-Id' => 'app-9f3c-0001']);
    expect($kept->headers->get('X-Request-Id'))->toBe('app-9f3c-0001')->and($kept->json('meta.request_id'))->toBe('app-9f3c-0001');

    // …a silly one is replaced
    $replaced = apiCall('GET', '/api/v1/health', headers: ['X-Request-Id' => "x\ny"]);
    expect($replaced->headers->get('X-Request-Id'))->not->toBe("x\ny")->and($replaced->headers->get('X-Request-Id'))->toHaveLength(26);
});

it('does not say whether the address or the password was wrong', function () {
    $unknown = ($this->login)(as: 'nobody@example.test')->assertUnauthorized();
    $wrong = ($this->login)(['password' => 'Not-The-Password-1'])->assertUnauthorized();

    expect($unknown->json('error.code'))->toBe('invalid_credentials')->and($wrong->json('error.code'))->toBe('invalid_credentials')
        ->and($unknown->json('error.message'))->toBe($wrong->json('error.message'))
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('refuses a deactivated person the same way', function () {
    $this->bde->forceFill(['is_active' => false])->saveQuietly();

    ($this->login)()->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credentials');
});

it('refuses someone who may not use the API — but only after the right password', function () {
    makeUser(['Operations User'], [$this->acme], null, ['email' => 'ops@example.test', 'password' => API_PASSWORD]);

    ($this->login)(as: 'ops@example.test')->assertForbidden()->assertJsonPath('error.code', 'api_access_denied');
    ($this->login)(['password' => 'Not-The-Password-1'], 'ops@example.test')->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credentials');
});

it('refuses someone who belongs to no company', function () {
    $role = Role::findByName('BDE / Field Salesperson');
    $loner = User::factory()->create(['email' => 'loner@example.test', 'password' => API_PASSWORD, 'is_active' => true]);
    $loner->assignRole($role);

    ($this->login)(as: 'loner@example.test')->assertForbidden()->assertJsonPath('error.code', 'no_company');
});

it('throttles sign-in attempts per address and place, and keeps the answer in the envelope', function () {
    foreach (range(1, 5) as $_) {
        ($this->login)(['password' => 'Not-The-Password-1'])->assertUnauthorized();
    }

    $sixth = ($this->login)()->assertStatus(429);   // even the right password: the attempts are what is counted

    expect($sixth->json('error.code'))->toBe('too_many_requests')->and((int) $sixth->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($sixth->json('meta.request_id'))->not->toBeEmpty();

    // somebody else is not locked out by it
    makeUser(['Tele-caller'], [$this->acme], null, ['email' => 'asha@example.test', 'password' => API_PASSWORD]);
    ($this->login)(as: 'asha@example.test')->assertCreated();
});

it('checks the shape of a sign-in request and says which fields are wrong', function () {
    $response = apiCall('POST', '/api/v1/auth/login', ['email' => 'not-an-address', 'device_name' => 'x', 'scopes' => ['admin']])->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')
        ->and(array_keys($response->json('error.fields')))->toContain('email', 'password', 'device_name', 'scopes.0');
});

// ---- devices ---------------------------------------------------------------------------------------------

it('lets a device that signs in again replace its old token', function () {
    ($this->login)(['device_id' => 'pixel-7-ravi'])->assertCreated();
    ($this->login)(['device_id' => 'pixel-7-ravi'])->assertCreated();
    expect(DB::table('personal_access_tokens')->count())->toBe(1);

    ($this->login)(['device_id' => 'tablet-office-1'])->assertCreated();
    expect(DB::table('personal_access_tokens')->count())->toBe(2);
});

it('signs out the least recently used device beyond the limit', function () {
    config(['formezy.api.max_devices' => 2]);

    $first = ($this->login)(['device_id' => 'device-one-aaaa'])->json('data.token');
    Carbon::setTestNow(now()->addMinute());
    $second = ($this->login)(['device_id' => 'device-two-aaaa'])->json('data.token');
    Carbon::setTestNow(now()->addMinute());
    $third = ($this->login)(['device_id' => 'device-three-aa'])->json('data.token');

    expect(DB::table('personal_access_tokens')->count())->toBe(2);

    apiCall('GET', '/api/v1/me', as: $first)->assertUnauthorized();
    apiCall('GET', '/api/v1/me', as: $second)->assertOk();
    apiCall('GET', '/api/v1/me', as: $third)->assertOk();
});

it('lists a person’s own devices, marks the current one, and never shows a token', function () {
    $mine = ($this->login)(['device_id' => 'pixel-7-ravi'])->json('data.token');
    ($this->login)(['device_id' => 'tablet-office-1']);

    $other = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    apiToken($other);

    $list = apiCall('GET', '/api/v1/devices', as: $mine)->assertOk();

    expect($list->json('data'))->toHaveCount(2)
        ->and(collect($list->json('data'))->where('current', true))->toHaveCount(1)
        ->and(collect($list->json('data'))->pluck('device_id')->sort()->values()->all())->toBe(['pixel-7-ravi', 'tablet-office-1'])
        ->and($list->getContent())->not->toContain('"token"')->not->toContain(explode('|', $mine)[1]);
});

it('lets a person sign out one of their devices, and nobody else’s', function () {
    $mine = ($this->login)(['device_id' => 'pixel-7-ravi'])->json('data.token');
    ($this->login)(['device_id' => 'tablet-office-1']);
    $tablet = DB::table('personal_access_tokens')->where('device_id', 'tablet-office-1')->value('id');

    $other = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    apiToken($other);
    $theirs = DB::table('personal_access_tokens')->where('tokenable_id', $other->id)->value('id');

    apiCall('DELETE', "/api/v1/devices/{$theirs}", as: $mine)->assertNotFound();
    expect(DB::table('personal_access_tokens')->where('id', $theirs)->exists())->toBeTrue();

    apiCall('DELETE', "/api/v1/devices/{$tablet}", as: $mine)->assertOk()->assertJsonPath('data.revoked', true);
    expect(DB::table('personal_access_tokens')->where('id', $tablet)->exists())->toBeFalse();
});

it('audits issuing and revoking a token — never the token itself', function () {
    $token = ($this->login)(['device_id' => 'pixel-7-ravi'])->json('data.token');
    apiCall('POST', '/api/v1/auth/logout', as: $token)->assertOk();

    $entries = ActivityLog::query()->where('log_name', 'api')->orderBy('id')->get();

    expect($entries->pluck('event')->all())->toBe(['token_issued', 'token_revoked'])
        ->and($entries->every(fn ($e) => (int) $e->company_id === $this->acme->id && (int) $e->causer_id === $this->bde->id))->toBeTrue()
        ->and($entries->first()->properties['device'])->toBe('Ravi’s Pixel')
        ->and(json_encode($entries->pluck('properties')))->not->toContain(explode('|', $token)[1]);
});

// ---- what happens to a token ----------------------------------------------------------------------------

it('answers 401 without a token, with an expired one, and with one that was revoked', function () {
    apiCall('GET', '/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated')->assertHeader('WWW-Authenticate', 'Bearer');
    apiCall('GET', '/api/v1/me', as: 'garbage')->assertUnauthorized();

    $expired = $this->bde->createToken('Old phone', ['read', 'write'], now()->subMinute())->plainTextToken;
    apiCall('GET', '/api/v1/me', as: $expired)->assertUnauthorized();

    $live = apiToken($this->bde);
    apiCall('GET', '/api/v1/me', as: $live)->assertOk();
    DB::table('personal_access_tokens')->delete();
    apiCall('GET', '/api/v1/me', as: $live)->assertUnauthorized();
});

it('does not let a browser session stand in for a device token', function () {
    $this->actingAs($this->bde)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
});

it('signs this device out on logout and leaves the others', function () {
    $one = ($this->login)(['device_id' => 'pixel-7-ravi'])->json('data.token');
    $two = ($this->login)(['device_id' => 'tablet-office-1'])->json('data.token');

    apiCall('POST', '/api/v1/auth/logout', as: $one)->assertOk()->assertJsonPath('data.signed_out', true);

    apiCall('GET', '/api/v1/me', as: $one)->assertUnauthorized();
    apiCall('GET', '/api/v1/me', as: $two)->assertOk();
});

it('swaps a token for a fresh one on refresh and stops the old one working', function () {
    $old = ($this->login)(['device_id' => 'pixel-7-ravi', 'scopes' => ['read']])->json('data.token');
    Carbon::setTestNow(now()->addDays(3));

    $fresh = apiCall('POST', '/api/v1/auth/refresh', as: $old)->assertCreated();

    expect($fresh->json('data.token'))->not->toBe($old)
        ->and($fresh->json('data.scopes'))->toBe(['read'])
        ->and($fresh->json('data.expires_at'))->toBe('2026-10-29T09:30:00+05:30')
        ->and($fresh->json('data.device.device_id'))->toBe('pixel-7-ravi')
        ->and(DB::table('personal_access_tokens')->count())->toBe(1);

    apiCall('GET', '/api/v1/me', as: $old)->assertUnauthorized();
    apiCall('GET', '/api/v1/me', as: $fresh->json('data.token'))->assertOk();
});

it('gives a read-only device its reads, and refuses every change — except signing out and renewing', function () {
    $token = ($this->login)(['scopes' => ['read']])->json('data.token');

    apiCall('GET', '/api/v1/me', as: $token)->assertOk();
    apiCall('POST', '/api/v1/attendance', ['position' => ['latitude' => HQ_LAT, 'longitude' => HQ_LNG, 'accuracy_m' => 10]], $token, idem())
        ->assertForbidden()->assertJsonPath('error.code', 'scope_missing');

    $renewed = apiCall('POST', '/api/v1/auth/refresh', as: $token)->assertCreated()->json('data.token');
    apiCall('POST', '/api/v1/auth/logout', as: $renewed)->assertOk();
});

it('removes every token when the person is switched off or their password changes', function () {
    apiToken($this->bde);
    apiToken($this->bde);
    expect(DB::table('personal_access_tokens')->count())->toBe(2);

    $this->bde->refresh()->forceFill(['password' => 'Another-Pass-2027'])->save();
    expect(DB::table('personal_access_tokens')->count())->toBe(0);

    apiToken($this->bde);
    $this->bde->refresh()->forceFill(['is_active' => false])->save();
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('stops a token working the moment the account is inactive or loses api.access, without waiting for it to expire', function () {
    $token = apiToken($this->bde);
    apiCall('GET', '/api/v1/me', as: $token)->assertOk();

    // switched off by a path that raised no model event
    DB::table('users')->where('id', $this->bde->id)->update(['is_active' => false]);
    apiCall('GET', '/api/v1/me', as: $token)->assertUnauthorized()->assertJsonPath('error.code', 'account_inactive');
    expect(DB::table('personal_access_tokens')->count())->toBe(0);

    DB::table('users')->where('id', $this->bde->id)->update(['is_active' => true]);
    $token = apiToken($this->bde);
    Role::findByName('BDE / Field Salesperson')->revokePermissionTo('api.access');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    apiCall('GET', '/api/v1/me', as: $token)->assertForbidden()->assertJsonPath('error.code', 'api_access_denied');
});

// ---- the company a request works in ----------------------------------------------------------------------

it('works in the person’s own company, or the one they name if they belong to it — and refuses one they do not, rather than swapping it', function () {
    $second = makeCompany('SECOND');
    seedDefaults($second);
    $both = makeUser(['Sales Manager'], [$this->acme, $second], null, ['default_company_id' => $this->acme->id]);

    apiCall('GET', '/api/v1/me', as: $both)->assertOk()->assertJsonPath('data.company.code', 'ACME');
    apiCall('GET', '/api/v1/me', as: $both, headers: ['X-Company-Id' => (string) $second->id])->assertOk()->assertJsonPath('data.company.code', 'SECOND');
    expect(apiCall('GET', '/api/v1/me', as: $both)->json('data.companies'))->toHaveCount(2);

    // a company they are not in is an error — never quietly the default one
    apiCall('GET', '/api/v1/me', as: $this->bde, headers: ['X-Company-Id' => (string) $this->globex->id])->assertForbidden()->assertJsonPath('error.code', 'company_not_accessible');
    apiCall('GET', '/api/v1/me', as: $this->bde, headers: ['X-Company-Id' => 'abc'])->assertForbidden()->assertJsonPath('error.code', 'company_not_accessible');
});

// ---- errors ----------------------------------------------------------------------------------------------

it('answers an unknown address, a wrong method and a malformed body in the envelope', function () {
    $missing = apiCall('GET', '/api/v1/nothing-here')->assertNotFound();
    expect($missing->json('error.code'))->toBe('not_found')->and($missing->headers->get('X-Request-Id'))->not->toBeEmpty();

    apiCall('GET', '/api/v1/auth/login')->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');

    $this->call('POST', '/api/v1/auth/login', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{"email": ')
        ->assertStatus(400)->assertJsonPath('error.code', 'bad_request');
});

it('says nothing about the server on the health check', function () {
    $response = apiCall('GET', '/api/v1/health')->assertOk();

    expect(array_keys($response->json('data')))->toBe(['status', 'api', 'time', 'checks'])
        ->and($response->json('data.status'))->toBe('ok')->and($response->json('data.checks'))->toBe(['database' => 'ok'])
        ->and(strtolower($response->getContent()))->not->toContain('laravel')->not->toContain('php')->not->toContain('mysql')->not->toContain('debug');
});

it('keeps an unexpected failure to a generic message and the request id', function () {
    // make something fail inside a real route, with a message that must never reach the client
    $this->app->bind(SettingsService::class, fn () => throw new RuntimeException('secret detail: db.internal password=hunter2'));

    $response = apiCall('GET', '/api/v1/me', as: $this->bde);

    expect($response->status())->toBe(500)->and($response->json('error.code'))->toBe('server_error')
        ->and($response->getContent())->not->toContain('hunter2')->not->toContain('db.internal')
        ->and($response->json('meta.request_id'))->not->toBeEmpty();
});

// ---- the access log ---------------------------------------------------------------------------------------

it('logs one line per request without what the person typed', function () {
    $path = storage_path('logs/api-test-'.bin2hex(random_bytes(4)).'.log');
    config(['logging.channels.api' => ['driver' => 'single', 'path' => $path, 'level' => 'info']]);
    Log::forgetChannel('api');

    $token = apiToken($this->bde);
    apiCall('GET', '/api/v1/leads', ['q' => '9876543210', 'stage' => 'all'], $token)->assertOk();
    apiCall('POST', '/api/v1/auth/login', ['email' => 'ravi@example.test', 'password' => 'a-wrong-password-1', 'device_name' => 'Ravi’s Pixel'])->assertUnauthorized();

    $log = (string) file_get_contents($path);
    File::delete($path);

    expect($log)->toContain('api request')->toContain('api/v1/leads')->toContain('"status":200')->toContain('"status":401')
        ->toContain('"query_keys":["q","stage"]')->toContain('request_id')
        // nothing typed, nothing secret
        ->not->toContain('9876543210')->not->toContain('a-wrong-password-1')->not->toContain(explode('|', $token)[1])->not->toContain('ravi@example.test');
});

it('allows no cross-origin browser until the owner names one', function () {
    $preflight = $this->call('OPTIONS', '/api/v1/leads', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET']);

    expect($preflight->headers->get('Access-Control-Allow-Origin'))->toBeNull()
        ->and($preflight->headers->get('Access-Control-Allow-Credentials'))->toBeNull();
});

it('has no cookie-based sign-in route: Sanctum’s own routes are switched off', function () {
    $uris = collect(app('router')->getRoutes()->getRoutes())->map->uri();

    expect($uris->contains(fn (string $uri) => str_starts_with($uri, 'sanctum')))->toBeFalse()
        ->and(config('sanctum.guard'))->toBe([]);
    $this->get('/sanctum/csrf-cookie')->assertNotFound();
});

it('lists the token model as the one Sanctum uses', function () {
    expect(Sanctum::$personalAccessTokenModel)->toBe(ApiToken::class);
});

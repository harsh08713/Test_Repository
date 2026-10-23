<?php

use App\Models\User;
use App\Modules\CRM\Actions\SeedCrmDefaults;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Real concurrency for the API's idempotency key: several separate PHP processes send the *same* create, with the *same*
 * Idempotency-Key, at the same instant — a phone retrying hard over a bad connection, or two copies of an offline queue.
 * Exactly one lead may come of it. What keeps it so is the unique key on (user, key) in api_idempotency_keys: the test fails
 * if that is removed (then each process creates a lead, or the duplicate mobile warning fires instead of the replay).
 *
 * Commits to the test database (no wrapping transaction, which would hide the race) and cleans up after itself.
 */

/**
 * @param  list<array{0: string, 1: string, 2: string}>  $requests  token, key and JSON body of each process
 * @return list<string>
 */
function runApiWorkers(array $requests): array
{
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => config('database.connections.mysql.host'),
        'DB_PORT' => (string) config('database.connections.mysql.port'),
        'DB_DATABASE' => config('database.connections.mysql.database'),
        'DB_USERNAME' => config('database.connections.mysql.username'),
        'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
        'LOG_CHANNEL' => 'null',
    ]);

    $startAt = sprintf('%.6F', microtime(true) + 5.0 + count($requests) * 0.5);

    $running = [];
    foreach ($requests as [$token, $key, $body]) {
        $process = proc_open([PHP_BINARY, base_path('tests/Support/api_worker.php'), $token, $key, $body, $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        $running[] = [$process, $pipes];
    }

    $lines = [];
    foreach ($running as [$process, $pipes]) {
        $stdout = trim(str_replace("\r", '', stream_get_contents($pipes[1])));
        $stderr = trim(stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $lines[] = $stdout !== '' ? $stdout : 'ERROR: no output'.($stderr !== '' ? " (stderr: {$stderr})" : '');
    }

    return $lines;
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);

    $this->tag = Str::upper(Str::random(5));
    $this->company = makeCompany('AC'.$this->tag);
    $this->location = makeLocation($this->company, 'L'.$this->tag);
    app(SeedCrmDefaults::class)->run($this->company);

    $this->person = makeUser(['Tele-caller'], [$this->company]);
    $this->token = $this->person->createToken('Racing phone', ['read', 'write'], now()->addDay())->plainTextToken;
    $this->body = fn (string $mobile) => json_encode([
        'name' => "Racing lead {$mobile}", 'mobile' => $mobile, 'location_id' => $this->location->id, 'lead_source_id' => leadSourceId($this->company),
    ]);
});

afterEach(function () {
    $id = $this->company->id;

    foreach (['api_idempotency_keys'] as $table) {
        DB::table($table)->where('user_id', $this->person->id)->delete();
    }

    foreach (['lead_stage_history', 'lead_transfers', 'lead_interests', 'leads', 'lost_reasons', 'followup_outcomes', 'lead_sources', 'number_series', 'activity_log', 'company_user'] as $table) {
        DB::table($table)->where('company_id', $id)->delete();
    }

    DB::table('personal_access_tokens')->where('tokenable_id', $this->person->id)->delete();
    DB::table('location_user')->where('user_id', $this->person->id)->delete();
    User::query()->whereKey($this->person->id)->update(['default_company_id' => null]);
    DB::table('model_has_roles')->where('model_id', $this->person->id)->where('model_type', 'user')->delete();
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->where('id', $this->person->id)->delete();
});

it('creates exactly one lead when the same create arrives from several processes at once', function () {
    foreach (range(1, 3) as $round) {
        $mobile = '96'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $key = (string) Str::uuid();

        $lines = runApiWorkers(array_fill(0, 5, [$this->token, $key, ($this->body)($mobile)]));

        $parsed = collect($lines)->map(fn (string $line) => explode('|', $line))->all();
        $created = array_values(array_filter($parsed, fn (array $p) => $p[0] === '201'));
        $busy = array_values(array_filter($parsed, fn (array $p) => $p[0] === '409' && ($p[2] ?? '') === 'request_in_progress'));
        $leads = DB::table('leads')->where('company_id', $this->company->id)->where('mobile', substr($mobile, -10))->get();

        // one process did the work, the rest were told to wait or were handed its answer — nothing failed, nothing was created twice
        expect($leads)->toHaveCount(1, "round {$round}: ".implode(' / ', $lines))
            ->and(count($created) + count($busy))->toBe(5, "round {$round}: ".implode(' / ', $lines))
            ->and(collect($created)->pluck(2)->unique()->values()->all())->toBe([(string) $leads->first()->id], "round {$round}: ".implode(' / ', $lines))
            ->and(collect($created)->where(1, '-'))->toHaveCount(1, "round {$round}: exactly one process may have done the work — ".implode(' / ', $lines));

        // asking again afterwards is a plain replay
        [$again] = runApiWorkers([[$this->token, $key, ($this->body)($mobile)]]);
        expect($again)->toBe('201|true|'.$leads->first()->id);
    }
});

it('lets only one request win when the same key arrives with different requests', function () {
    $key = (string) Str::uuid();
    $mobiles = collect(range(1, 4))->map(fn () => '95'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT));

    $lines = runApiWorkers($mobiles->map(fn (string $m) => [$this->token, $key, ($this->body)($m)])->all());

    $created = collect($lines)->filter(fn (string $l) => str_starts_with($l, '201|'));

    expect(DB::table('leads')->where('company_id', $this->company->id)->count())->toBe(1, implode(' / ', $lines))
        ->and($created)->toHaveCount(1, implode(' / ', $lines))
        ->and(collect($lines)->reject(fn (string $l) => str_starts_with($l, '201|'))->every(fn (string $l) => str_contains($l, 'idempotency_key_reused') || str_contains($l, 'request_in_progress')))->toBeTrue(implode(' / ', $lines));
});

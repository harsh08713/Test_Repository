<?php

use App\Models\User;
use App\Modules\CRM\Actions\SeedCrmDefaults;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Real concurrency for the two promises a marketplace feed depends on, with separate PHP processes delivering at the same instant
 * (a provider that retries hard, or two feeds that carry the same enquiry):
 *
 *  - the same provider lead id delivered by five processes at once is **one** delivery and **one** lead — what makes it so is the
 *    unique key on (company, provider, provider lead id) in lead_api_logs: remove it and every process makes its own;
 *  - five *different* enquiries from the **same buyer** (the same mobile number) arriving together make **one** lead and four notes
 *    on it — what makes it so is the lock LeadIntake takes on the number: remove it and each process finds no lead and makes one.
 *
 * Commits to the test database (no wrapping transaction, which would hide the race) and cleans up after itself. The lock needs a
 * cache every process shares, so the workers use the database cache (a production install must not use the per-process `array` store).
 */

/**
 * @param  list<array{0: string, 1: string, 2: string}>  $posts  provider, public key and JSON body of each process
 * @return list<string>
 */
function runWebhookWorkers(array $posts): array
{
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => config('database.connections.mysql.host'),
        'DB_PORT' => (string) config('database.connections.mysql.port'),
        'DB_DATABASE' => config('database.connections.mysql.database'),
        'DB_USERNAME' => config('database.connections.mysql.username'),
        'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
        'CACHE_STORE' => 'database',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
        'LOG_CHANNEL' => 'null',
    ]);

    $startAt = sprintf('%.6F', microtime(true) + 5.0 + count($posts) * 0.5);

    $running = [];
    foreach ($posts as [$provider, $key, $body]) {
        $process = proc_open([PHP_BINARY, base_path('tests/Support/webhook_worker.php'), $provider, $key, $body, $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
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
    $this->company = makeCompany('WH'.$this->tag);
    $this->location = makeLocation($this->company, 'W'.$this->tag);
    app(SeedCrmDefaults::class)->run($this->company);

    $this->admin = makeUser(['Company Admin'], [$this->company]);
    $this->connection = makeConnection($this->company, $this->admin, $this->location, 'indiamart', ['mode' => 'webhook']);
    $this->post = fn (array $record) => ['indiamart', $this->connection->public_key, json_encode($record)];
});

afterEach(function () {
    $id = $this->company->id;

    foreach (['lead_api_logs', 'comments', 'lead_stage_history', 'lead_transfers', 'lead_interests', 'leads', 'integration_connections', 'lost_reasons', 'followup_outcomes', 'lead_sources',
        'number_series', 'activity_log', 'settings', 'company_user'] as $table) {
        DB::table($table)->where('company_id', $id)->delete();
    }

    DB::table('notifications')->where('notifiable_id', $this->admin->id)->delete();
    DB::table('location_user')->where('user_id', $this->admin->id)->delete();
    User::query()->whereKey($this->admin->id)->update(['default_company_id' => null]);
    DB::table('model_has_roles')->where('model_id', $this->admin->id)->where('model_type', 'user')->delete();
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->where('id', $this->admin->id)->delete();
});

it('makes one delivery and one lead when the same enquiry is delivered by several processes at once', function () {
    foreach (range(1, 3) as $round) {
        $record = indiaMartRecord(['UNIQUE_QUERY_ID' => "RACE-{$this->tag}-{$round}", 'SENDER_MOBILE' => '97'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT)]);

        $lines = runWebhookWorkers(array_fill(0, 5, ($this->post)($record)));

        $rows = DB::table('lead_api_logs')->where('company_id', $this->company->id)->where('provider_lead_id', $record['UNIQUE_QUERY_ID'])->get();
        $leads = DB::table('leads')->where('company_id', $this->company->id)->where('mobile', substr($record['SENDER_MOBILE'], -10))->count();
        $made = collect($lines)->filter(fn (string $l) => str_starts_with($l, '202|1|'))->count();

        expect(collect($lines)->every(fn (string $l) => str_starts_with($l, '202|')))->toBeTrue("round {$round}: ".implode(' / ', $lines))
            ->and($rows)->toHaveCount(1, "round {$round}: ".implode(' / ', $lines))
            ->and($made)->toBe(1, "round {$round}: exactly one delivery was the first — ".implode(' / ', $lines))
            ->and((int) $rows->first()->received_count)->toBe(5, "round {$round}: every delivery is counted")
            ->and($rows->first()->status)->toBe('created')
            ->and($leads)->toBe(1, "round {$round}");
    }
});

it('makes one lead, and notes on it, when different enquiries from one buyer arrive at once', function () {
    foreach (range(1, 2) as $round) {
        $mobile = '96'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

        $lines = runWebhookWorkers(array_map(
            fn (int $i) => ($this->post)(indiaMartRecord(['UNIQUE_QUERY_ID' => "BUYER-{$this->tag}-{$round}-{$i}", 'SENDER_MOBILE' => $mobile, 'QUERY_MESSAGE' => "Enquiry number {$i}"])),
            range(1, 5),
        ));

        $logs = DB::table('lead_api_logs')->where('company_id', $this->company->id)->where('provider_lead_id', 'like', "BUYER-{$this->tag}-{$round}-%")->pluck('status')->countBy()->all();
        $leads = DB::table('leads')->where('company_id', $this->company->id)->where('mobile', $mobile)->get();
        $notes = DB::table('comments')->where('company_id', $this->company->id)->where('commentable_id', $leads->first()->id ?? 0)->count();

        expect(collect($lines)->every(fn (string $l) => str_starts_with($l, '202|')))->toBeTrue("round {$round}: ".implode(' / ', $lines))
            ->and($leads)->toHaveCount(1, "round {$round}: ".implode(' / ', $lines).' '.json_encode($logs))
            ->and(collect($logs)->sortKeys()->all())->toBe(['created' => 1, 'linked' => 4], "round {$round}")
            ->and($notes)->toBe(4, "round {$round}");
    }
});

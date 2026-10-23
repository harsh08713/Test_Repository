<?php

use App\Models\User;
use App\Modules\CRM\Actions\SeedCrmDefaults;
use App\Modules\CRM\Enums\FollowupStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Real concurrency for the lead rules that only a race can break: separate PHP processes, each with its own database
 * connection, act at the same instant.
 *
 *  - Entering leads: several people enter leads in one branch at once — every lead gets a number, no number twice.
 *  - Transfers: a lead handed to two people at the same moment ends up with one of them, and its history still reads as
 *    a chain (each hand-over starts where the last one ended). Only the row lock LeadTransferService takes keeps it so:
 *    this test fails if that lock is removed.
 *  - Follow-ups: the same follow-up finished from two places at once is finished once, and plans its next step once.
 *    Only the row lock FollowupService takes keeps it so.
 *  - Reminders: the scheduler's command run several times at once reminds about each follow-up once.
 *
 * These tests commit to the test database (no wrapping transaction, which would hide the locking) and clean up after themselves.
 */

/**
 * Start one process per argument list, all told to act at the same moment, and collect the one line each printed.
 *
 * @param  list<list<string>>  $jobs  the arguments of each worker, without the start time
 * @return list<string>
 */
function runLeadWorkers(array $jobs): array
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
    ]);

    // Booting the framework takes a moment in each process: give them all time to be ready before the shot.
    $startAt = sprintf('%.6F', microtime(true) + 4.0 + count($jobs) * 0.5);

    $running = [];
    foreach ($jobs as $arguments) {
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/lead_worker.php'), ...$arguments, $startAt],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $env,
        );

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

    $this->tag = Str::upper(Str::random(5));
    $this->company = makeCompany('LC'.$this->tag);
    $this->location = makeLocation($this->company, 'L'.$this->tag);
    app(SeedCrmDefaults::class)->run($this->company);

    $this->manager = makeUser([], [$this->company]);
    $this->people = collect(range(1, 4))->map(fn () => makeUser([], [$this->company]));
    $this->team = makeTeam($this->company, $this->manager, $this->people->all(), 'T'.$this->tag, 'Team '.$this->tag);

    $this->users = fn () => [$this->manager->id, ...$this->people->pluck('id')->all()];
});

afterEach(function () {
    $id = $this->company->id;
    $users = ($this->users)();

    // children first: the foreign keys restrict
    foreach (['followups', 'lead_stage_history', 'lead_transfers', 'lead_interests', 'leads', 'lost_reasons', 'followup_outcomes', 'lead_sources',
        'number_series', 'sales_team_members', 'sales_teams', 'activity_log', 'company_user'] as $table) {
        $table === 'followups'
            ? DB::table($table)->where('company_id', $id)->orderByDesc('id')->delete()     // a next step points at the one before it
            : DB::table($table)->where('company_id', $id)->delete();
    }

    DB::table('notifications')->whereIn('notifiable_id', $users)->delete();
    DB::table('location_user')->whereIn('user_id', $users)->delete();
    User::query()->whereIn('id', $users)->update(['default_company_id' => null]);
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->whereIn('id', $users)->delete();
});

it('numbers every lead once when several are entered in the same branch at the same moment', function () {
    $source = (string) leadSourceId($this->company);
    $jobs = [];
    foreach (range(1, 6) as $i) {
        $jobs[] = ['create', (string) $this->company->id, (string) $this->people[$i % 4]->id, (string) $this->location->id, $source, '97'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT)];
    }

    $lines = runLeadWorkers($jobs);

    $numbers = collect($lines)->filter(fn (string $l) => str_starts_with($l, 'OK '))->map(fn (string $l) => substr($l, 3))->values();
    $serials = $numbers->map(fn (string $n) => (int) Str::afterLast($n, '/'))->sort()->values()->all();

    expect($numbers)->toHaveCount(6, implode(' | ', $lines))
        ->and($numbers->unique())->toHaveCount(6)
        ->and($serials)->toBe(range(1, 6))                                             // nothing skipped, nothing repeated
        ->and(DB::table('leads')->where('company_id', $this->company->id)->count())->toBe(6)
        ->and(DB::table('lead_stage_history')->where('company_id', $this->company->id)->count())->toBe(6)
        ->and(DB::table('lead_transfers')->where('company_id', $this->company->id)->count())->toBe(6);
});

it('keeps a lead’s ownership history a chain when it is handed to several people at once', function () {
    foreach (range(1, 3) as $round) {
        $lead = makeLead($this->company, $this->people[0], $this->location);

        // the manager hands the same lead to three different people in the same instant
        $lines = runLeadWorkers([
            ['transfer', (string) $this->company->id, (string) $this->manager->id, (string) $lead->id, (string) $this->people[1]->id],
            ['transfer', (string) $this->company->id, (string) $this->manager->id, (string) $lead->id, (string) $this->people[2]->id],
            ['transfer', (string) $this->company->id, (string) $this->manager->id, (string) $lead->id, (string) $this->people[3]->id],
        ]);

        $errors = array_values(array_filter($lines, fn (string $l) => str_starts_with($l, 'ERROR:')));
        $history = DB::table('lead_transfers')->where('lead_id', $lead->id)->orderBy('id')->get(['from_user_id', 'to_user_id']);
        $owner = (int) DB::table('leads')->where('id', $lead->id)->value('assigned_to');

        expect($errors)->toBe([], "round {$round}: ".implode(' | ', $lines))
            ->and(collect($lines)->filter(fn (string $l) => $l === 'OK')->count())->toBe(3, implode(' | ', $lines))
            ->and($history)->toHaveCount(4);                                            // entered for the first person, then three hand-overs

        // each hand-over starts where the one before it ended, and the lead is with whoever the last one names
        for ($i = 1; $i < $history->count(); $i++) {
            expect((int) $history[$i]->from_user_id)->toBe((int) $history[$i - 1]->to_user_id, "round {$round}: hand-over {$i} does not follow the one before it — ".json_encode($history));
        }
        expect($owner)->toBe((int) $history->last()->to_user_id, "round {$round}: the lead is not with the person its history ends on");
    }
});

it('finishes a follow-up once, and plans its next step once, when it is finished from several places at once', function () {
    $lead = makeLead($this->company, $this->people[0], $this->location);
    $followup = makeFollowup($this->company, $this->people[0], $lead, ['scheduled_at' => now()->addHours(2)->format('Y-m-d\TH:i')]);
    $outcome = (string) outcomeId($this->company, 'CALL_BACK_LATER');
    $nextAt = now()->addDays(2)->format('Y-m-d\TH:i');

    $finish = ['complete', (string) $this->company->id, (string) $this->people[0]->id, (string) $followup->id, $outcome, $nextAt];
    $lines = runLeadWorkers([$finish, $finish, $finish, $finish]);

    $followups = DB::table('followups')->where('subject_id', $lead->id)->where('subject_type', 'lead')->get();

    expect(collect($lines)->filter(fn (string $l) => $l === 'OK')->count())->toBe(1, implode(' | ', $lines))
        ->and(collect($lines)->filter(fn (string $l) => $l === 'REFUSED: This follow-up is already done.')->count())->toBe(3, implode(' | ', $lines))
        ->and($followups->where('status', FollowupStatus::Done->value))->toHaveCount(1)
        ->and($followups->where('status', FollowupStatus::Pending->value))->toHaveCount(1)      // one next step, not four
        ->and((int) $followups->where('status', FollowupStatus::Pending->value)->first()->previous_id)->toBe($followup->id)
        ->and(DB::table('lead_stage_history')->where('lead_id', $lead->id)->count())->toBe(2);  // created, then contacted — once
});

it('reminds about each follow-up once when the scheduler’s command runs several times at once', function () {
    // Enough follow-ups that the runs are all in the middle of their work at the same time: each run reads the same
    // list of what is due, and only the claim keeps them from each reminding about every one of them.
    $count = 24;
    $due = [];
    foreach (range(1, $count) as $i) {
        $person = $this->people[$i % 4];
        $lead = makeLead($this->company, $person, $this->location);
        $due[] = makeFollowup($this->company, $person, $lead, ['scheduled_at' => now()->addMinutes(5 + ($i % 20))->format('Y-m-d\TH:i')]);   // inside the 30-minute lead time
    }

    // four overlapping runs, each a second "later" than the last, as runs that outlast their slot are
    $lines = runLeadWorkers([['remind', '0'], ['remind', '1'], ['remind', '2'], ['remind', '3']]);

    $reminders = DB::table('notifications')->whereIn('notifiable_id', $this->people->pluck('id')->all())->where('type', 'like', '%FollowupDueNotification')->get();

    expect($lines)->toBe(['OK', 'OK', 'OK', 'OK'])
        ->and($reminders)->toHaveCount($count)                                          // one reminder per follow-up, however many runs there were
        ->and($reminders->groupBy('notifiable_id')->map->count()->values()->all())->toBe([6, 6, 6, 6])
        ->and(DB::table('followups')->whereIn('id', collect($due)->pluck('id')->all())->whereNotNull('reminder_sent_at')->count())->toBe($count);
});

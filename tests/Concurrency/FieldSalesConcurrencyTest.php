<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Real concurrency for two field-sales rules: separate PHP processes, each with its own database connection, act at the
 * same instant.
 *
 *  - Targets: two targets for the same person and scope may not cover the same days. A monthly target and the quarter
 *    that contains it have different keys, so only the lock TargetService takes on the person keeps them apart — this
 *    test fails if that lock is removed.
 *  - Attendance: one record per person and day, however many times the button is tapped at once; the taps that lose are
 *    told "already marked", they do not crash.
 *
 * These tests commit to the test database (no wrapping transaction, which would hide the locking) and clean up after themselves.
 */

/**
 * Start one process per argument list, all told to act at the same moment, and collect the one line each printed.
 *
 * @param  list<list<string>>  $jobs  the arguments of each worker, without the start time
 * @return list<string>
 */
function runFieldWorkers(array $jobs): array
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
    ]);

    // Booting the framework takes a moment in each process: give them all time to be ready before the shot.
    $startAt = sprintf('%.6F', microtime(true) + 4.0 + count($jobs) * 0.5);

    $running = [];
    foreach ($jobs as $arguments) {
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/field_worker.php'), ...$arguments, $startAt],
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
    $this->company = makeCompany('FC'.$this->tag);
    $this->location = makeLocation($this->company, 'F'.$this->tag);
    $this->manager = makeUser([], [$this->company]);
    $this->person = makeUser([], [$this->company]);
    $this->team = makeTeam($this->company, $this->manager, [$this->person], 'T'.$this->tag, 'Team '.$this->tag);
});

afterEach(function () {
    $id = $this->company->id;

    foreach (['attendance_records', 'geofence_violations', 'route_plan_stops', 'route_plans', 'sales_targets', 'sales_team_members', 'sales_teams', 'activity_log', 'company_user'] as $table) {
        DB::table($table)->where('company_id', $id)->delete();
    }

    DB::table('location_user')->whereIn('user_id', [$this->manager->id, $this->person->id])->delete();
    User::query()->whereIn('id', [$this->manager->id, $this->person->id])->update(['default_company_id' => null]);
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->whereIn('id', [$this->manager->id, $this->person->id])->delete();
});

it('never lets a monthly target and the quarter that contains it both be saved when they race', function () {
    $company = (string) $this->company->id;
    $actor = (string) $this->manager->id;
    $person = (string) $this->person->id;

    foreach ([2031, 2032, 2033] as $year) {
        // Q3 of a financial year that starts in April is October–December: the quarter overlaps each of its months
        $lines = runFieldWorkers([
            ['target', $company, $actor, $person, 'quarterly', "{$year}-10-01"],
            ['target', $company, $actor, $person, 'monthly', "{$year}-10-01"],
            ['target', $company, $actor, $person, 'monthly', "{$year}-11-01"],
            ['target', $company, $actor, $person, 'monthly', "{$year}-12-01"],
        ]);

        $errors = array_values(array_filter($lines, fn (string $l) => str_starts_with($l, 'ERROR:')));
        $saved = DB::table('sales_targets')->where('company_id', $this->company->id)->whereBetween('period_start', ["{$year}-10-01", "{$year}-12-31"])->whereNull('deleted_at')->get(['period_type', 'period_start', 'period_end']);
        $quarters = $saved->where('period_type', 'quarterly')->count();
        $months = $saved->where('period_type', 'monthly')->count();

        expect($errors)->toBe([], "{$year}: ".implode(' | ', $lines))
            ->and($saved->count())->toBeGreaterThan(0)
            // either the quarter got there first and every month was refused, or the months did and the quarter was refused
            ->and($quarters === 0 || $months === 0)->toBeTrue("{$year}: a quarter and {$months} of its months were both saved — ".implode(' | ', $lines))
            ->and(collect($lines)->filter(fn (string $l) => $l === 'OK')->count())->toBe($saved->count());
    }
});

it('records one attendance when the button is tapped several times at once, and tells the rest it is already marked', function () {
    makePlan($this->company, $this->manager, $this->person, $this->location);

    $tap = ['attendance', (string) $this->company->id, (string) $this->person->id, (string) HQ_LAT, (string) HQ_LNG];
    $lines = runFieldWorkers([$tap, $tap, $tap, $tap, $tap]);

    expect(collect($lines)->filter(fn (string $l) => $l === 'OK')->count())->toBe(1, implode(' | ', $lines))
        ->and(collect($lines)->filter(fn (string $l) => $l === 'REFUSED: already marked')->count())->toBe(4, implode(' | ', $lines))
        ->and(DB::table('attendance_records')->where('company_id', $this->company->id)->count())->toBe(1)
        ->and(DB::table('geofence_violations')->where('company_id', $this->company->id)->count())->toBe(0);
});

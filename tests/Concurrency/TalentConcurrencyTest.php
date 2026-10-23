<?php

use App\Models\User;
use App\Modules\HR\Models\Candidate;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobOpening;
use App\Modules\HR\Models\Offer;
use App\Modules\HR\Models\TrainingProgram;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/*
 * Real concurrency for the two places HR hands out something that is in short supply, with separate PHP processes acting at the same instant:
 *
 *  - An opening with **one position** hired for by several people at once (two applicants, two people pressing "Hire" for each) makes **one** employee file.
 *  - A training program with **two seats** enrolled by five people at once takes **two**.
 *
 * Commits to the test database (no wrapping transaction, which would hide the race) and cleans up after itself.
 */

/**
 * @param  list<string>  $perWorker  one entry per worker: its arguments joined by "|"
 * @return list<string> one line per worker
 */
function runTalentWorkers(string $mode, array $perWorker): array
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
    $startAt = sprintf('%.6F', microtime(true) + 4.0 + count($perWorker) * 0.5);

    $running = [];
    foreach ($perWorker as $args) {
        $running[] = [proc_open([PHP_BINARY, base_path('tests/Support/hr_worker.php'), $mode, ...explode('|', $args), $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env), $pipes];
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
    $this->company = makeCompany('TC'.$this->tag);
    $this->location = makeLocation($this->company, 'T'.$this->tag);

    foreach (['recruitment.edit', 'employee.create', 'training.edit'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->hr = makeUser([], [$this->company]);
    $this->hr->givePermissionTo(['recruitment.edit', 'employee.create', 'training.edit']);
});

afterEach(function () {
    $id = $this->company->id;

    foreach (['training_enrolments', 'training_programs', 'application_events', 'offers', 'applications', 'candidates', 'job_openings', 'employment_events', 'employees', 'activity_log', 'number_series', 'company_user'] as $table) {
        DB::table($table)->where('company_id', $id)->delete();
    }

    DB::table('model_has_permissions')->where('model_id', $this->hr->id)->where('model_type', 'user')->delete();
    DB::table('location_user')->where('user_id', $this->hr->id)->delete();
    User::query()->whereKey($this->hr->id)->update(['default_company_id' => null]);
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->where('id', $this->hr->id)->delete();
});

it('makes one employee file when several people hire for a single position at the same moment', function () {
    $c = $this->company->id;
    $opening = (new JobOpening)->forceFill(['company_id' => $c, 'location_id' => $this->location->id, 'code' => 'J'.$this->tag, 'title' => 'The one job', 'positions' => 1, 'state' => 'open', 'opened_on' => now()->toDateString()]);
    $opening->save();

    $applications = [];
    foreach ([1, 2] as $n) {
        $candidate = (new Candidate)->forceFill(['company_id' => $c, 'name' => 'Racer '.$n, 'mobile' => '9'.str_pad((string) random_int(100000000, 999999999), 9, '0'), 'source' => 'other']);
        $candidate->save();
        $application = (new JobApplication)->forceFill(['company_id' => $c, 'candidate_id' => $candidate->id, 'job_opening_id' => $opening->id, 'stage' => 'offer']);
        $application->save();
        (new Offer)->forceFill(['company_id' => $c, 'application_id' => $application->id, 'joining_date' => now()->addDays(10)->toDateString(), 'state' => 'accepted', 'responded_on' => now()->toDateString()])->save();
        $applications[] = $application;
    }

    $workers = [];
    foreach ([0, 1, 0, 1] as $which) {
        $workers[] = implode('|', [(string) $c, (string) $this->hr->id, (string) $applications[$which]->id]);
    }

    $lines = runTalentWorkers('hire', $workers);
    $ok = collect($lines)->filter(fn (string $l) => $l === 'OK')->count();
    $refused = collect($lines)->filter(fn (string $l) => str_starts_with($l, 'REFUSED:'))->count();

    expect($ok)->toBe(1, implode(' | ', $lines))->and($refused)->toBe(3, implode(' | ', $lines))
        ->and(Employee::withoutGlobalScopes()->where('company_id', $c)->count())->toBe(1)
        ->and(DB::table('applications')->where('job_opening_id', $opening->id)->where('stage', 'hired')->count())->toBe(1)
        ->and(DB::table('job_openings')->where('id', $opening->id)->value('state'))->toBe('closed');
});

it('takes only the seats a training program has when several people are enrolled at the same moment', function () {
    $c = $this->company->id;
    $program = (new TrainingProgram)->forceFill(['company_id' => $c, 'code' => 'P'.$this->tag, 'title' => 'Two seats only', 'mode' => 'online', 'starts_on' => now()->addDays(10)->toDateString(), 'ends_on' => now()->addDays(10)->toDateString(), 'capacity' => 2, 'state' => 'planned']);
    $program->save();

    $workers = [];
    foreach (range(1, 5) as $n) {
        $employee = (new Employee)->forceFill(['company_id' => $c, 'location_id' => $this->location->id, 'code' => 'E'.$n.$this->tag, 'name' => 'Seeker '.$n, 'joining_date' => '2024-01-01', 'employment_type' => 'permanent', 'employment_status' => 'active']);
        $employee->save();
        $workers[] = implode('|', [(string) $c, (string) $this->hr->id, (string) $program->id, (string) $employee->id]);
    }

    $lines = runTalentWorkers('enrol', $workers);
    $ok = collect($lines)->filter(fn (string $l) => $l === 'OK')->count();
    $full = collect($lines)->filter(fn (string $l) => str_starts_with($l, 'REFUSED:') && str_contains($l, 'is full'))->count();

    expect($ok)->toBe(2, implode(' | ', $lines))->and($full)->toBe(3, implode(' | ', $lines))
        ->and(DB::table('training_enrolments')->where('training_program_id', $program->id)->count())->toBe(2);
});

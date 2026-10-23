<?php

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\Shared\Support\FinancialYear;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/*
 * Real concurrency for what HR promises, with separate PHP processes (each with its own database connection) acting at the same instant:
 *
 *  - A leave request approved by several people at once takes its days **once**: one "taken" line in the ledger, one winner, the others told it is already decided.
 *  - A person who sends the same leave from several tabs at once gets **one** request: overlapping requests cannot both be saved.
 *  - A person who taps "punch in" several times at once is punched in **once**.
 *
 * Commits to the test database (no wrapping transaction, which would hide the race) and cleans up after itself.
 */

/**
 * @param  list<string>  $args  every argument after the mode and before the start time
 * @return list<string> one line per worker
 */
function runHrWorkers(string $mode, array $args, int $count): array
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
    $startAt = sprintf('%.6F', microtime(true) + 4.0 + $count * 0.5);

    $running = [];
    foreach ($args as $i => $perWorker) {
        $running[] = [proc_open([PHP_BINARY, base_path('tests/Support/hr_worker.php'), $mode, ...explode('|', $perWorker), $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env), $pipes];
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
    $this->company = makeCompany('HC'.$this->tag);
    $this->location = makeLocation($this->company, 'H'.$this->tag);
    $c = $this->company->id;

    Permission::findOrCreate('leave.approve_all', 'web');
    $this->approverA = makeUser([], [$this->company]);
    $this->approverB = makeUser([], [$this->company]);
    $this->approverA->givePermissionTo('leave.approve_all');
    $this->approverB->givePermissionTo('leave.approve_all');
    $this->workerUser = makeUser([], [$this->company]);

    $this->employee = (new Employee)->forceFill([
        'company_id' => $c, 'location_id' => $this->location->id, 'code' => 'E'.$this->tag, 'name' => 'Racing '.$this->tag, 'joining_date' => '2024-01-01', 'employment_type' => 'permanent',
        'employment_status' => 'active', 'user_id' => $this->workerUser->id,
    ]);
    $this->employee->save();

    $this->type = (new LeaveType(['code' => 'C'.$this->tag, 'name' => 'Casual '.$this->tag, 'annual_quota' => '12', 'accrual' => 'none', 'allow_half_day' => true]))->forceFill(['company_id' => $c]);
    $this->type->save();

    // twelve days in the ledger for the leave year that contains today
    $year = FinancialYear::startYear(now());
    DB::table('leave_ledger')->insert(['company_id' => $c, 'employee_id' => $this->employee->id, 'leave_type_id' => $this->type->id, 'leave_year' => $year, 'entry_type' => 'opening', 'days' => 12,
        'period_key' => 'C'.$year, 'entry_date' => now()->toDateString(), 'created_at' => now()]);

    $this->ledger = fn (string $type) => DB::table('leave_ledger')->where('employee_id', $this->employee->id)->where('entry_type', $type)->count();
});

afterEach(function () {
    $id = $this->company->id;
    $users = [$this->approverA->id, $this->approverB->id, $this->workerUser->id];

    foreach (['leave_ledger', 'leave_requests', 'attendance_punches', 'attendance_days', 'leave_types', 'employment_events', 'employees', 'activity_log', 'number_series', 'company_user'] as $table) {
        DB::table($table)->where('company_id', $id)->delete();
    }

    DB::table('model_has_permissions')->whereIn('model_id', $users)->where('model_type', 'user')->delete();
    DB::table('location_user')->whereIn('user_id', $users)->delete();
    User::query()->whereIn('id', $users)->update(['default_company_id' => null]);
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->whereIn('id', $users)->delete();
});

it('takes the days of a leave request once when several people approve it at the same moment', function () {
    $monday = now()->next('Monday')->addWeek();     // two working days next-but-one week, so it is in the future whatever today is
    $request = (new LeaveRequest)->forceFill([
        'company_id' => $this->company->id, 'location_id' => $this->location->id, 'employee_id' => $this->employee->id, 'leave_type_id' => $this->type->id,
        'from_date' => $monday->toDateString(), 'to_date' => $monday->copy()->addDay()->toDateString(), 'days' => '2.0', 'reason' => 'Race', 'state' => 'pending',
    ]);
    $request->save();

    $company = (string) $this->company->id;
    $workers = [];
    foreach ([$this->approverA, $this->approverB, $this->approverA, $this->approverB, $this->approverA] as $approver) {
        $workers[] = implode('|', [$company, (string) $approver->id, (string) $request->id]);
    }

    $lines = runHrWorkers('approve', $workers, 5);
    $ok = collect($lines)->filter(fn (string $l) => $l === 'OK')->count();
    $told = collect($lines)->filter(fn (string $l) => str_starts_with($l, 'REFUSED:') && str_contains($l, 'already been'))->count();

    expect($ok)->toBe(1, implode(' | ', $lines))->and($told)->toBe(4, implode(' | ', $lines))
        ->and(($this->ledger)('taken'))->toBe(1)
        ->and((float) DB::table('leave_ledger')->where('employee_id', $this->employee->id)->sum('days'))->toBe(10.0)
        ->and(DB::table('leave_requests')->where('id', $request->id)->value('state'))->toBe('approved');
});

it('saves one request when the same leave is sent from several places at the same moment', function () {
    $monday = now()->next('Monday')->addWeeks(2);
    $args = implode('|', [(string) $this->company->id, (string) $this->workerUser->id, (string) $this->employee->id, (string) $this->type->id, $monday->toDateString(), $monday->copy()->addDay()->toDateString()]);

    $lines = runHrWorkers('apply', array_fill(0, 5, $args), 5);
    $ok = collect($lines)->filter(fn (string $l) => $l === 'OK')->count();
    $told = collect($lines)->filter(fn (string $l) => str_starts_with($l, 'REFUSED:') && str_contains($l, 'overlaps'))->count();

    expect($ok)->toBe(1, implode(' | ', $lines))->and($told)->toBe(4, implode(' | ', $lines))
        ->and(DB::table('leave_requests')->where('employee_id', $this->employee->id)->count())->toBe(1);
});

it('punches a person in once when they tap several times at the same moment', function () {
    $args = implode('|', [(string) $this->company->id, (string) $this->workerUser->id, (string) $this->employee->id, 'in']);

    $lines = runHrWorkers('punch', array_fill(0, 5, $args), 5);
    $ok = collect($lines)->filter(fn (string $l) => $l === 'OK')->count();
    $told = collect($lines)->filter(fn (string $l) => str_starts_with($l, 'REFUSED:') && str_contains($l, 'already punched in'))->count();

    expect($ok)->toBe(1, implode(' | ', $lines))->and($told)->toBe(4, implode(' | ', $lines))
        ->and(DB::table('attendance_punches')->where('employee_id', $this->employee->id)->count())->toBe(1);
});

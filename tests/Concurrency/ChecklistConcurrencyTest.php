<?php

use App\Models\User;
use App\Modules\HR\Models\ChecklistTemplateItem;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeChecklistItem;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Real concurrency for onboarding lists: HR presses "Start" while the hire is still being saved, or two people press it together. Six separate PHP processes start the same person's
 * list at the same instant, and each line of the standard list is copied **once** (the employee row lock, with the unique key behind it). Commits to the test database and cleans up.
 */

function runChecklistWorkers(int $count, int $companyId, int $actorId, int $employeeId, string $mode = 'checklist'): array
{
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => config('database.default'), 'DB_HOST' => config('database.connections.mysql.host'),
        'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_DATABASE' => config('database.connections.mysql.database'),
        'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    ]);

    $startAt = sprintf('%.6F', microtime(true) + 4.0 + $count * 0.5);
    $running = [];

    foreach (range(1, $count) as $n) {
        $running[] = [proc_open([PHP_BINARY, base_path('tests/Support/hr_worker.php'), $mode, (string) $companyId, (string) $actorId, ...($mode === 'checklist' ? [(string) $employeeId, 'onboarding'] : []), $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env), $pipes];
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
    $this->company = makeCompany('TK'.$this->tag);
    $this->location = makeLocation($this->company, 'K'.$this->tag);
    $this->hr = makeUser([], [$this->company]);
});

afterEach(function () {
    $id = $this->company->id;

    foreach (['employee_checklist_items', 'checklist_template_items', 'employees', 'activity_log', 'number_series', 'company_user'] as $table) {
        DB::table($table)->where('company_id', $id)->delete();
    }

    DB::table('location_user')->where('user_id', $this->hr->id)->delete();
    User::query()->whereKey($this->hr->id)->update(['default_company_id' => null]);
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->where('id', $this->hr->id)->delete();
});

it('copies each line of the standard list once however many people start the list at the same moment', function () {
    $c = $this->company->id;

    foreach ([['A'.$this->tag, 'Laptop', 'hr', 1], ['B'.$this->tag, 'Welcome chat', 'manager', 3], ['C'.$this->tag, 'Handbook', 'employee', 7]] as [$code, $name, $who, $days]) {
        (new ChecklistTemplateItem)->forceFill(['company_id' => $c, 'code' => $code, 'name' => $name, 'kind' => 'onboarding', 'responsible' => $who, 'due_days' => $days, 'status' => 'active'])->save();
    }

    $employee = (new Employee)->forceFill(['company_id' => $c, 'location_id' => $this->location->id, 'code' => 'E'.$this->tag, 'name' => 'Racer', 'employment_type' => 'permanent', 'joining_date' => '2026-10-12', 'employment_status' => 'active']);
    $employee->save();

    $lines = runChecklistWorkers(6, $c, $this->hr->id, $employee->id);

    expect(collect($lines)->reject(fn ($l) => $l === 'OK')->all())->toBe([], 'a worker failed: '.implode(' | ', $lines))
        ->and(EmployeeChecklistItem::withoutGlobalScopes(Tenancy::SCOPES)->where('employee_id', $employee->id)->count())->toBe(3)
        ->and(EmployeeChecklistItem::withoutGlobalScopes(Tenancy::SCOPES)->where('employee_id', $employee->id)->distinct()->count('source_item_id'))->toBe(3);
});

it('tells a person about a late line once however many reminder runs start at the same moment', function () {
    $c = $this->company->id;
    $line = (new ChecklistTemplateItem)->forceFill(['company_id' => $c, 'code' => 'R'.$this->tag, 'name' => 'Handbook', 'kind' => 'onboarding', 'responsible' => 'employee', 'due_days' => 1, 'status' => 'active']);
    $line->save();

    $employee = (new Employee)->forceFill(['company_id' => $c, 'location_id' => $this->location->id, 'user_id' => $this->hr->id, 'code' => 'E'.$this->tag, 'name' => 'Late one', 'employment_type' => 'permanent', 'joining_date' => '2026-01-05', 'employment_status' => 'active']);
    $employee->save();
    (new EmployeeChecklistItem)->forceFill(['company_id' => $c, 'employee_id' => $employee->id, 'kind' => 'onboarding', 'source_item_id' => $line->id, 'title' => 'Handbook', 'responsible' => 'employee', 'due_on' => '2026-01-06'])->save();

    $lines = runChecklistWorkers(6, $c, $this->hr->id, $employee->id, 'remind');

    expect(collect($lines)->reject(fn ($l) => $l === 'OK')->all())->toBe([], 'a worker failed: '.implode(' | ', $lines))
        ->and(DB::table('notifications')->where('notifiable_id', $this->hr->id)->count())->toBe(1)
        ->and(DB::table('employee_checklist_items')->where('employee_id', $employee->id)->value('reminded_at'))->not->toBeNull();

    DB::table('notifications')->where('notifiable_id', $this->hr->id)->delete();
});

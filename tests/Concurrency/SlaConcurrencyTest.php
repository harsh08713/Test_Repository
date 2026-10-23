<?php

use App\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Models\SalesTeamMember;
use App\Modules\Workspace\Models\SlaPolicy;
use App\Modules\Workspace\Models\Task;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Real concurrency for the two promises the SLA engine makes, with separate PHP processes (each with its own database connection) running at the
 * same instant:
 *
 *  - A clock that runs out is **recorded once and announced once**: five sweeps over the same overdue lead write one breach, set `breached_at`
 *    once, and tell the person and their manager once each (the conditional UPDATE that claims the breach is what decides who does it).
 *  - A task's reminder and its overdue notice go to the person **once** however many reminder runs overlap.
 *
 * Commits to the test database (no wrapping transaction, which would hide the race) and cleans up after itself.
 */

/**
 * @param  list<string>  $args  after the mode, before the start time
 * @return list<string>
 */
function runSlaWorkers(string $mode, int $count): array
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
    for ($i = 0; $i < $count; $i++) {
        $running[] = [proc_open([PHP_BINARY, base_path('tests/Support/sla_worker.php'), $mode, $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env), $pipes];
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
    $this->company = makeCompany('SL'.$this->tag, ['state_code' => '24']);
    $this->location = makeLocation($this->company, 'L'.$this->tag, ['state_code' => '24']);
    $this->owner = makeUser([], [$this->company]);
    $this->manager = makeUser([], [$this->company]);
    $c = $this->company->id;

    $team = (new SalesTeam(['code' => 'T'.$this->tag, 'name' => 'Team '.$this->tag]))->forceFill(['company_id' => $c, 'manager_user_id' => $this->manager->id, 'status' => 'active']);
    $team->save();
    (new SalesTeamMember)->forceFill(['company_id' => $c, 'sales_team_id' => $team->id, 'user_id' => $this->owner->id, 'joined_on' => '2026-01-01'])->save();

    $source = (new LeadSource(['code' => 'S'.$this->tag, 'name' => 'Source '.$this->tag]))->forceFill(['company_id' => $c]);
    $source->save();

    // an old policy (it must predate the lead) and a lead that has waited for two days without a word
    $policy = new SlaPolicy;
    $policy->fill(['name' => 'First response', 'kind' => 'first_response', 'target_minutes' => 60, 'business_hours' => false, 'escalate_to_manager' => true])->forceFill(['company_id' => $c])->save();
    DB::table('sla_policies')->where('id', $policy->id)->update(['created_at' => now()->subDays(10)]);

    $this->lead = new Lead;
    $this->lead->forceFill([
        'company_id' => $c, 'location_id' => $this->location->id, 'lead_number' => "LD/{$this->tag}/1", 'lead_date' => now()->subDays(2)->toDateString(), 'lead_source_id' => $source->id,
        'name' => 'Waiting lead', 'mobile' => '9500000002', 'stage' => 'new', 'assigned_to' => $this->owner->id, 'created_by' => $this->owner->id,
    ])->save();
    DB::table('leads')->where('id', $this->lead->id)->update(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);

    $this->notified = fn (int $userId, string $kind) => DB::table('notifications')->where('notifiable_id', $userId)->where('data', 'like', '%"kind":"'.$kind.'"%')->count();
});

afterEach(function () {
    $id = $this->company->id;
    $users = [$this->owner->id, $this->manager->id];

    DB::table('notifications')->whereIn('notifiable_id', $users)->delete();

    foreach (['sla_breaches', 'sla_clocks', 'sla_policies', 'task_assignments', 'tasks', 'lead_stage_history', 'leads', 'lead_sources', 'sales_team_members', 'sales_teams',
        'activity_log', 'number_series', 'company_user'] as $table) {
        DB::table($table)->where('company_id', $id)->delete();
    }

    DB::table('location_user')->whereIn('user_id', $users)->delete();
    User::query()->whereIn('id', $users)->update(['default_company_id' => null]);
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->whereIn('id', $users)->delete();
});

it('records an overdue lead’s breach once, and tells the person and the manager once, when five sweeps run at the same moment', function () {
    $lines = runSlaWorkers('sla', 5);

    expect(collect($lines)->every(fn (string $l) => str_starts_with($l, 'OK ')))->toBeTrue(implode(' | ', $lines));

    $started = collect($lines)->sum(fn (string $l) => (int) (preg_match('/started=(\d+)/', $l, $m) ? $m[1] : 0));
    $breached = collect($lines)->sum(fn (string $l) => (int) (preg_match('/breached=(\d+)/', $l, $m) ? $m[1] : 0));

    expect($started)->toBe(1, 'one clock for the lead, not one for each process: '.implode(' | ', $lines))
        ->and($breached)->toBe(1, 'one breach announced: '.implode(' | ', $lines))
        ->and(DB::table('sla_clocks')->where('company_id', $this->company->id)->count())->toBe(1)
        ->and(DB::table('sla_breaches')->where('company_id', $this->company->id)->count())->toBe(1)
        ->and(DB::table('sla_clocks')->where('company_id', $this->company->id)->whereNotNull('breached_at')->count())->toBe(1)
        ->and(($this->notified)($this->owner->id, 'sla_breached'))->toBe(1)
        ->and(($this->notified)($this->manager->id, 'sla_escalated'))->toBe(1)
        ->and(DB::table('activity_log')->where('company_id', $this->company->id)->where('log_name', 'sla')->count())->toBe(1);

    // and a later sweep finds nothing more to say
    expect(collect(runSlaWorkers('sla', 2))->every(fn (string $l) => str_contains($l, 'started=0 settled=0 breached=0')))->toBeTrue();
});

it('sends a task’s reminder and its overdue notice once each when several reminder runs overlap', function () {
    $task = new Task;
    $task->forceFill([
        'company_id' => $this->company->id, 'location_id' => $this->location->id, 'title' => 'Chase the order', 'status' => 'open', 'priority' => 'normal', 'recurrence' => 'none',
        'assigned_to' => $this->owner->id, 'created_by' => $this->owner->id, 'due_at' => now()->subHour(), 'remind_at' => now()->subHours(3),
    ])->save();

    $lines = runSlaWorkers('remind', 5);

    expect(collect($lines)->every(fn (string $l) => str_starts_with($l, 'OK ')))->toBeTrue(implode(' | ', $lines))
        ->and(($this->notified)($this->owner->id, 'task_reminder'))->toBe(1, implode(' | ', $lines))
        ->and(($this->notified)($this->owner->id, 'task_overdue'))->toBe(1, implode(' | ', $lines));
});

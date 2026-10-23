<?php

use App\Models\User;
use App\Modules\Reports\Models\ReportExport;
use App\Modules\Reports\Models\ReportSchedule;
use App\Modules\Reports\Services\ReportScheduler;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/*
 * Real concurrency for scheduled reports: the scheduler runs every fifteen minutes and a run may overlap the next (or a second server may run it too). Six separate PHP processes
 * ask for the same due schedule at the same instant — each with its own clock — and exactly **one** may start it, so the owner gets one file and one e-mail, not six.
 * Commits to the test database (no wrapping transaction, which would hide the race) and cleans up after itself.
 */

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);

    $this->tag = Str::upper(Str::random(5));
    $this->company = makeCompany('TR'.$this->tag);
    $this->location = makeLocation($this->company, 'R'.$this->tag);

    foreach (['report.view', 'report.export', 'stock.view'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->owner = makeUser([], [$this->company]);
    $this->owner->givePermissionTo(['report.view', 'report.export', 'stock.view']);
});

afterEach(function () {
    $files = ReportExport::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->company->id)->pluck('file_path')->filter()->all();
    Storage::disk('local')->delete($files);

    DB::table('notifications')->where('notifiable_id', $this->owner->id)->delete();
    DB::table('report_exports')->where('company_id', $this->company->id)->delete();
    DB::table('report_schedules')->where('company_id', $this->company->id)->delete();

    $id = $this->company->id;

    foreach (['activity_log', 'number_series', 'company_user'] as $table) {
        DB::table($table)->where('company_id', $id)->delete();
    }

    DB::table('model_has_permissions')->where('model_id', $this->owner->id)->where('model_type', 'user')->delete();
    DB::table('location_user')->where('user_id', $this->owner->id)->delete();
    User::query()->whereKey($this->owner->id)->update(['default_company_id' => null]);
    DB::table('locations')->where('company_id', $id)->delete();
    DB::table('companies')->where('id', $id)->delete();
    DB::table('users')->where('id', $this->owner->id)->delete();
});

it('starts a due scheduled report once however many runs ask for it at the same moment', function () {
    $schedule = app(TenantContext::class)->withCompany($this->company->id, fn () => app(ReportScheduler::class)->create(
        ['report' => 'stock-valuation', 'format' => 'xlsx', 'frequency' => 'daily', 'send_hour' => 7], $this->owner,
    ));
    DB::table('report_schedules')->where('id', $schedule->id)->update(['next_run_at' => now()->subMinute()]);

    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => config('database.default'), 'DB_HOST' => config('database.connections.mysql.host'),
        'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_DATABASE' => config('database.connections.mysql.database'),
        'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    ]);

    $workers = 6;
    $startAt = sprintf('%.6F', microtime(true) + 4.0 + $workers * 0.5);
    $running = [];

    foreach (range(1, $workers) as $n) {
        $running[] = [proc_open([PHP_BINARY, base_path('tests/Support/report_worker.php'), 'run', (string) $n, $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env), $pipes];
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

    expect(collect($lines)->filter(fn ($l) => ! str_starts_with($l, 'OK '))->all())->toBe([], 'a worker failed: '.implode(' | ', $lines))
        ->and(collect($lines)->sum(fn ($l) => (int) substr($l, 3)))->toBe(1, implode(' | ', $lines))
        ->and(ReportExport::withoutGlobalScopes(Tenancy::SCOPES)->where('schedule_id', $schedule->id)->count())->toBe(1)
        ->and(DB::table('notifications')->where('notifiable_id', $this->owner->id)->count())->toBe(1)
        ->and(ReportSchedule::withoutGlobalScopes(Tenancy::SCOPES)->find($schedule->id)->next_run_at->isFuture())->toBeTrue();
});

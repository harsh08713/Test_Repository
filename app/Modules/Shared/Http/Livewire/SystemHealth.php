<?php

namespace App\Modules\Shared\Http\Livewire;

use App\Models\User;
use App\Modules\Shared\Services\Heartbeat;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Is everything that has to run on its own actually running? The scheduler and the jobs it starts leave a note each time they run
 * (`Heartbeat`); the queue, the mail transport, the private storage disk and the cache are looked at directly. Read-only: it changes nothing and
 * shows no secret — only names, counts and ages.
 */
#[Title('System health')]
class SystemHealth extends Component
{
    /** The jobs the scheduler starts, and how often each is expected to report in (minutes, with room to spare). */
    public const JOBS = [
        'sla:check' => ['SLA sweep', 15],
        'tasks:remind' => ['Task reminders', 15],
        'crm:followup-reminders' => ['Follow-up reminders', 15],
        'marketing:run' => ['Campaign sending', 15],
        'automation:run' => ['Workflow automation', 15],
    ];

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->can('setting.view'), 403);
    }

    public function render(Heartbeat $beats): View
    {
        return view('shared.system-health', ['checks' => $this->checks($beats)]);
    }

    /**
     * @return list<array{name: string, status: string, detail: string}> status is ok | warn | fail
     */
    public function checks(Heartbeat $beats): array
    {
        $out = [];
        $ago = fn (?Carbon $at) => $at === null ? 'never seen' : $at->diffForHumans();

        // ---- scheduler
        $last = $beats->last(Heartbeat::SCHEDULER);
        $out[] = match (true) {
            $last === null => ['name' => 'Scheduler', 'status' => 'warn', 'detail' => 'Never seen. Reminders, SLA checks and campaigns only run while `php artisan schedule:run` is called every minute (a Windows Task Scheduler entry, or a cron line on a server).'],
            $last->lt(now()->subMinutes(3)) => ['name' => 'Scheduler', 'status' => 'fail', 'detail' => 'Last ran '.$ago($last).'. It should run every minute: reminders, SLA checks and campaigns are not being started.'],
            default => ['name' => 'Scheduler', 'status' => 'ok', 'detail' => 'Last ran '.$ago($last).'.'],
        };

        foreach (self::JOBS as $key => [$label, $minutes]) {
            $at = $beats->last($key);
            $fresh = $at !== null && $at->gte(now()->subMinutes($minutes));

            // a job that was never seen is not a failure yet (it may simply not be installed or the scheduler never ran)
            $out[] = ['name' => $label, 'status' => $at === null ? 'warn' : ($fresh ? 'ok' : 'fail'), 'detail' => $at === null ? 'Not seen yet.' : 'Last ran '.$ago($at).'.'];
        }

        // ---- queue
        $connection = (string) config('queue.default');
        $queue = ['name' => 'Queue', 'status' => 'ok', 'detail' => "Connection “{$connection}”."];

        try {
            if ($connection === 'sync') {
                $queue = ['name' => 'Queue', 'status' => 'warn', 'detail' => 'Jobs run at once inside the web request (“sync”). Fine on a developer machine; on a server use “database” or “redis” with a worker (`php artisan queue:work`), or a slow e-mail slows the page down.'];
            } elseif ($connection === 'database' && Schema::hasTable('jobs')) {
                $waiting = DB::table('jobs')->count();
                $oldest = DB::table('jobs')->min('created_at');
                $age = $oldest === null ? 0 : max(0, now()->getTimestamp() - (int) $oldest);
                $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
                $queue = [
                    'name' => 'Queue', 'status' => $failed > 0 || $age > 600 ? ($age > 600 ? 'fail' : 'warn') : 'ok',
                    'detail' => "Connection “database”: {$waiting} waiting".($waiting > 0 ? ', the oldest for '.max(1, (int) round($age / 60)).' minute(s)' : '').", {$failed} failed."
                        .($age > 600 ? ' Nothing has picked them up for over 10 minutes: is `php artisan queue:work` running?' : ''),
                ];
            }
        } catch (Throwable) {
            $queue = ['name' => 'Queue', 'status' => 'fail', 'detail' => 'The queue could not be read.'];
        }
        $out[] = $queue;

        // ---- mail
        $mailer = (string) config('mail.default');
        $out[] = in_array($mailer, ['log', 'array'], true)
            ? ['name' => 'E-mail', 'status' => 'warn', 'detail' => "The mailer is “{$mailer}”: messages are written down, not sent. Set a real transport (MAIL_MAILER, MAIL_HOST…) before relying on e-mail."]
            : ['name' => 'E-mail', 'status' => 'ok', 'detail' => "The mailer is “{$mailer}”. Whether a message reaches the inbox is up to that provider; this only shows it is configured."];

        // ---- storage
        try {
            $probe = 'health/'.bin2hex(random_bytes(6));
            Storage::disk('local')->put($probe, 'ok');
            Storage::disk('local')->delete($probe);
            $out[] = ['name' => 'Private file storage', 'status' => 'ok', 'detail' => 'Files can be written (exports, task files).'];
        } catch (Throwable) {
            $out[] = ['name' => 'Private file storage', 'status' => 'fail', 'detail' => 'The private disk could not be written to. Exports and attachments will fail.'];
        }

        // ---- cache
        $store = (string) config('cache.default');
        $out[] = in_array($store, ['array', 'file'], true)
            ? ['name' => 'Cache', 'status' => 'warn', 'detail' => "The cache is “{$store}”: the scheduler and the web server do not share it, so these heartbeats and locks across processes cannot be relied on. Use “database” or “redis”."]
            : ['name' => 'Cache', 'status' => $this->cacheWorks() ? 'ok' : 'fail', 'detail' => $this->cacheWorks() ? "Store “{$store}”." : "Store “{$store}” could not be written."];

        // ---- database
        try {
            DB::select('select 1');
            $out[] = ['name' => 'Database', 'status' => 'ok', 'detail' => 'Connected.'];
        } catch (Throwable) {
            $out[] = ['name' => 'Database', 'status' => 'fail', 'detail' => 'The database could not be reached.'];
        }

        return $out;
    }

    private function cacheWorks(): bool
    {
        try {
            Cache::put('health:probe', 1, 10);

            return Cache::get('health:probe') === 1;
        } catch (Throwable) {
            return false;
        }
    }
}

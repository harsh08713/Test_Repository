<?php

namespace App\Modules\Workspace;

use App\Modules\Workspace\Console\CheckSla;
use App\Modules\Workspace\Console\SendTaskReminders;
use App\Modules\Workspace\Models\Holiday;
use App\Modules\Workspace\Models\SlaBreach;
use App\Modules\Workspace\Models\SlaClock;
use App\Modules\Workspace\Models\SlaPolicy;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Models\TaskAssignment;
use App\Modules\Workspace\Services\SlaPolicies;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Customer 360, tasks and SLAs (Phase 14): the working day around the records the other modules keep. */
class WorkspaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SlaPolicies::class);
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'task' => Task::class,
            'task_assignment' => TaskAssignment::class,
            'sla_policy' => SlaPolicy::class,
            'holiday' => Holiday::class,
            'sla_clock' => SlaClock::class,
            'sla_breach' => SlaBreach::class,
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([CheckSla::class, SendTaskReminders::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Every five minutes, never two runs at once; each reminder and each breach is also claimed atomically.
            $schedule->command('sla:check')->everyFiveMinutes()->withoutOverlapping();
            $schedule->command('tasks:remind')->everyFiveMinutes()->withoutOverlapping();
        });

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}

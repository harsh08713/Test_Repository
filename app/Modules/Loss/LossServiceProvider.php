<?php

namespace App\Modules\Loss;

use App\Modules\Loss\Console\EscalateLossCommand;
use App\Modules\Loss\Console\RadarSweepCommand;
use App\Modules\Loss\Console\SavingsRecurrenceCommand;
use App\Modules\Loss\Models\CorrectiveAction;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Models\RadarFinding;
use App\Modules\Loss\Models\SavingsMeasurement;
use App\Modules\Loss\Services\LossWorkflowService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Loss management (Loss Intelligence L4): incidents, impact figures, workflow, root cause, corrective and preventive actions, escalation. */
class LossServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // It remembers each template version's workflow for the request: lists ask it once per row.
        $this->app->singleton(LossWorkflowService::class);
    }

    public function boot(): void
    {
        Relation::enforceMorphMap(['loss_incident' => LossIncident::class, 'corrective_action' => CorrectiveAction::class, 'radar_finding' => RadarFinding::class, 'savings_measurement' => SavingsMeasurement::class]);

        if ($this->app->runningInConsole()) {
            $this->commands([EscalateLossCommand::class, RadarSweepCommand::class, SavingsRecurrenceCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Each escalation is claimed atomically, so overlapping runs never tell anybody twice.
            $schedule->command('loss:escalate')->hourly()->withoutOverlapping();
            // Proposals only; a repeat run refreshes findings (unique per detector and source record), it never makes more.
            $schedule->command('radar:sweep')->dailyAt('02:30')->withoutOverlapping();
            // A recurrence is claimed by a conditional UPDATE, so a repeat run flags nothing twice.
            $schedule->command('savings:check-recurrence')->dailyAt('03:00')->withoutOverlapping();
        });

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}

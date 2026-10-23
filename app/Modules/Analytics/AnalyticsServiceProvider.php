<?php

namespace App\Modules\Analytics;

use App\Modules\Analytics\Console\SnapshotCommand;
use App\Modules\Analytics\Http\Livewire\SearchBox;
use App\Modules\Analytics\Models\KpiSnapshot;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/** The executive dashboard, the six analytics pages, KPI snapshots and the header search (Loss Intelligence L6). */
class AnalyticsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap(['kpi_snapshot' => KpiSnapshot::class]);
        Livewire::component('analytics.search-box', SearchBox::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SnapshotCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Once a day, shortly after midnight; running it again rewrites the same rows.
            $schedule->command('analytics:snapshots')->dailyAt('00:20')->withoutOverlapping();
        });

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}

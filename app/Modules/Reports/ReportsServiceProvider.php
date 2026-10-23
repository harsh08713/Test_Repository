<?php

namespace App\Modules\Reports;

use App\Modules\Reports\Console\ExpireReportExports;
use App\Modules\Reports\Console\SendScheduledReports;
use App\Modules\Reports\Http\Livewire\SalesDashboardPanel;
use App\Modules\Reports\Models\ReportExport;
use App\Modules\Reports\Services\ReportCatalog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/** Reports and the sales dashboard (Phase 13): read-only views over the other modules’ data, with queued Excel/PDF exports. */
class ReportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportCatalog::class);
    }

    public function boot(): void
    {
        Relation::enforceMorphMap(['report_export' => ReportExport::class]);
        Livewire::component('reports.sales-dashboard', SalesDashboardPanel::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireReportExports::class, SendScheduledReports::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('reports:expire-exports')->dailyAt('03:30')->withoutOverlapping();
            $schedule->command('reports:send-scheduled')->everyFifteenMinutes()->withoutOverlapping();
        });

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}

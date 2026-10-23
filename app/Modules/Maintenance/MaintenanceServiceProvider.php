<?php

namespace App\Modules\Maintenance;

use App\Modules\Maintenance\Console\GenerateMaintenanceOrders;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Models\MaintenanceLabour;
use App\Modules\Maintenance\Models\MaintenanceOrder;
use App\Modules\Maintenance\Models\MaintenancePart;
use App\Modules\Maintenance\Models\MaintenancePlan;
use App\Modules\Maintenance\Models\MaintenanceTask;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Maintenance (M1): assets, preventive schedules, work orders with tasks, spare parts from stock, hours and downtime. */
class MaintenanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'asset' => Asset::class, 'maintenance_plan' => MaintenancePlan::class, 'maintenance_order' => MaintenanceOrder::class, 'maintenance_task' => MaintenanceTask::class,
            'maintenance_part' => MaintenancePart::class, 'maintenance_labour' => MaintenanceLabour::class,
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateMaintenanceOrders::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('maintenance:generate-orders')->dailyAt('05:30')->withoutOverlapping();
        });

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}

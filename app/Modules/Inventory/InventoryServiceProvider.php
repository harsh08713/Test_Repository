<?php

namespace App\Modules\Inventory;

use App\Modules\Inventory\Console\ExpiryWatchCommand;
use App\Modules\Inventory\Console\RebuildBalancesCommand;
use App\Modules\Inventory\Models\Batch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Inventory (Loss Intelligence L3): the stock ledger and balances, batches, expiry watch, manual stock entries. */
class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap(['batch' => Batch::class]);

        if ($this->app->runningInConsole()) {
            $this->commands([ExpiryWatchCommand::class, RebuildBalancesCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Each warning is also claimed atomically, so two runs can never tell anybody twice.
            $schedule->command('stock:expiry-watch')->dailyAt('06:30')->withoutOverlapping();
        });

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}

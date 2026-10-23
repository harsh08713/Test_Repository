<?php

namespace App\Modules\Procurement;

use App\Modules\Procurement\Console\PostGrnsCommand;
use App\Modules\Procurement\Listeners\PostApprovedGrn;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Models\GrnItem;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderItem;
use App\Modules\Shared\Events\DocumentApproved;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Procurement (Loss Intelligence L3): purchase orders, goods receipts with the PO tolerance, posting receipts to stock, reorder suggestions. */
class ProcurementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'purchase_order' => PurchaseOrder::class,
            'purchase_order_item' => PurchaseOrderItem::class,
            'grn' => Grn::class,
            'grn_item' => GrnItem::class,
        ]);

        // An approved goods receipt puts its accepted goods into stock (once).
        Event::listen(DocumentApproved::class, PostApprovedGrn::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PostGrnsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // The retry for an approval whose stock could not be posted at the time; the poster posts each receipt once however often it runs.
            $schedule->command('procurement:post-grns')->everyFifteenMinutes()->withoutOverlapping();
        });

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}

<?php

namespace App\Modules\Sales;

use App\Modules\CRM\Contracts\AchievementSource;
use App\Modules\Sales\Console\ExpireQuotations;
use App\Modules\Sales\Console\GenerateInvoices;
use App\Modules\Sales\Listeners\ProcessApprovedSalesOrder;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\QuotationDispatch;
use App\Modules\Sales\Models\QuotationItem;
use App\Modules\Sales\Models\QuotationStateEntry;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Models\SalesOrderStateEntry;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use App\Modules\Sales\Services\SalesOrderAchievement;
use App\Modules\Shared\Events\DocumentApproved;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Sales module: quotations (Phase 11), sales orders and the invoices made from them (Phase 12). */
class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Targets are achieved by approved sales orders (the CRM module ships a placeholder until orders exist).
        $this->app->bind(AchievementSource::class, SalesOrderAchievement::class);
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'quotation' => Quotation::class,
            'quotation_item' => QuotationItem::class,
            'quotation_state_entry' => QuotationStateEntry::class,
            'quotation_dispatch' => QuotationDispatch::class,
            'sales_order' => SalesOrder::class,
            'sales_order_item' => SalesOrderItem::class,
            'sales_order_state_entry' => SalesOrderStateEntry::class,
            'sales_invoice' => SalesInvoice::class,
            'sales_invoice_item' => SalesInvoiceItem::class,
            'sales_return' => SalesReturn::class,
            'sales_return_item' => SalesReturnItem::class,
        ]);

        // An approved sales order is invoiced (once), and wins the deal it came from.
        Event::listen(DocumentApproved::class, ProcessApprovedSalesOrder::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireQuotations::class, GenerateInvoices::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Validity: once a day, early in the morning, never two runs at once (each warning is also claimed atomically).
            $schedule->command('sales:quotation-expiry')->dailyAt('06:00')->withoutOverlapping();

            // The retry for an approval whose invoice could not be made at the time; the generator makes one invoice per order however often it runs.
            $schedule->command('sales:generate-invoices')->everyFifteenMinutes()->withoutOverlapping();
        });

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}

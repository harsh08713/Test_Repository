<?php

namespace App\Modules\Sales\Listeners;

use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\InvoiceGenerator;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Shared\Events\DocumentApproved;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When a sales order is approved (DocumentApproved, fired after the approval has been committed): make its invoice, and win the deal it came from.
 *
 * Neither failure can undo the approval, and neither is lost: a failed invoice is recorded on the order (`invoice_error`) and retried by
 * `sales:generate-invoices`; the invoice generator is safe to ask any number of times, so a replayed event makes no second invoice.
 */
class ProcessApprovedSalesOrder
{
    public function __construct(private readonly InvoiceGenerator $invoices, private readonly SalesOrderService $orders) {}

    public function handle(DocumentApproved $event): void
    {
        if (! $event->document instanceof SalesOrder) {
            return;
        }

        $order = $event->document;

        try {
            $this->invoices->generateFor($order);
        } catch (Throwable $e) {
            Log::error('The invoice for an approved sales order could not be made; it will be retried.', ['sales_order' => $order->getKey(), 'error' => $e->getMessage()]);
        }

        try {
            $this->orders->sourceWon($order->fresh() ?? $order, $event->approver);
        } catch (Throwable $e) {
            Log::warning('A sales order was approved, but the deal it came from could not be marked won.', ['sales_order' => $order->getKey(), 'error' => $e->getMessage()]);
        }
    }
}

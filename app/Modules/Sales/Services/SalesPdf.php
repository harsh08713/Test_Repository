<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Shared\Models\Company;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

/**
 * The PDFs of the documents after the quotation: the order acknowledgment (what the customer is told has been ordered, at which prices and for
 * which date) and the invoice. Both are rendered from the stored document only — never from anything typed — with remote resources switched
 * off, and every value is escaped by Blade.
 */
final class SalesPdf
{
    public function order(SalesOrder $order): string
    {
        $order->load(['items.unit:id,code,name', 'location', 'placeOfSupply:id,name,gst_code', 'priceList:id,name', 'owner:id,name']);

        return $this->render('sales.order-pdf', ['doc' => $order, 'company' => Company::withoutGlobalScopes()->find($order->company_id), 'owner' => $order->owner]);
    }

    public function invoice(SalesInvoice $invoice): string
    {
        $invoice->load(['items.unit:id,code,name', 'location', 'placeOfSupply:id,name,gst_code', 'priceList:id,name', 'owner:id,name', 'order:id,so_number,order_date']);

        return $this->render('sales.invoice-pdf', ['doc' => $invoice, 'company' => Company::withoutGlobalScopes()->find($invoice->company_id), 'owner' => $invoice->owner]);
    }

    public function orderFilename(SalesOrder $order): string
    {
        return Str::slug(str_replace('/', '-', $order->so_number), '-').'-acknowledgment.pdf';
    }

    public function invoiceFilename(SalesInvoice $invoice): string
    {
        return Str::slug(str_replace('/', '-', $invoice->invoice_number), '-').'.pdf';
    }

    /** @param  array<string, mixed>  $data */
    private function render(string $view, array $data): string
    {
        return Pdf::loadView($view, $data)->setPaper('a4')->setOption('isRemoteEnabled', false)->setOption('defaultFont', 'DejaVu Sans')->output();
    }
}

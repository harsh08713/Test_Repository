<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Downloads the invoice PDF, for whoever may open the invoice. The invoice is found through the tenant scopes, so another company's id is a 404. */
class SalesInvoicePdfController
{
    public function __invoke(Request $request, int $invoice, SalesPdf $pdf): Response
    {
        $model = SalesInvoice::query()->findOrFail($invoice);

        Gate::authorize('download', $model);

        return response($pdf->invoice($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->invoiceFilename($model).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

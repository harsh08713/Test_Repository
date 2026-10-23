<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Downloads the order acknowledgment, for whoever may open the order. The order is found through the tenant scopes, so another company's id is a 404. */
class SalesOrderPdfController
{
    public function __invoke(Request $request, int $order, SalesPdf $pdf): Response
    {
        $model = SalesOrder::query()->findOrFail($order);

        Gate::authorize('download', $model);

        return response($pdf->order($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->orderFilename($model).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Services\QuotationDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Downloads the PDF of one revision, for whoever may open it. The quotation is found through the tenant scopes, so another company's id is a 404. */
class QuotationPdfController
{
    public function __invoke(Request $request, int $quotation, QuotationDelivery $delivery): Response
    {
        $model = Quotation::query()->findOrFail($quotation);

        Gate::authorize('download', $model);

        return response($delivery->pdf($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$delivery->filename($model).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

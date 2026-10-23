<?php

namespace App\Modules\Masters\Http\Controllers;

use App\Modules\Masters\Exceptions\RateNotFoundException;
use App\Modules\Masters\Models\Rate;
use App\Modules\Masters\Services\RateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Rate look-up for quotation and order screens:
 * `GET /masters/rates/lookup?item=1&location=2&unit=3&date=2026-10-12&price_list=4`.
 *
 * Returns the one approved, active rate that applies, or a 422 that says why there is none. The company is never a
 * parameter and the branch must be one the caller works in. The caller needs permission to view rates.
 */
class RateLookupController
{
    public function __invoke(Request $request, RateService $rates): JsonResponse
    {
        Gate::authorize('viewAny', Rate::class);

        $data = $request->validate([
            'item' => ['required', 'integer', 'min:1'],
            'location' => ['required', 'integer', 'min:1'],
            'unit' => ['required', 'integer', 'min:1'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'price_list' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $quote = $rates->getRate(
                (int) $data['item'], (int) $data['location'], (int) $data['unit'],
                $data['date'] ?? null, isset($data['price_list']) ? (int) $data['price_list'] : null,
            );
        } catch (RateNotFoundException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason->value], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'invalid_date'], 422);
        }

        return response()->json(['data' => $quote->toArray()]);
    }
}

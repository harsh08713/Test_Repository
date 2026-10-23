<?php

namespace App\Modules\Masters\Http\Controllers;

use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Services\ItemLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Item look-up for pickers: `GET /masters/items/lookup?q=bolt&limit=20`.
 * Only approved, active items of the user's active company come back, with their units; searching matches code,
 * name, aliases and HSN. The caller needs permission to view items; the company is never a parameter.
 */
class ItemLookupController
{
    public function __invoke(Request $request, ItemLookup $lookup): JsonResponse
    {
        Gate::authorize('viewAny', Item::class);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.ItemLookup::MAX_LIMIT],
        ]);

        $items = $lookup->search((string) ($data['q'] ?? ''), (int) ($data['limit'] ?? ItemLookup::DEFAULT_LIMIT));

        return response()->json(['data' => $items->map(fn (Item $i) => $lookup->present($i))->values()]);
    }
}

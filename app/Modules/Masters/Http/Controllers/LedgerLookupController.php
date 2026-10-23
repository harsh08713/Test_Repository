<?php

namespace App\Modules\Masters\Http\Controllers;

use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Masters\Services\LedgerLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Ledger look-up for pickers: `GET /masters/ledgers/lookup?type=customer&q=shree&limit=20`.
 * Only approved, active ledgers of the user's active company come back, and never bank details; the caller needs
 * permission to view ledgers. The company is the server's choice, never a parameter.
 */
class LedgerLookupController
{
    public function __invoke(Request $request, LedgerLookup $lookup): JsonResponse
    {
        Gate::authorize('viewAny', Ledger::class);

        $data = $request->validate([
            'type' => ['nullable', 'in:'.implode(',', LedgerType::values())],
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.LedgerLookup::MAX_LIMIT],
        ]);

        $ledgers = $lookup->search(
            isset($data['type']) ? LedgerType::from($data['type']) : null,
            (string) ($data['q'] ?? ''),
            (int) ($data['limit'] ?? LedgerLookup::DEFAULT_LIMIT),
        );

        return response()->json(['data' => $ledgers->map(fn (Ledger $l) => $lookup->present($l))->values()]);
    }
}

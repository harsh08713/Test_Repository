<?php

namespace App\Modules\Masters\Services;

use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Ledger;
use Illuminate\Support\Collection;

/**
 * The one way transactions (and, later, the REST API) find ledgers. It only ever returns **approved, active**
 * ledgers of the current company, so an unapproved, rejected, deactivated or deleted ledger can never be picked
 * or exposed, whatever id a client sends. It never returns bank details.
 */
final class LedgerLookup
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    /**
     * @return Collection<int, Ledger>
     */
    public function search(?LedgerType $type = null, string $term = '', int $limit = self::DEFAULT_LIMIT): Collection
    {
        $term = trim($term);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        return Ledger::query()
            ->usable()
            ->when($type !== null, fn ($q) => $q->ofType($type))
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like)->orWhere('gstin', strtoupper($term)));
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'code', 'name', 'legal_name', 'ledger_type', 'gstin', 'credit_days', 'credit_limit']);
    }

    /** The ledger with this id if — and only if — a transaction may use it. */
    public function usable(int $id, ?LedgerType $type = null): ?Ledger
    {
        return Ledger::query()->usable()->when($type !== null, fn ($q) => $q->ofType($type))->whereKey($id)->first();
    }

    /** @return array{id: int, code: string, name: string, type: string, gstin: string|null, credit_days: int|null, credit_limit: string|null} */
    public function present(Ledger $ledger): array
    {
        return [
            'id' => $ledger->id,
            'code' => $ledger->code,
            'name' => $ledger->name,
            'type' => $ledger->ledger_type->value,
            'gstin' => $ledger->gstin,
            'credit_days' => $ledger->credit_days,
            'credit_limit' => $ledger->credit_limit,
        ];
    }
}

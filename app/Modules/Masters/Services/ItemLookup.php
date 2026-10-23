<?php

namespace App\Modules\Masters\Services;

use App\Modules\Masters\Models\Item;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The one way transactions (and, later, the REST API) find items. It returns only **approved, active** items of
 * the current company, so an item still in Purchase or Inventory, awaiting approval, returned, re-opened or
 * switched off can never be picked or exposed, whatever id a client sends.
 *
 * Search matches the item code, name, any alias and the HSN code.
 */
final class ItemLookup
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    /**
     * @return Collection<int, Item>
     */
    public function search(string $term = '', int $limit = self::DEFAULT_LIMIT): Collection
    {
        $term = trim($term);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        return Item::query()
            ->usable()
            ->with(['baseUnit:id,code,name,decimal_allowed', 'uoms.unit:id,code,name,decimal_allowed'])
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like)->orWhere('hsn_code', 'like', $like)
                    ->orWhereHas('aliases', fn ($a) => $a->where('alias', 'like', $like)));
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * A page of usable items, for a client that keeps its own copy: searched, or — with `$since` — only those that changed at or
     * after that moment, in change order. Still only approved, active items; see unusableSince() for the ones that stopped being.
     *
     * @return LengthAwarePaginator<int, Item>
     */
    public function page(string $term = '', ?CarbonInterface $since = null, int $perPage = self::DEFAULT_LIMIT): LengthAwarePaginator
    {
        $term = trim($term);

        return Item::query()
            ->usable()
            ->with(['baseUnit:id,code,name,decimal_allowed', 'uoms.unit:id,code,name,decimal_allowed'])
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like)->orWhere('hsn_code', 'like', $like)
                    ->orWhereHas('aliases', fn ($a) => $a->where('alias', 'like', $like)));
            })
            ->when($since !== null, fn ($q) => $q->where('updated_at', '>=', $since)->orderBy('updated_at'), fn ($q) => $q->orderBy('name'))
            ->orderBy('id')
            ->paginate(max(1, $perPage));
    }

    /**
     * Ids of items that changed at or after `$since` and that a transaction may **not** use (switched off, re-opened, returned):
     * what a client holding a copy must drop. Only ids — nothing else about an unapproved item leaves the server.
     *
     * @return list<int>
     */
    public function unusableSince(CarbonInterface $since): array
    {
        return Item::withTrashed()
            ->where('updated_at', '>=', $since)
            ->whereNotIn('id', Item::query()->usable()->select('id'))
            ->orderBy('id')
            ->limit(1000)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** The item with this id if — and only if — a transaction may use it. */
    public function usable(int $id): ?Item
    {
        return Item::query()->usable()->with(['baseUnit', 'uoms.unit'])->whereKey($id)->first();
    }

    /** @return array<string, mixed> */
    public function present(Item $item): array
    {
        return [
            'id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'hsn_code' => $item->hsn_code,
            'gst_rate' => $item->gst_rate,
            'po_tolerance_percent' => $item->po_tolerance_percent,
            'base_unit' => ['id' => $item->baseUnit->id, 'code' => $item->baseUnit->code, 'decimal_allowed' => $item->baseUnit->decimal_allowed],
            'units' => $item->uoms->map(fn ($u) => [
                'id' => $u->unit->id, 'code' => $u->unit->code, 'decimal_allowed' => $u->unit->decimal_allowed, 'factor' => $u->factor,
            ])->values()->all(),
        ];
    }
}

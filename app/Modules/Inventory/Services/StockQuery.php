<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Masters\Models\Item;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Questions about stock on hand. What a person sees (`onHand`, `expiryExposure`) runs through the tenant and branch scopes of the balances;
 * the average cost (`averageCost`) is a valuation for a document being made by the system, so it looks at the item's company explicitly and
 * at every branch the document's own branch is not enough for.
 */
final class StockQuery
{
    /** Quantity on hand (base unit, 3 decimals) of an item, in one branch or in all the person works in, optionally of one batch. */
    public function onHand(Item|int $item, ?int $locationId = null, ?int $batchId = null): string
    {
        $total = StockBalance::query()->where('item_id', $item instanceof Item ? $item->getKey() : $item)
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->when($batchId !== null, fn ($q) => $q->where('batch_id', $batchId))
            ->sum('quantity');

        return bcadd((string) $total, '0', 3);
    }

    /**
     * The moving-average cost of one base unit: that of the branch if it has stock, otherwise of the company's stock anywhere; null when there is
     * none (the caller then falls back to the item's standard cost).
     */
    public function averageCost(Item $item, ?int $locationId = null): ?string
    {
        $pool = fn (?int $location) => DB::table('stock_balances')->where('company_id', $item->company_id)->where('item_id', $item->getKey())
            ->where('quantity', '>', 0)->when($location !== null, fn ($q) => $q->where('location_id', $location))
            ->selectRaw('sum(quantity) as quantity, sum(value) as value')->first();

        foreach ($locationId !== null ? [$locationId, null] : [null] as $location) {
            $row = $pool($location);

            if ($row !== null && $row->quantity !== null && bccomp((string) $row->quantity, '0', 3) > 0) {
                return bcadd(bcdiv((string) $row->value, (string) $row->quantity, 10), '0.00005', 4);
            }
        }

        return null;
    }

    /**
     * Value of the stock whose batch expires within $days (or has expired already): FORMULAS.md `expiry_exposure`.
     *
     * @return numeric-string
     */
    public function expiryExposure(int $days, ?int $locationId = null, ?CarbonInterface $today = null, ?int $industryId = null): string
    {
        $limit = ($today ?? now())->copy()->addDays($days)->toDateString();

        $total = StockBalance::query()->where('quantity', '>', 0)
            ->whereIn('batch_id', fn ($q) => $q->select('id')->from('batches')->whereNotNull('expiry_date')->where('expiry_date', '<=', $limit)->whereNull('deleted_at'))
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->when($industryId !== null, fn ($q) => $q->whereIn('item_id', fn ($i) => $i->select('id')->from('items')->where('industry_template_id', $industryId)->whereNull('deleted_at')))
            ->sum('value');

        return bcadd((string) $total, '0', 2);
    }
}

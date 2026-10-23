<?php

namespace App\Modules\Loss\Radar;

use App\Modules\Procurement\Models\GrnItem;
use Carbon\CarbonInterface;

/** Goods rejected on an approved receipt in the look-back period: an exposure until the supplier's credit or replacement is settled. */
final class RejectedReceiptDetector implements Detector
{
    public function key(): string
    {
        return 'rejected_receipt';
    }

    public function method(): string
    {
        return 'Goods rejected on an approved goods receipt in the look-back period, at the purchase order price (an exposure until the supplier settles it).';
    }

    public function candidates(CarbonInterface $now, int $lookbackDays): iterable
    {
        $ids = GrnItem::query()->where('rejected_quantity', '>', 0)
            ->whereHas('grn', fn ($q) => $q->where('approval_status', 'approved')->where('grn_date', '>=', $now->copy()->subDays($lookbackDays)->toDateString()))
            ->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            yield ['grn_item', (int) $id];
        }
    }
}

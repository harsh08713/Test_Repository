<?php

namespace App\Modules\Loss\Radar;

use App\Modules\Inventory\Models\Batch;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** A batch past its expiry date (or marked expired) that still has stock on hand: the stock can be sold to nobody. */
final class ExpiredStockDetector implements Detector
{
    public function key(): string
    {
        return 'expired_stock';
    }

    public function method(): string
    {
        return 'A batch past its expiry date that still has stock on hand, valued at its average cost.';
    }

    public function candidates(CarbonInterface $now, int $lookbackDays): iterable
    {
        $ids = Batch::query()
            ->where(fn ($q) => $q->where('status', 'expired')->orWhere('expiry_date', '<', $now->toDateString()))
            ->whereIn('id', DB::table('stock_balances')->where('quantity', '>', 0)->whereNotNull('batch_id')->select('batch_id'))
            ->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            yield ['batch', (int) $id];
        }
    }
}

<?php

namespace App\Modules\Loss\Radar;

use App\Modules\Inventory\Models\StockMovement;
use Carbon\CarbonInterface;

/** Stock scrapped or written off as lost in the look-back period, valued at the average cost it left at. */
final class ScrapDetector implements Detector
{
    public function key(): string
    {
        return 'scrap';
    }

    public function method(): string
    {
        return 'Stock scrapped or adjusted out as lost in the look-back period, valued at the moving-average cost it left at.';
    }

    public function candidates(CarbonInterface $now, int $lookbackDays): iterable
    {
        $ids = StockMovement::query()->whereIn('movement_type', [StockMovement::SCRAP, StockMovement::ADJUSTMENT_OUT])
            ->where('moved_at', '>=', $now->copy()->subDays($lookbackDays)->startOfDay())->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            yield ['stock_movement', (int) $id];
        }
    }
}

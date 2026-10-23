<?php

namespace App\Modules\Loss\Radar;

use App\Modules\Production\Models\ProductionOrder;
use Carbon\CarbonInterface;

/** A production order completed in the look-back period (LossSources then decides whether it used more material than the standard for what it made). */
final class ProductionVarianceDetector implements Detector
{
    public function key(): string
    {
        return 'production_variance';
    }

    public function method(): string
    {
        return 'A production order completed in the look-back period that used more material than the standard for the output it made, valued at the average issue cost of each component.';
    }

    public function candidates(CarbonInterface $now, int $lookbackDays): iterable
    {
        $ids = ProductionOrder::query()->where('state', 'completed')->where('completed_at', '>=', $now->copy()->subDays($lookbackDays)->startOfDay())->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            yield ['production_order', (int) $id];
        }
    }
}

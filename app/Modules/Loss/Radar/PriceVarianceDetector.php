<?php

namespace App\Modules\Loss\Radar;

use App\Modules\Procurement\Models\GrnItem;
use App\Modules\Shared\Services\SettingsService;
use Carbon\CarbonInterface;

/**
 * Receipt lines in the look-back period whose price per base unit is more than `radar.price_variance_percent` above the item’s current standard cost.
 * Items with no standard cost are left out (there is nothing to compare with).
 */
final class PriceVarianceDetector implements Detector
{
    public function __construct(private readonly SettingsService $settings) {}

    public function key(): string
    {
        return 'price_variance';
    }

    public function method(): string
    {
        return 'Goods received in the look-back period at a price more than '.max(0, (int) $this->settings->get('radar.price_variance_percent')).'% above the item’s current standard cost.';
    }

    public function candidates(CarbonInterface $now, int $lookbackDays): iterable
    {
        $percent = (string) max(0, (int) $this->settings->get('radar.price_variance_percent'));
        $factor = bcadd('1', bcdiv($percent, '100', 6), 6);

        $ids = GrnItem::query()->where('base_accepted', '>', 0)
            ->whereHas('grn', fn ($q) => $q->where('approval_status', 'approved')->where('grn_date', '>=', $now->copy()->subDays($lookbackDays)->toDateString()))
            ->whereHas('item', fn ($q) => $q->whereNotNull('standard_cost')->where('standard_cost', '>', 0)->whereRaw('grn_items.unit_cost > items.standard_cost * ?', [$factor]))
            ->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            yield ['grn_price', (int) $id];
        }
    }
}

<?php

namespace App\Modules\Masters\Services;

use App\Modules\Inventory\Services\StockQuery;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Unit;
use App\Modules\Shared\Services\SettingsService;

/**
 * The cost of one unit of an item, to be copied onto a document line when it is made (FORMULAS.md `line.cost_amount`).
 *
 * The company setting `costing.method` decides: **moving average** uses the average cost of the stock the branch holds (else of the company's stock
 * anywhere) and falls back to the item's standard cost when there is no stock to average; **standard** always uses the item's standard cost. The
 * returned source says what answered, so an invoice line records which. An item with neither has no answer — null, never zero.
 */
final class CostResolver
{
    public const STANDARD = 'standard';

    public const MOVING_AVERAGE = 'moving_average';

    public function __construct(
        private readonly UomConverter $units,
        private readonly StockQuery $stock,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Cost of one $unit of $item (4 decimals), and where it came from; null when the item has no cost.
     *
     * @return array{unit_cost: string, source: string}|null
     */
    public function unitCost(Item $item, Unit $unit, ?int $locationId = null): ?array
    {
        $perBase = null;
        $source = null;

        if ($this->settings->get('costing.method', (int) $item->company_id) === self::MOVING_AVERAGE) {
            $perBase = $this->stock->averageCost($item, $locationId);
            $source = self::MOVING_AVERAGE;
        }

        if ($perBase === null && $item->standard_cost !== null) {
            $perBase = (string) $item->standard_cost;
            $source = self::STANDARD;
        }

        if ($perBase === null || $source === null) {
            return null;
        }

        return ['unit_cost' => $this->round4(bcmul($perBase, $this->units->factor($item, $unit), 10)), 'source' => $source];
    }

    /** Round half up to 4 decimals. */
    private function round4(string $value): string
    {
        return bcadd($value, '0.00005', 4);
    }
}

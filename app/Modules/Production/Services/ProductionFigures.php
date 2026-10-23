<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * What has happened on a production order, **worked out** from its entries and never stored: issued per component, produced and rejected, yield, the material value that went in, and
 * how much more may still be issued or produced (the planned amount plus the company's tolerance). Production screens and reports use this and nothing else.
 */
final class ProductionFigures
{
    public function __construct(private readonly SettingsService $settings) {}

    /** The most a component may be over-issued by, as a percent of what it requires. */
    public function overIssuePercent(int $companyId): int
    {
        return max(0, (int) $this->settings->get('production.over_issue_percent', $companyId));
    }

    public function overProductionPercent(int $companyId): int
    {
        return max(0, (int) $this->settings->get('production.over_production_percent', $companyId));
    }

    /**
     * @return array{
     *     components: list<array{id: int, line_no: int, item_id: int, item_name: string, required: string, issued: string, allowed_more: string, standard_for_actual: string, variance: string}>,
     *     planned: string, good: string, rejected: string, yield: string|null, still_to_make: string, allowed_more_output: string, material_value: string, unit_cost: string|null
     * }
     */
    public function forOrder(ProductionOrder $order): array
    {
        $issued = DB::table('production_issues')->where('production_order_id', $order->id)->groupBy('production_component_id')->selectRaw('production_component_id as id, sum(quantity) as quantity')->pluck('quantity', 'id');
        $outputs = DB::table('production_outputs')->where('production_order_id', $order->id)->selectRaw('coalesce(sum(good_quantity), 0) as good, coalesce(sum(rejected_quantity), 0) as rejected')->first();
        $good = bcadd((string) ($outputs->good ?? '0'), '0', 3);
        $rejected = bcadd((string) ($outputs->rejected ?? '0'), '0', 3);
        $made = bcadd($good, $rejected, 3);
        $planned = bcadd((string) $order->planned_quantity, '0', 3);
        $overIssue = $this->overIssuePercent((int) $order->company_id);
        $overOutput = $this->overProductionPercent((int) $order->company_id);

        $components = [];

        foreach ($order->components()->get() as $c) {
            $in = bcadd((string) ($issued[$c->id] ?? '0'), '0', 3);
            $required = bcadd((string) $c->required_quantity, '0', 3);
            $cap = bcadd(bcmul($required, bcadd('1', bcdiv((string) $overIssue, '100', 6), 6), 6), '0', 3);
            $standard = bccomp($planned, '0', 3) > 0 ? bcadd(bcdiv(bcmul($required, $made, 6), $planned, 6), '0.0005', 3) : '0.000';

            $components[] = [
                'id' => (int) $c->id, 'line_no' => (int) $c->line_no, 'item_id' => (int) $c->item_id, 'item_name' => $c->item_name, 'required' => $required, 'issued' => $in,
                'allowed_more' => bccomp($cap, $in, 3) > 0 ? bcsub($cap, $in, 3) : '0.000', 'standard_for_actual' => $standard, 'variance' => bcsub($in, $standard, 3),
            ];
        }

        $capOutput = bcadd(bcmul($planned, bcadd('1', bcdiv((string) $overOutput, '100', 6), 6), 6), '0', 3);
        $value = $this->materialValue($order);

        return [
            'components' => $components, 'planned' => $planned, 'good' => $good, 'rejected' => $rejected,
            'yield' => bccomp($made, '0', 3) > 0 ? bcadd(bcdiv(bcmul($good, '100', 6), $made, 6), '0.005', 2) : null,
            'still_to_make' => bccomp($planned, $good, 3) > 0 ? bcsub($planned, $good, 3) : '0.000',
            'allowed_more_output' => bccomp($capOutput, $made, 3) > 0 ? bcsub($capOutput, $made, 3) : '0.000',
            'material_value' => $value, 'unit_cost' => bccomp($good, '0', 3) > 0 ? bcadd(bcdiv($value, $good, 8), '0.00005', 4) : null,
        ];
    }

    /** The value of the material taken out of stock for the order, at the moving average when it was issued (what the stock ledger says). */
    public function materialValue(ProductionOrder $order): string
    {
        $sum = DB::table('stock_movements')->where('company_id', $order->company_id)->where('source_type', 'production_issue')->where('movement_type', 'issue')
            ->whereIn('source_id', DB::table('production_issues')->where('production_order_id', $order->id)->select('id'))->sum('value');

        return bcadd(ltrim((string) $sum, '-'), '0', 2);
    }
}

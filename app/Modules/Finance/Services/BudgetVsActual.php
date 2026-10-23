<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Budget;
use App\Modules\Loss\Models\LossIncident;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Budget against actual loss, per cost centre and loss category, for a run of months — FORMULAS.md "budget variance". The only place it is worked out.
 *
 *   budget    Σ **approved** budgets of the cost centre (and category) for the months in the period
 *   actual    Σ validated actual figures (current, not superseded) of incidents tagged with the cost centre that occurred in the period, are not
 *             duplicates and not rejected — of that category; for a budget with no category, of every category of the cost centre
 *   variance  actual − budget (positive = over budget)
 *   variance %  variance ÷ budget × 100, 2 decimals half up away from zero; null when the budget is 0
 *
 * A category with actual loss but no budget shows with a budget of 0 (so it is never hidden). The cost centre is flat: a parent does not add up its
 * children. Everything is limited to the company and the branches the person works in (incidents) through the tenant scopes.
 */
final class BudgetVsActual
{
    /**
     * @return list<array{cost_center_id: int, cost_center: string, category_id: int|null, category: string, budget: string, actual: string, variance: string, variance_pct: string|null, status: string}>
     */
    public function forPeriod(CarbonInterface $from, CarbonInterface $to, ?int $costCenterId = null): array
    {
        $first = $from->copy()->startOfMonth()->toDateString();
        $last = $to->copy()->startOfMonth()->toDateString();

        $budgets = Budget::query()->where('approval_status', 'approved')->whereBetween('period_start', [$first, $last])
            ->when($costCenterId !== null, fn ($q) => $q->where('cost_center_id', $costCenterId))
            ->groupBy('cost_center_id', 'category_key')->selectRaw('cost_center_id, category_key, sum(amount) as amount')->get();

        $incidents = LossIncident::query()->whereNotNull('cost_center_id')->whereNull('primary_incident_id')->where('state', '<>', 'rejected')
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($costCenterId !== null, fn ($q) => $q->where('cost_center_id', $costCenterId))->select('id');

        $actual = DB::table('loss_impact_lines as l')->join('loss_incidents as i', 'i.id', '=', 'l.loss_incident_id')
            ->whereIn('l.loss_incident_id', $incidents)->where('l.impact_type', 'actual')->where('l.validation_state', 'validated')->whereNull('l.superseded_at')
            ->groupBy('i.cost_center_id', 'i.loss_category_id')->selectRaw('i.cost_center_id, i.loss_category_id, sum(l.amount) as amount')->get();

        // (cost centre, category) => [budget, actual]; category 0 = the budget with no category (compared with the centre's total)
        $rows = [];
        $centreTotal = [];

        foreach ($actual as $a) {
            $rows[$a->cost_center_id][(int) $a->loss_category_id]['actual'] = (string) $a->amount;
            $centreTotal[$a->cost_center_id] = bcadd($centreTotal[$a->cost_center_id] ?? '0', (string) $a->amount, 2);
        }

        foreach ($budgets as $b) {
            $rows[$b->cost_center_id][(int) $b->category_key]['budget'] = (string) $b->amount;
        }

        $names = DB::table('cost_centers')->whereIn('id', array_keys($rows))->pluck('name', 'id');
        $categories = DB::table('loss_categories')->pluck('name', 'id');
        $out = [];

        foreach ($rows as $centre => $byCategory) {
            if (isset($byCategory[0])) {
                $byCategory[0]['actual'] = $centreTotal[$centre] ?? '0';   // the all-categories budget meets the centre's whole actual
            }

            foreach ($byCategory as $category => $figures) {
                $budget = bcadd($figures['budget'] ?? '0', '0', 2);
                $spent = bcadd($figures['actual'] ?? '0', '0', 2);
                $variance = bcsub($spent, $budget, 2);

                $out[] = [
                    'cost_center_id' => (int) $centre, 'cost_center' => (string) ($names[$centre] ?? '—'), 'category_id' => $category === 0 ? null : $category,
                    'category' => $category === 0 ? 'All categories' : (string) ($categories[$category] ?? '—'), 'budget' => $budget, 'actual' => $spent,
                    'variance' => $variance, 'variance_pct' => bccomp($budget, '0', 2) === 0 ? null : $this->percent($variance, $budget),
                    'status' => bccomp($budget, '0', 2) === 0 ? 'unbudgeted' : (bccomp($variance, '0', 2) > 0 ? 'over' : 'within'),
                ];
            }
        }

        usort($out, fn (array $x, array $y) => [$x['cost_center'], $x['category_id'] ?? 0] <=> [$y['cost_center'], $y['category_id'] ?? 0]);

        return $out;
    }

    /** variance ÷ budget × 100, 2 decimals, half up away from zero. */
    private function percent(string $variance, string $budget): string
    {
        $value = bcmul(bcdiv($variance, $budget, 8), '100', 6);

        return bcadd($value, bccomp($value, '0', 6) < 0 ? '-0.005' : '0.005', 2);
    }
}

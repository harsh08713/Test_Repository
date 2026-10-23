<?php

namespace App\Modules\Loss\Services;

use App\Modules\Loss\Models\CorrectiveAction;
use App\Modules\Loss\Models\LossImpactLine;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Models\SavingsMeasurement;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The four loss values of FORMULAS.md §3 — **kept apart, never added together** — and the Pareto of loss by category. The only place they are
 * worked out, so the incident list, the dashboard and the reports (Phase L6) cannot disagree.
 *
 *   actual_loss             Σ validated, current *actual* lines of incidents that are not rejected
 *   estimated_exposure      Σ current *exposure* lines not rejected, of incidents that are not rejected
 *   recoverable_opportunity Σ current *opportunity* lines not rejected, of incidents that are not rejected
 *   realised_savings        Σ verified results of corrective actions verified in the period
 *
 * Double counting: lines are summed from the lines table by incident id (never through an incident × line join); a **duplicate** incident (another
 * covers the same source) counts for nothing; a **superseded** line (recalculated) counts for nothing. Losses fall in the period the incident
 * occurred; savings in the period they were verified. Everything runs through the tenant and branch scopes.
 */
final class LossFigures
{
    /**
     * @return array{actual_loss: string, estimated_exposure: string, recoverable_opportunity: string, realised_savings: string, incidents: int, open_actions: int, overdue_actions: int}
     */
    public function forPeriod(CarbonInterface $from, CarbonInterface $to, ?int $templateId = null, ?int $locationId = null): array
    {
        $incidents = $this->incidents($templateId, $locationId)->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        $sum = fn (string $type, bool $validatedOnly) => bcadd((string) LossImpactLine::query()
            ->whereIn('loss_incident_id', (clone $incidents)->select('id'))
            ->where('impact_type', $type)->whereNull('superseded_at')
            ->when($validatedOnly, fn ($q) => $q->where('validation_state', 'validated'), fn ($q) => $q->where('validation_state', '<>', 'rejected'))
            ->sum('amount'), '0', 2);

        $actions = CorrectiveAction::query()->whereIn('loss_incident_id', $this->incidents($templateId, $locationId)->select('id'));

        return [
            'actual_loss' => $sum('actual', true),
            'estimated_exposure' => $sum('exposure', false),
            'recoverable_opportunity' => $sum('opportunity', false),
            'realised_savings' => bcadd((string) (clone $actions)->where('state', 'verified')
                ->whereBetween('verified_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->sum('verified_result_amount'), '0', 2),
            'incidents' => (clone $incidents)->count(),
            'open_actions' => (clone $actions)->whereIn('state', ['open', 'in_progress', 'done'])->count(),
            'overdue_actions' => (clone $actions)->whereIn('state', ['open', 'in_progress'])->where('due_date', '<', now()->toDateString())->count(),
        ];
    }

    /**
     * Actual loss by loss category, largest first, with the number of incidents and the running share — the Pareto.
     *
     * @return list<array{category_id: int, category: string, amount: string, incidents: int, cumulative_pct: string|null}>
     */
    public function pareto(CarbonInterface $from, CarbonInterface $to, ?int $templateId = null, ?int $locationId = null): array
    {
        $ids = $this->incidents($templateId, $locationId)->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->select('id');

        $rows = DB::table('loss_impact_lines as l')->join('loss_incidents as i', 'i.id', '=', 'l.loss_incident_id')->join('loss_categories as c', 'c.id', '=', 'i.loss_category_id')
            ->whereIn('l.loss_incident_id', $ids)->where('l.impact_type', 'actual')->where('l.validation_state', 'validated')->whereNull('l.superseded_at')
            ->groupBy('c.id', 'c.name')->selectRaw('c.id as category_id, c.name as category, sum(l.amount) as amount, count(distinct i.id) as incidents')
            ->orderByDesc('amount')->orderBy('c.name')->get();

        $total = bcadd((string) $rows->sum('amount'), '0', 2);
        $running = '0.00';
        $out = [];

        foreach ($rows as $row) {
            $running = bcadd($running, (string) $row->amount, 2);
            $out[] = [
                'category_id' => (int) $row->category_id, 'category' => (string) $row->category, 'amount' => bcadd((string) $row->amount, '0', 2), 'incidents' => (int) $row->incidents,
                'cumulative_pct' => bccomp($total, '0', 2) === 0 ? null : bcadd(bcmul(bcdiv($running, $total, 8), '100', 6), '0.005', 2),
            ];
        }

        return $out;
    }

    /** @return Builder<LossIncident> incidents that count: not duplicates, not rejected */
    private function incidents(?int $templateId, ?int $locationId, ?int $categoryId = null, ?int $itemId = null): Builder
    {
        return LossIncident::query()->whereNull('primary_incident_id')->where('state', '<>', 'rejected')
            ->when($templateId !== null, fn ($q) => $q->where('industry_template_id', $templateId))
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->when($categoryId !== null, fn ($q) => $q->where('loss_category_id', $categoryId))
            ->when($itemId !== null, fn ($q) => $q->where('item_id', $itemId));
    }

    /**
     * The validated *actual* loss of one loss category (at one branch, and one item if given) over a period — what a savings measurement compares before
     * and after. Counted exactly as in forPeriod(): duplicates, rejected incidents, rejected and superseded lines count for nothing.
     */
    public function actualFor(CarbonInterface $from, CarbonInterface $to, int $categoryId, ?int $locationId, ?int $itemId = null): string
    {
        $incidents = $this->incidents(null, $locationId, $categoryId, $itemId)->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        return bcadd((string) LossImpactLine::query()->whereIn('loss_incident_id', $incidents->select('id'))
            ->where('impact_type', 'actual')->where('validation_state', 'validated')->whereNull('superseded_at')->sum('amount'), '0', 2);
    }

    /**
     * Savings Finance has verified (savings measurements decided in the period) — a different thing from `realised_savings` above (a corrective
     * action's own verified result), and never added to it. `recurred` counts those whose loss has come back.
     *
     * @return array{net_saving: string, count: int, recurred: int}
     */
    public function financeVerifiedSavings(CarbonInterface $from, CarbonInterface $to, ?int $locationId = null): array
    {
        $rows = SavingsMeasurement::query()->where('state', 'verified')->whereBetween('decided_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId));

        return [
            'net_saving' => bcadd((string) (clone $rows)->sum('net_saving'), '0', 2),
            'count' => (clone $rows)->count(),
            'recurred' => (clone $rows)->whereNotNull('recurred_at')->count(),
        ];
    }
}

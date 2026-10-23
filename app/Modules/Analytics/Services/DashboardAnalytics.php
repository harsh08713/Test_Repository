<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Finance\Services\Profitability;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Models\RadarFinding;
use App\Modules\Loss\Services\LossFigures;
use App\Modules\Masters\Models\Item;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every figure the executive dashboard and the analytics pages show, worked out **in one place** so a card, a chart, a table and a JSON answer can never
 * disagree. It owns no new rule: revenue, cost, margin and loss come from `Profitability` and `LossFigures` (which hold the formulas of FORMULAS.md), and
 * this class only slices them by date, industry and branch, compares them with the previous period and shapes them for a screen. Everything runs through
 * the tenant and branch scopes, so a person's dashboard shows only what they are allowed to see.
 *
 * Money is returned as decimal strings, percentages as decimal strings with two places, and a figure that has nothing to divide by is **null** (shown "—"),
 * never zero. Each KPI card carries its own `formula` so the screen can say exactly what the number means.
 */
final class DashboardAnalytics
{
    public function __construct(
        private readonly Profitability $profit,
        private readonly LossFigures $losses,
        private readonly IndustryTemplateService $templates,
    ) {}

    /**
     * The five headline cards.
     *
     * @return array<string, array<string, mixed>>
     */
    public function kpiCards(AnalyticsFilter $f): array
    {
        $now = $this->totals($f);
        $before = $this->totals($f->previous());
        $brands = collect($this->profit->by('brand', $f->from, $f->to, $f->industryId, $f->locationId))->where('key', '!=', 0)->values();
        $top = $brands->first();
        $topBefore = $top === null ? null : collect($this->profit->by('brand', $f->previous()->from, $f->previous()->to, $f->industryId, $f->locationId))->firstWhere('key', $top['key']);
        $products = Item::query()->usable()->when($f->industryId !== null, fn (Builder $q) => $q->where('industry_template_id', $f->industryId))->count();

        return [
            'revenue' => [
                'label' => 'Total revenue', 'value' => $now['net_revenue'], 'previous' => $before['net_revenue'], 'change_pct' => $this->change($now['net_revenue'], $before['net_revenue']),
                'good' => 'up', 'formula' => 'Net revenue = gross sales − discounts − approved returns and credit notes, from issued invoices, before tax.',
            ],
            'losses' => [
                'label' => 'Total losses', 'value' => $now['actual_loss'], 'previous' => $before['actual_loss'], 'change_pct' => $this->change($now['actual_loss'], $before['actual_loss']),
                'good' => 'down', 'formula' => 'Actual loss = validated actual impact figures of loss incidents that occurred in the period (duplicates and rejected incidents excluded). Exposure and opportunity are not included.',
            ],
            'products' => [
                'label' => 'Total products', 'value' => (string) $products, 'previous' => null, 'change_pct' => null, 'good' => 'up',
                'formula' => 'Items that are approved and active'.($f->industryId !== null ? ' in the chosen industry' : '').'. Not limited by the date range.',
            ],
            'brand' => [
                'label' => 'Top performing brand', 'value' => $top['name'] ?? '—', 'sub' => $top === null ? null : $top['net_revenue'], 'previous' => $topBefore['net_revenue'] ?? null,
                'change_pct' => $top === null ? null : $this->change($top['net_revenue'], $topBefore['net_revenue'] ?? '0.00'), 'good' => 'up',
                'formula' => 'The brand with the highest net revenue in the period (items with no brand are not a brand).',
            ],
            'templates' => [
                'label' => 'Active industry templates', 'value' => (string) $this->templates->enabled()->count(), 'previous' => null, 'change_pct' => null, 'good' => 'up',
                'formula' => 'Industry templates your company has switched on.',
            ],
        ];
    }

    /**
     * Revenue and actual loss by day, week or month, with the previous period alongside.
     *
     * @return array{granularity: string, labels: list<string>, revenue: list<string>, loss: list<string>, prior_revenue: list<string>, prior_loss: list<string>, target: null}
     */
    public function trend(AnalyticsFilter $f, ?string $granularity = null): array
    {
        $g = $f->granularity($granularity);
        $keys = $this->bucketKeys($f->from, $f->to, $g);
        $now = $this->series($f, $g);
        $prior = $this->series($f->previous(), $g);
        $priorKeys = $this->bucketKeys($f->previous()->from, $f->previous()->to, $g);

        $pick = fn (array $series, array $keyList, int $i, int $slot) => $series[$keyList[$i] ?? ''][$slot] ?? '0.00';

        $out = ['granularity' => $g, 'labels' => [], 'revenue' => [], 'loss' => [], 'prior_revenue' => [], 'prior_loss' => [], 'target' => null];

        foreach ($keys as $i => $key) {
            $out['labels'][] = $this->label($key, $g);
            $out['revenue'][] = $pick($now, $keys, $i, 0);
            $out['loss'][] = $pick($now, $keys, $i, 1);
            $out['prior_revenue'][] = $pick($prior, $priorKeys, $i, 0);
            $out['prior_loss'][] = $pick($prior, $priorKeys, $i, 1);
        }

        return $out;
    }

    /**
     * Net revenue by industry with its share, largest first. A sale belongs to the industry of its item ("Unassigned" when the item has none).
     *
     * @return list<array{key: int, name: string, amount: string, share_pct: string|null}>
     */
    public function revenueByIndustry(AnalyticsFilter $f): array
    {
        return $this->shares($this->profit->by('industry', $f->from, $f->to, $f->industryId, $f->locationId), 'net_revenue');
    }

    /**
     * Actual loss by industry with its share, largest first.
     *
     * @return list<array{key: int, name: string, amount: string, share_pct: string|null}>
     */
    public function lossesByIndustry(AnalyticsFilter $f): array
    {
        return $this->shares($this->profit->by('industry', $f->from, $f->to, $f->industryId, $f->locationId), 'actual_loss');
    }

    /**
     * The products that sold most: revenue, units (in each item's base unit), gross profit, and the change in revenue on the previous period.
     *
     * @return list<array{key: int, name: string, net_revenue: string, units: string, gross_profit: string, margin_pct: string|null, change_pct: string|null}>
     */
    public function topProducts(AnalyticsFilter $f, int $limit = 10): array
    {
        $rows = collect($this->profit->by('product', $f->from, $f->to, $f->industryId, $f->locationId))->where('key', '!=', 0)
            ->filter(fn (array $r) => bccomp($r['net_revenue'], '0', 2) > 0)->take($limit)->values();

        if ($rows->isEmpty()) {
            return [];
        }

        $before = collect($this->profit->by('product', $f->previous()->from, $f->previous()->to, $f->industryId, $f->locationId))->keyBy('key');
        $units = $this->unitsSold($f, $rows->pluck('key')->all());

        return $rows->map(fn (array $r) => [
            'key' => $r['key'], 'name' => $r['name'], 'net_revenue' => $r['net_revenue'], 'units' => $units[$r['key']] ?? '0', 'gross_profit' => $r['gross_profit'],
            'margin_pct' => $r['margin_pct'], 'change_pct' => $this->change($r['net_revenue'], $before->get($r['key'])['net_revenue'] ?? '0.00'),
        ])->all();
    }

    /**
     * Brand profitability, ranked by the chosen metric.
     *
     * @return list<array<string, mixed>>
     */
    public function brandProfitability(AnalyticsFilter $f, string $sort = 'net_revenue'): array
    {
        $rows = $this->profit->by('brand', $f->from, $f->to, $f->industryId, $f->locationId);
        $sort = in_array($sort, ['net_revenue', 'cogs', 'actual_loss', 'gross_profit', 'margin_pct'], true) ? $sort : 'net_revenue';

        usort($rows, fn (array $a, array $b) => bccomp((string) ($b[$sort] ?? '-999999999'), (string) ($a[$sort] ?? '-999999999'), 2) ?: strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Loss by category (the Pareto): amount, incidents, share and running share, and **recurrence** — how many of the period's incidents had another of the
     * same category, branch and item in the 90 days before (FORMULAS.md `recurrence`).
     *
     * @return list<array{category_id: int, category: string, amount: string, incidents: int, share_pct: string|null, cumulative_pct: string|null, recurrence: int}>
     */
    public function lossCategories(AnalyticsFilter $f): array
    {
        $pareto = $this->losses->pareto($f->from, $f->to, $f->industryId, $f->locationId);
        $total = array_reduce($pareto, fn (string $c, array $r) => bcadd($c, $r['amount'], 2), '0.00');
        $recurring = $this->recurrence($f);

        return array_map(fn (array $r) => [
            ...$r, 'share_pct' => bccomp($total, '0', 2) === 0 ? null : $this->pct(bcdiv($r['amount'], $total, 8)), 'recurrence' => $recurring[$r['category_id']] ?? 0,
        ], $pareto);
    }

    /**
     * Where the loss of one category (or all) comes from, by branch.
     *
     * @return list<array{key: int, name: string, amount: string, incidents: int}>
     */
    public function lossesBySite(AnalyticsFilter $f): array
    {
        $rows = DB::table('loss_impact_lines as l')->join('loss_incidents as i', 'i.id', '=', 'l.loss_incident_id')->join('locations as s', 's.id', '=', 'i.location_id')
            ->whereIn('l.loss_incident_id', $this->incidentIds($f))->where('l.impact_type', 'actual')->where('l.validation_state', 'validated')->whereNull('l.superseded_at')
            ->groupBy('s.id', 's.name')->selectRaw('s.id as k, s.name as name, sum(l.amount) as amount, count(distinct i.id) as incidents')->orderByDesc('amount')->get();

        return $rows->map(fn ($r) => ['key' => (int) $r->k, 'name' => (string) $r->name, 'amount' => bcadd((string) $r->amount, '0', 2), 'incidents' => (int) $r->incidents])->all();
    }

    /**
     * The incidents behind a number (the last step of a drill-down), largest actual loss first, with their validated actual loss.
     *
     * @return list<LossIncident>
     */
    public function incidents(AnalyticsFilter $f, int $limit = 25): array
    {
        return $this->incidentQuery($f)->with(['template:id,name', 'category:id,name', 'location:id,name', 'owner:id,name'])
            ->withSum(['impactLines as actual_amount' => fn ($q) => $q->where('impact_type', 'actual')->where('validation_state', 'validated')->whereNull('superseded_at')], 'amount')
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get()->all();
    }

    /**
     * One card per industry template: revenue, loss, margin and how revenue moved. Templates the company has not switched on are listed, marked off.
     *
     * @return list<array{id: int, code: string, name: string, icon: string|null, enabled: bool, net_revenue: string, actual_loss: string, margin_pct: string|null, change_pct: string|null}>
     */
    public function industryOverview(AnalyticsFilter $f): array
    {
        $now = collect($this->profit->by('industry', $f->from, $f->to, null, $f->locationId))->keyBy('key');
        $before = collect($this->profit->by('industry', $f->previous()->from, $f->previous()->to, null, $f->locationId))->keyBy('key');
        $enabled = $this->templates->enabled()->pluck('id')->all();

        return IndustryTemplate::query()->where('status', 'active')->orderBy('name')->get()->map(fn (IndustryTemplate $t) => [
            'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'icon' => $t->icon, 'enabled' => in_array($t->id, $enabled, true),
            'net_revenue' => $now->get($t->id)['net_revenue'] ?? '0.00', 'actual_loss' => $now->get($t->id)['actual_loss'] ?? '0.00',
            'margin_pct' => $now->get($t->id)['margin_pct'] ?? null,
            'change_pct' => $this->change($now->get($t->id)['net_revenue'] ?? '0.00', $before->get($t->id)['net_revenue'] ?? '0.00'),
        ])->all();
    }

    /**
     * The Leakage radar's open backlog (what is waiting for a person to report or dismiss — **estimates, not losses**, so kept apart from every loss figure) and
     * the savings Finance has verified in the period (a different thing from a corrective action's own verified result). Each part is null when the caller
     * may not see it.
     *
     * @return array{radar: array{open: int, high: int, estimate: string}|null, savings: array{net_saving: string, count: int, recurred: int}|null}
     */
    public function leakage(AnalyticsFilter $f, bool $radar, bool $savings): array
    {
        $open = null;

        if ($radar) {
            $rows = RadarFinding::query()->where('state', 'open')->when($f->locationId !== null, fn (Builder $q) => $q->where('location_id', $f->locationId));
            $open = [
                'open' => (clone $rows)->count(), 'high' => (clone $rows)->where('severity', 'high')->count(),
                'estimate' => bcadd((string) (clone $rows)->sum('estimated_amount'), '0', 2),
            ];
        }

        return ['radar' => $open, 'savings' => $savings ? $this->losses->financeVerifiedSavings($f->from, $f->to, $f->locationId) : null];
    }

    /** @return Builder<LossIncident> incidents that count, in the period, for the filter */
    public function incidentQuery(AnalyticsFilter $f): Builder
    {
        return LossIncident::query()->whereNull('primary_incident_id')->where('state', '<>', 'rejected')
            ->whereBetween('occurred_at', [$f->from->startOfDay(), $f->to->endOfDay()])
            ->when($f->industryId !== null, fn (Builder $q) => $q->where('industry_template_id', $f->industryId))
            ->when($f->locationId !== null, fn (Builder $q) => $q->where('location_id', $f->locationId))
            ->when($f->categoryId !== null, fn (Builder $q) => $q->where('loss_category_id', $f->categoryId));
    }

    /** (new − old) ÷ old × 100, 2 decimals half up away from zero; null when there was nothing before. */
    public function change(string $now, string $before): ?string
    {
        if (bccomp($before, '0', 2) === 0) {
            return null;
        }

        $value = bcmul(bcdiv(bcsub($now, $before, 2), $before, 8), '100', 6);

        return bcadd($value, bccomp($value, '0', 6) < 0 ? '-0.005' : '0.005', 2);
    }

    // ---- internals ------------------------------------------------------------------------------------------------

    /** @return array{net_revenue: string, actual_loss: string} */
    private function totals(AnalyticsFilter $f): array
    {
        $rows = $this->profit->by('industry', $f->from, $f->to, $f->industryId, $f->locationId);

        return [
            'net_revenue' => array_reduce($rows, fn (string $c, array $r) => bcadd($c, $r['net_revenue'], 2), '0.00'),
            'actual_loss' => array_reduce($rows, fn (string $c, array $r) => bcadd($c, $r['actual_loss'], 2), '0.00'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{key: int, name: string, amount: string, share_pct: string|null}>
     */
    private function shares(array $rows, string $field): array
    {
        $rows = array_values(array_filter($rows, fn (array $r) => bccomp((string) $r[$field], '0', 2) > 0));
        usort($rows, fn (array $a, array $b) => bccomp((string) $b[$field], (string) $a[$field], 2));
        $total = array_reduce($rows, fn (string $c, array $r) => bcadd($c, (string) $r[$field], 2), '0.00');

        return array_map(fn (array $r) => [
            'key' => $r['key'], 'name' => $r['name'], 'amount' => (string) $r[$field], 'share_pct' => bccomp($total, '0', 2) === 0 ? null : $this->pct(bcdiv((string) $r[$field], $total, 8)),
        ], $rows);
    }

    private function pct(string $ratio): string
    {
        return bcadd(bcmul($ratio, '100', 6), '0.005', 2);
    }

    /**
     * Net revenue and actual loss per bucket: bucket key => [revenue, loss].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function series(AnalyticsFilter $f, string $g): array
    {
        $dates = [$f->from->toDateString(), $f->to->toDateString()];
        $invoices = SalesInvoice::query()->where('status', SalesInvoice::ISSUED)->whereBetween('invoice_date', $dates)
            ->when($f->locationId !== null, fn ($q) => $q->where('location_id', $f->locationId))->select('id');
        $returns = SalesReturn::query()->where('approval_status', 'approved')->whereBetween('return_date', $dates)
            ->when($f->locationId !== null, fn ($q) => $q->where('location_id', $f->locationId))->select('id');

        $sold = DB::table('sales_invoice_items as l')->join('sales_invoices as d', 'd.id', '=', 'l.sales_invoice_id')->join('items as it', 'it.id', '=', 'l.item_id')
            ->whereIn('l.sales_invoice_id', $invoices)->whereNull('l.deleted_at')->when($f->industryId !== null, fn ($q) => $q->where('it.industry_template_id', $f->industryId))
            ->groupBy(DB::raw($this->bucket('d.invoice_date', $g)))->selectRaw($this->bucket('d.invoice_date', $g).' as k, sum(l.gross_amount - l.discount_amount) as amount')->pluck('amount', 'k');

        $back = DB::table('sales_return_items as l')->join('sales_returns as d', 'd.id', '=', 'l.sales_return_id')->join('items as it', 'it.id', '=', 'l.item_id')
            ->whereIn('l.sales_return_id', $returns)->whereNull('l.deleted_at')->when($f->industryId !== null, fn ($q) => $q->where('it.industry_template_id', $f->industryId))
            ->groupBy(DB::raw($this->bucket('d.return_date', $g)))->selectRaw($this->bucket('d.return_date', $g).' as k, sum(l.taxable_amount) as amount')->pluck('amount', 'k');

        $lost = DB::table('loss_impact_lines as l')->join('loss_incidents as d', 'd.id', '=', 'l.loss_incident_id')
            ->whereIn('l.loss_incident_id', $this->incidentIds($f))->where('l.impact_type', 'actual')->where('l.validation_state', 'validated')->whereNull('l.superseded_at')
            ->groupBy(DB::raw($this->bucket('d.occurred_at', $g)))->selectRaw($this->bucket('d.occurred_at', $g).' as k, sum(l.amount) as amount')->pluck('amount', 'k');

        $out = [];

        foreach ($sold->keys()->merge($back->keys())->merge($lost->keys())->unique() as $k) {
            $out[(string) $k] = [bcsub(bcadd((string) ($sold[$k] ?? 0), '0', 2), bcadd((string) ($back[$k] ?? 0), '0', 2), 2), bcadd((string) ($lost[$k] ?? 0), '0', 2)];
        }

        return $out;
    }

    /** @return Builder<LossIncident> the ids of the incidents that count */
    private function incidentIds(AnalyticsFilter $f): Builder
    {
        return $this->incidentQuery($f)->select('id');
    }

    private function bucket(string $column, string $g): string
    {
        return match ($g) {
            'day' => "DATE({$column})",
            'week' => "DATE_SUB(DATE({$column}), INTERVAL WEEKDAY({$column}) DAY)",
            default => "DATE_FORMAT({$column}, '%Y-%m-01')",
        };
    }

    /** @return list<string> */
    private function bucketKeys(CarbonImmutable $from, CarbonImmutable $to, string $g): array
    {
        $keys = [];
        $at = match ($g) {
            'day' => $from->startOfDay(),
            'week' => $from->startOfWeek(),
            default => $from->startOfMonth(),
        };

        while ($at->lte($to)) {
            $keys[] = $at->toDateString();
            $at = match ($g) {
                'day' => $at->addDay(),
                'week' => $at->addWeek(),
                default => $at->addMonthNoOverflow(),
            };
        }

        return $keys;
    }

    private function label(string $key, string $g): string
    {
        $d = CarbonImmutable::parse($key);

        return $g === 'month' ? $d->format('M Y') : $d->format('d M');
    }

    /**
     * Units sold of each item, in the item's base unit (the line's quantity × the conversion of the unit it was sold in), less approved returns.
     *
     * @param  list<int>  $itemIds
     * @return array<int, string>
     */
    private function unitsSold(AnalyticsFilter $f, array $itemIds): array
    {
        $dates = [$f->from->toDateString(), $f->to->toDateString()];
        $invoices = SalesInvoice::query()->where('status', SalesInvoice::ISSUED)->whereBetween('invoice_date', $dates)
            ->when($f->locationId !== null, fn ($q) => $q->where('location_id', $f->locationId))->select('id');
        $returns = SalesReturn::query()->where('approval_status', 'approved')->whereBetween('return_date', $dates)
            ->when($f->locationId !== null, fn ($q) => $q->where('location_id', $f->locationId))->select('id');

        $sum = fn (string $table, string $parent, $ids) => DB::table("{$table} as l")->leftJoin('item_uoms as u', fn ($j) => $j->on('u.item_id', '=', 'l.item_id')->on('u.unit_id', '=', 'l.unit_id')->whereNull('u.deleted_at'))
            ->whereIn("l.{$parent}", $ids)->whereIn('l.item_id', $itemIds)->whereNull('l.deleted_at')->groupBy('l.item_id')->selectRaw('l.item_id as k, sum(l.quantity * coalesce(u.factor, 1)) as qty')->pluck('qty', 'k');

        $sold = $sum('sales_invoice_items', 'sales_invoice_id', $invoices);
        $back = $sum('sales_return_items', 'sales_return_id', $returns);
        $out = [];

        foreach ($itemIds as $id) {
            $q = bcsub(bcadd((string) ($sold[$id] ?? 0), '0', 3), bcadd((string) ($back[$id] ?? 0), '0', 3), 3);
            $out[$id] = rtrim(rtrim($q, '0'), '.') ?: '0';
        }

        return $out;
    }

    /** @return array<int, int> category id => incidents with an earlier one of the same category, branch and item in the 90 days before */
    private function recurrence(AnalyticsFilter $f): array
    {
        return DB::table('loss_incidents as i')->whereIn('i.id', $this->incidentIds($f))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('loss_incidents as p')->whereColumn('p.company_id', 'i.company_id')->whereColumn('p.loss_category_id', 'i.loss_category_id')
                ->whereColumn('p.location_id', 'i.location_id')->whereRaw('p.item_id <=> i.item_id')->whereColumn('p.id', '<>', 'i.id')->whereNull('p.deleted_at')->whereNull('p.primary_incident_id')
                ->where('p.state', '<>', 'rejected')->whereColumn('p.occurred_at', '<', 'i.occurred_at')->whereRaw('p.occurred_at >= DATE_SUB(i.occurred_at, INTERVAL 90 DAY)'))
            ->groupBy('i.loss_category_id')->selectRaw('i.loss_category_id as k, count(*) as n')->pluck('n', 'k')->map(fn ($n) => (int) $n)->all();
    }
}

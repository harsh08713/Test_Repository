<?php

namespace App\Modules\Finance\Services;

use App\Modules\Loss\Models\LossIncident;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Profitability by product, brand or site — FORMULAS.md §2 `brand_profitability`, worked out in one place.
 *
 *   net_revenue   gross sales − discounts − approved returns   (issued invoices and approved returns dated in the period; tax excluded)
 *   cogs          cost of the invoiced lines − cost of the returned lines   (the cost copied onto the lines when they were made)
 *   gross_profit  net_revenue − cogs
 *   margin %      gross_profit ÷ net_revenue × 100; null when net revenue is 0
 *   actual_loss   validated actual loss of incidents of that item / brand / site (not duplicates, not rejected) — shown **beside** the profit, never
 *                 taken off it: the four loss values stay apart
 *
 * A line whose cost was not known adds nothing to the cost and is counted in `lines_without_cost`, so a row can say its profit is not complete.
 * Loss incidents with no item belong to the "No item" row of a product view and "No brand" of a brand view. **Industry**: a sale belongs to the industry of its
 * item (`items.industry_template_id`, "Unassigned" when none is set); a loss to the industry of its incident. Optional filters narrow every figure to one
 * industry and/or one branch. Everything runs through the tenant and branch scopes.
 */
final class Profitability
{
    public const DIMENSIONS = ['product' => 'Product', 'brand' => 'Brand', 'site' => 'Site', 'industry' => 'Industry'];

    /**
     * @return list<array{key: int, name: string, gross_sales: string, discounts: string, returns: string, net_revenue: string, cogs: string, gross_profit: string, margin_pct: string|null, actual_loss: string, lines_without_cost: int}>
     */
    public function by(string $dimension, CarbonInterface $from, CarbonInterface $to, ?int $industryId = null, ?int $locationId = null): array
    {
        abort_unless(array_key_exists($dimension, self::DIMENSIONS), 404);

        $dates = [$from->toDateString(), $to->toDateString()];
        $invoices = SalesInvoice::query()->where('status', SalesInvoice::ISSUED)->whereBetween('invoice_date', $dates)
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))->select('id');
        $returns = SalesReturn::query()->where('approval_status', 'approved')->whereBetween('return_date', $dates)
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))->select('id');
        $incidents = LossIncident::query()->whereNull('primary_incident_id')->where('state', '<>', 'rejected')
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($industryId !== null, fn ($q) => $q->where('industry_template_id', $industryId))
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))->select('id');

        $sold = DB::table('sales_invoice_items as l')->join('sales_invoices as d', 'd.id', '=', 'l.sales_invoice_id')->join('items as it', 'it.id', '=', 'l.item_id')
            ->whereIn('l.sales_invoice_id', $invoices)->whereNull('l.deleted_at')->when($industryId !== null, fn ($q) => $q->where('it.industry_template_id', $industryId))->groupBy(DB::raw($this->key($dimension, 'it.id', 'it.brand_id', 'd.location_id', 'it.industry_template_id')))
            ->selectRaw($this->key($dimension, 'it.id', 'it.brand_id', 'd.location_id', 'it.industry_template_id').' as k, sum(l.gross_amount) as gross, sum(l.discount_amount) as discount, sum(l.cost_amount) as cost, sum(case when l.cost_amount is null then 1 else 0 end) as unknown')
            ->get()->keyBy('k');

        $back = DB::table('sales_return_items as l')->join('sales_returns as d', 'd.id', '=', 'l.sales_return_id')->join('items as it', 'it.id', '=', 'l.item_id')
            ->whereIn('l.sales_return_id', $returns)->whereNull('l.deleted_at')->when($industryId !== null, fn ($q) => $q->where('it.industry_template_id', $industryId))->groupBy(DB::raw($this->key($dimension, 'it.id', 'it.brand_id', 'd.location_id', 'it.industry_template_id')))
            ->selectRaw($this->key($dimension, 'it.id', 'it.brand_id', 'd.location_id', 'it.industry_template_id').' as k, sum(l.taxable_amount) as taxable, sum(l.cost_amount) as cost, sum(case when l.cost_amount is null then 1 else 0 end) as unknown')
            ->get()->keyBy('k');

        $lost = DB::table('loss_impact_lines as l')->join('loss_incidents as d', 'd.id', '=', 'l.loss_incident_id')->leftJoin('items as it', 'it.id', '=', 'd.item_id')
            ->whereIn('l.loss_incident_id', $incidents)->where('l.impact_type', 'actual')->where('l.validation_state', 'validated')->whereNull('l.superseded_at')
            ->groupBy(DB::raw($this->key($dimension, 'coalesce(d.item_id, 0)', 'coalesce(it.brand_id, 0)', 'd.location_id', 'd.industry_template_id')))
            ->selectRaw($this->key($dimension, 'coalesce(d.item_id, 0)', 'coalesce(it.brand_id, 0)', 'd.location_id', 'd.industry_template_id').' as k, sum(l.amount) as amount')
            ->get()->keyBy('k');

        $keys = $sold->keys()->merge($back->keys())->merge($lost->keys())->unique()->values();
        $names = $this->names($dimension, $keys->map(fn ($k) => (int) $k)->all());
        $rows = [];

        foreach ($keys as $k) {
            $s = $sold->get($k);
            $r = $back->get($k);
            $gross = bcadd((string) ($s->gross ?? 0), '0', 2);
            $discounts = bcadd((string) ($s->discount ?? 0), '0', 2);
            $returned = bcadd((string) ($r->taxable ?? 0), '0', 2);
            $net = bcsub(bcsub($gross, $discounts, 2), $returned, 2);
            $cogs = bcsub(bcadd((string) ($s->cost ?? 0), '0', 2), bcadd((string) ($r->cost ?? 0), '0', 2), 2);
            $profit = bcsub($net, $cogs, 2);

            $rows[] = [
                'key' => (int) $k, 'name' => $names[(int) $k] ?? '—', 'gross_sales' => $gross, 'discounts' => $discounts, 'returns' => $returned, 'net_revenue' => $net,
                'cogs' => $cogs, 'gross_profit' => $profit, 'margin_pct' => bccomp($net, '0', 2) === 0 ? null : $this->percent($profit, $net),
                'actual_loss' => bcadd((string) ($lost->get($k)->amount ?? 0), '0', 2), 'lines_without_cost' => (int) ($s->unknown ?? 0) + (int) ($r->unknown ?? 0),
            ];
        }

        usort($rows, fn (array $a, array $b) => [bccomp($b['net_revenue'], $a['net_revenue'], 2), $a['name']] <=> [0, $b['name']]);

        return $rows;
    }

    /** The SQL expression that says which row a line belongs to. */
    private function key(string $dimension, string $item, string $brand, string $site, string $industry): string
    {
        return match ($dimension) {
            'product' => $item,
            'brand' => "coalesce({$brand}, 0)",
            'industry' => "coalesce({$industry}, 0)",
            default => $site,
        };
    }

    /**
     * @param  list<int>  $keys
     * @return array<int, string>
     */
    private function names(string $dimension, array $keys): array
    {
        [$table, $none] = match ($dimension) {
            'product' => ['items', 'No item'],
            'brand' => ['brands', 'No brand'],
            'industry' => ['industry_templates', 'Unassigned'],
            default => ['locations', 'No site'],
        };

        $names = DB::table($table)->whereIn('id', $keys)->pluck('name', 'id')->all();
        $names[0] = $none;

        return $names;
    }

    private function percent(string $profit, string $net): string
    {
        $value = bcmul(bcdiv($profit, $net, 8), '100', 6);

        return bcadd($value, bccomp($value, '0', 6) < 0 ? '-0.005' : '0.005', 2);
    }
}

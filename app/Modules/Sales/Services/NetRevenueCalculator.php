<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use Carbon\CarbonInterface;

/**
 * Net revenue, cost of goods sold, gross profit and margin for a period — FORMULAS.md §2, version 1. The only place these are worked out, so
 * the dashboard, the reports and the exports (Phase L6) cannot disagree.
 *
 *   gross_sales   = Σ gross amount of the lines of issued invoices dated in the period
 *   discounts     = Σ discount of those lines
 *   returns       = Σ taxable value of **approved** returns and credit notes dated in the period (a draft, pending or rejected one counts for nothing)
 *   net_revenue   = gross_sales − discounts − returns
 *   cogs          = Σ cost of those invoice lines − Σ cost of the returned lines
 *   gross_profit  = net_revenue − cogs
 *   margin_pct    = null when net_revenue is 0, else gross_profit ÷ net_revenue × 100 (2 decimals, half up)
 *
 * Tax is left out of every figure. A cancelled invoice counts for nothing. A line with no known cost adds nothing to the cost, and is counted in
 * `lines_without_cost` so a report can say its profit is not complete — a missing cost is never read as zero. Everything runs through the
 * tenant scopes, so a person sees the company and branches they work in.
 */
final class NetRevenueCalculator
{
    /**
     * @return array{gross_sales: string, discounts: string, returns: string, net_revenue: string, cogs: string, gross_profit: string, margin_pct: string|null, lines_without_cost: int, cost_complete: bool}
     */
    public function forPeriod(CarbonInterface $from, CarbonInterface $to, ?int $locationId = null): array
    {
        $invoices = SalesInvoice::query()->where('status', SalesInvoice::ISSUED)
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->select('id');

        $sold = SalesInvoiceItem::query()->whereIn('sales_invoice_id', $invoices)->toBase()
            ->selectRaw('coalesce(sum(gross_amount), 0) as gross, coalesce(sum(discount_amount), 0) as discount, coalesce(sum(cost_amount), 0) as cost, sum(case when cost_amount is null then 1 else 0 end) as unknown')
            ->first();

        $returns = SalesReturn::query()->where('approval_status', 'approved')
            ->whereBetween('return_date', [$from->toDateString(), $to->toDateString()])
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->select('id');

        $back = SalesReturnItem::query()->whereIn('sales_return_id', $returns)->toBase()
            ->selectRaw('coalesce(sum(taxable_amount), 0) as taxable, coalesce(sum(cost_amount), 0) as cost, sum(case when cost_amount is null then 1 else 0 end) as unknown')
            ->first();

        $gross = bcadd((string) ($sold->gross ?? 0), '0', 2);
        $discounts = bcadd((string) ($sold->discount ?? 0), '0', 2);
        $returned = bcadd((string) ($back->taxable ?? 0), '0', 2);
        $net = bcsub(bcsub($gross, $discounts, 2), $returned, 2);
        $cogs = bcsub(bcadd((string) ($sold->cost ?? 0), '0', 2), bcadd((string) ($back->cost ?? 0), '0', 2), 2);
        $profit = bcsub($net, $cogs, 2);
        $unknown = (int) ($sold->unknown ?? 0) + (int) ($back->unknown ?? 0);

        return [
            'gross_sales' => $gross,
            'discounts' => $discounts,
            'returns' => $returned,
            'net_revenue' => $net,
            'cogs' => $cogs,
            'gross_profit' => $profit,
            'margin_pct' => bccomp($net, '0', 2) === 0 ? null : $this->percent($profit, $net),
            'lines_without_cost' => $unknown,
            'cost_complete' => $unknown === 0,
        ];
    }

    /** profit ÷ net × 100, 2 decimals, half up away from zero (a loss rounds the same way as a profit). */
    private function percent(string $profit, string $net): string
    {
        $value = bcmul(bcdiv($profit, $net, 8), '100', 6);
        $half = bccomp($value, '0', 6) < 0 ? '-0.005' : '0.005';

        return bcadd($value, $half, 2);
    }
}

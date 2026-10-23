<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Finance\Services\Profitability;
use App\Modules\Reports\Reports\Concerns\ResolvesSpan;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Support\Collection;
use stdClass;

/** Net revenue, cost, gross profit and margin by product, brand, branch or industry, with actual loss beside it (never subtracted). Profitability's own figures. */
final class ProfitabilityBreakdown extends Report
{
    use ResolvesSpan;

    public function key(): string
    {
        return 'profitability';
    }

    public function title(): string
    {
        return 'Profitability';
    }

    public function description(): string
    {
        return 'Net revenue, cost, gross profit and margin by product, brand, site or industry. Net revenue is invoiced less approved returns and credit notes, before tax.';
    }

    public function group(): string
    {
        return 'Finance';
    }

    public function permission(): string
    {
        return 'profitability.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Invoiced';
    }

    public function choiceLabel(): string
    {
        return 'By';
    }

    public function choices(): array
    {
        return Profitability::DIMENSIONS;
    }

    public function defaultChoice(): string
    {
        return 'product';
    }

    public function note(): string
    {
        return 'Cost is the cost copied onto each invoice line when it was issued; a line with no cost is counted in "Lines with no cost" and its profit is left incomplete, never taken as zero cost. Loss is shown beside profit, not subtracted from it.';
    }

    public function columns(): array
    {
        return [
            new C('name', 'Name'), new C('gross_sales', 'Gross sales', C::MONEY), new C('returns', 'Returns', C::MONEY), new C('net_revenue', 'Net revenue', C::MONEY), new C('cogs', 'Cost', C::MONEY),
            new C('gross_profit', 'Gross profit', C::MONEY), new C('margin_pct', 'Margin', C::PERCENT), new C('actual_loss', 'Actual loss', C::MONEY), new C('lines_without_cost', 'Lines with no cost', C::NUMBER),
        ];
    }

    /** @return Collection<int, stdClass> */
    public function collection(ReportInput $input, User $user): Collection
    {
        [$from, $to] = $this->span($input);
        $by = array_key_exists((string) $input->choice, Profitability::DIMENSIONS) ? (string) $input->choice : 'product';

        return collect(app(Profitability::class)->by($by, $from, $to, null, $input->branch))->map(fn (array $r): stdClass => $this->asRow($r))->values();
    }

    public function summary(ReportInput $input, User $user): array
    {
        $rows = $this->collection($input, $user);
        $sum = fn (string $k) => $rows->reduce(fn (string $c, object $r) => bcadd($c, (string) $r->{$k}, 2), '0.00');

        return ['Net revenue (₹)' => number_format((float) $sum('net_revenue'), 2), 'Cost (₹)' => number_format((float) $sum('cogs'), 2), 'Gross profit (₹)' => number_format((float) $sum('gross_profit'), 2), 'Actual loss (₹)' => number_format((float) $sum('actual_loss'), 2)];
    }
}

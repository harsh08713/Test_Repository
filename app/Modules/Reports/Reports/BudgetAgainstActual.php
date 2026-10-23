<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Finance\Models\CostCenter;
use App\Modules\Finance\Services\BudgetVsActual;
use App\Modules\Reports\Reports\Concerns\ResolvesSpan;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Support\Collection;
use stdClass;

/** Approved budgets against validated actual loss, by cost centre and category, from BudgetVsActual — the screen's own figures. */
final class BudgetAgainstActual extends Report
{
    use ResolvesSpan;

    public function key(): string
    {
        return 'budget-vs-actual';
    }

    public function title(): string
    {
        return 'Budget against actual loss';
    }

    public function description(): string
    {
        return 'For each cost centre and loss category: the approved budget for the months in the period, the validated actual loss, and how far over or under it is.';
    }

    public function group(): string
    {
        return 'Finance';
    }

    public function permission(): string
    {
        return 'budget.view';
    }

    public function filters(): array
    {
        return ['date', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Period';
    }

    public function choiceLabel(): string
    {
        return 'Cost centre';
    }

    public function choices(): array
    {
        return CostCenter::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function note(): string
    {
        return 'Only approved budgets are compared, and only actual loss a second person has validated. A budget for a whole cost centre meets that centre\'s losses of every category.';
    }

    public function columns(): array
    {
        return [
            new C('cost_center', 'Cost centre'), new C('category', 'Category'), new C('budget', 'Budget', C::MONEY), new C('actual', 'Actual loss', C::MONEY), new C('variance', 'Over (+) / under (−)', C::MONEY),
            new C('variance_pct', 'Variance', C::PERCENT), new C('status', 'Status'),
        ];
    }

    /** @return Collection<int, stdClass> */
    public function collection(ReportInput $input, User $user): Collection
    {
        [$from, $to] = $this->span($input);

        return collect(app(BudgetVsActual::class)->forPeriod($from, $to, $input->choice === null ? null : (int) $input->choice))->map(fn (array $r): stdClass => $this->asRow($r))->values();
    }

    public function summary(ReportInput $input, User $user): array
    {
        $rows = $this->collection($input, $user);
        $sum = fn (string $k, Collection $of) => $of->reduce(fn (string $c, object $r) => bcadd($c, (string) $r->{$k}, 2), '0.00');

        // a budget for a whole cost centre meets that centre's losses of every category, so its "actual" repeats what the category rows already show: the loss is added up from the category rows only
        $budget = $sum('budget', $rows);
        $actual = $sum('actual', $rows->filter(fn (object $r) => $r->category_id !== null));

        return ['Budget (₹)' => number_format((float) $budget, 2), 'Actual loss (₹)' => number_format((float) $actual, 2), 'Over / under (₹)' => number_format((float) bcsub($actual, $budget, 2), 2)];
    }
}

<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Analytics\Services\AnalyticsFilter;
use App\Modules\Analytics\Services\DashboardAnalytics;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Reports\Reports\Concerns\ResolvesSpan;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Support\Collection;
use stdClass;

/** Where the actual loss comes from, largest first, with each category's share, the running share (the Pareto) and how often it recurs. Worked out by DashboardAnalytics, the same numbers as the dashboard. */
final class LossPareto extends Report
{
    use ResolvesSpan;

    public function key(): string
    {
        return 'loss-pareto';
    }

    public function title(): string
    {
        return 'Loss by category (Pareto)';
    }

    public function description(): string
    {
        return 'Actual loss by category, largest first, with its share of the total, the running share and how many incidents recurred (same category, branch and item in the 90 days before).';
    }

    public function group(): string
    {
        return 'Loss Intelligence';
    }

    public function permission(): string
    {
        return 'loss_incident.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Occurred';
    }

    public function choiceLabel(): string
    {
        return 'Industry';
    }

    public function choices(): array
    {
        return app(IndustryTemplateService::class)->enabled()->mapWithKeys(fn ($t) => [$t->id => $t->name])->all();
    }

    public function note(): string
    {
        return 'Actual loss only (validated by a second person). Duplicates and rejected incidents are left out; exposure and opportunity are not part of it.';
    }

    public function columns(): array
    {
        return [new C('category', 'Category'), new C('amount', 'Actual loss', C::MONEY), new C('incidents', 'Incidents', C::NUMBER), new C('share_pct', 'Share', C::PERCENT), new C('cumulative_pct', 'Running share', C::PERCENT), new C('recurrence', 'Recurring', C::NUMBER)];
    }

    /** @return Collection<int, stdClass> */
    public function collection(ReportInput $input, User $user): Collection
    {
        [$from, $to] = $this->span($input);
        $filter = new AnalyticsFilter($from, $to, 'custom', $input->choice === null ? null : (int) $input->choice, $input->branch);

        return collect(app(DashboardAnalytics::class)->lossCategories($filter))->map(fn (array $r): stdClass => $this->asRow($r))->values();
    }

    public function summary(ReportInput $input, User $user): array
    {
        $rows = $this->collection($input, $user);
        $total = $rows->reduce(fn (string $c, object $r) => bcadd($c, $r->amount, 2), '0.00');

        return ['Actual loss (₹)' => number_format((float) $total, 2), 'Categories' => number_format($rows->count()), 'Largest category' => $rows->first()->category ?? '—'];
    }
}

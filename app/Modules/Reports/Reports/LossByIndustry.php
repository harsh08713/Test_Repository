<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Analytics\Services\AnalyticsFilter;
use App\Modules\Analytics\Services\DashboardAnalytics;
use App\Modules\Reports\Reports\Concerns\ResolvesSpan;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Support\Collection;
use stdClass;

/** Actual loss by industry with each industry's share. DashboardAnalytics again, so it matches the dashboard's "Losses by industry". */
final class LossByIndustry extends Report
{
    use ResolvesSpan;

    public function key(): string
    {
        return 'loss-by-industry';
    }

    public function title(): string
    {
        return 'Loss by industry';
    }

    public function description(): string
    {
        return 'Actual loss by industry, largest first, with its share of the total.';
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
        return ['date', 'branch'];
    }

    public function dateLabel(): string
    {
        return 'Occurred';
    }

    public function columns(): array
    {
        return [new C('name', 'Industry'), new C('amount', 'Actual loss', C::MONEY), new C('share_pct', 'Share', C::PERCENT)];
    }

    /** @return Collection<int, stdClass> */
    public function collection(ReportInput $input, User $user): Collection
    {
        [$from, $to] = $this->span($input);

        return collect(app(DashboardAnalytics::class)->lossesByIndustry(new AnalyticsFilter($from, $to, 'custom', null, $input->branch)))->map(fn (array $r): stdClass => $this->asRow($r))->values();
    }
}

<?php

namespace App\Modules\Analytics\Services;

use App\Models\User;

/**
 * The six analytics pages: who may open each, and the data each shows. The screens and the `?format=json` endpoints both ask here, so a chart, a table and a
 * JSON answer are the same numbers. A page needs the right to the *kind* of figure it shows: money from sales needs `profitability.view`, loss figures
 * need `loss_incident.view` — on top of `analytics.view`. The trend page shows whichever of the two a person holds and leaves the other out.
 */
final class AnalyticsPages
{
    /** @var array<string, array{title: string, blurb: string, needs: list<string>, any: bool}> */
    public const PAGES = [
        'revenue-loss' => ['title' => 'Revenue vs loss', 'blurb' => 'Net revenue and actual loss over time, with the previous period for comparison.', 'needs' => ['profitability.view', 'loss_incident.view'], 'any' => true],
        'industry-revenue' => ['title' => 'Revenue by industry', 'blurb' => 'Net revenue by the industry of the product sold, with each industry’s share.', 'needs' => ['profitability.view'], 'any' => false],
        'industry-losses' => ['title' => 'Losses by industry', 'blurb' => 'Actual loss by industry. Choose an industry, then a category, to see the branches and incidents behind it.', 'needs' => ['loss_incident.view'], 'any' => false],
        'top-products' => ['title' => 'Top products', 'blurb' => 'The products that sold most: revenue, units, gross profit and how revenue moved on the previous period.', 'needs' => ['profitability.view'], 'any' => false],
        'loss-categories' => ['title' => 'Loss categories', 'blurb' => 'Where the loss comes from, largest first (the Pareto), with how often it recurs.', 'needs' => ['loss_incident.view'], 'any' => false],
        'brand-profitability' => ['title' => 'Brand profitability', 'blurb' => 'Revenue, cost, loss, profit and margin by brand. Rank by any column.', 'needs' => ['profitability.view'], 'any' => false],
    ];

    public function __construct(private readonly DashboardAnalytics $analytics) {}

    public function exists(string $page): bool
    {
        return array_key_exists($page, self::PAGES);
    }

    /** May this person open this page at all? */
    public function allowed(User $user, string $page): bool
    {
        $def = self::PAGES[$page] ?? null;

        if ($def === null || ! $user->can('analytics.view')) {
            return false;
        }

        $has = array_map(fn (string $p) => $user->can($p), $def['needs']);

        return $def['any'] ? in_array(true, $has, true) : ! in_array(false, $has, true);
    }

    /**
     * The data of a page, for the filter (and, where a page takes one, a sort or a granularity).
     *
     * @param  array<string, mixed>  $options  sort, granularity
     * @return array<string, mixed>
     */
    public function data(User $user, string $page, AnalyticsFilter $f, array $options = []): array
    {
        $head = ['page' => $page, 'title' => self::PAGES[$page]['title'], 'period' => ['from' => $f->from->toDateString(), 'to' => $f->to->toDateString(), 'label' => $f->label()]];
        $sales = $user->can('profitability.view');
        $loss = $user->can('loss_incident.view');

        return $head + match ($page) {
            'revenue-loss' => ['trend' => $this->trend($f, $options['granularity'] ?? null, $sales, $loss), 'can' => ['revenue' => $sales, 'loss' => $loss]],
            'industry-revenue' => ['rows' => $this->analytics->revenueByIndustry($f)],
            'industry-losses' => [
                'rows' => $this->analytics->lossesByIndustry($f),
                'categories' => $f->industryId === null ? [] : $this->analytics->lossCategories($f),
                'sites' => $f->industryId === null && $f->categoryId === null ? [] : $this->analytics->lossesBySite($f),
                'incidents' => $f->industryId === null && $f->categoryId === null ? [] : array_map(fn ($i) => [
                    'id' => $i->id, 'number' => $i->incident_number, 'title' => $i->title, 'industry' => $i->template?->name, 'category' => $i->category?->name, 'branch' => $i->location?->name,
                    'severity' => $i->severity, 'state' => $i->state, 'occurred_at' => $i->occurred_at->toIso8601String(), 'actual_loss' => bcadd((string) ($i->actual_amount ?? '0'), '0', 2),
                ], $this->analytics->incidents($f)),
            ],
            'top-products' => ['rows' => $this->analytics->topProducts($f, 15)],
            'loss-categories' => ['rows' => $this->analytics->lossCategories($f)],
            default => ['rows' => $this->analytics->brandProfitability($f, (string) ($options['sort'] ?? 'net_revenue')), 'sort' => (string) ($options['sort'] ?? 'net_revenue')],
        };
    }

    /**
     * The trend with the series this person may not see blanked out.
     *
     * @return array<string, mixed>
     */
    private function trend(AnalyticsFilter $f, ?string $granularity, bool $sales, bool $loss): array
    {
        $t = $this->analytics->trend($f, $granularity);
        $zeros = array_fill(0, count($t['labels']), '0.00');

        return [...$t, ...($sales ? [] : ['revenue' => $zeros, 'prior_revenue' => $zeros]), ...($loss ? [] : ['loss' => $zeros, 'prior_loss' => $zeros])];
    }
}

<?php

namespace App\Modules\Analytics\Http\Livewire;

use App\Models\User;
use App\Modules\Analytics\Services\AnalyticsPages;
use App\Modules\Analytics\Services\DashboardAnalytics;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Executive dashboard')]
class ExecutiveDashboard extends Component
{
    use FiltersAnalytics;

    #[Url(as: 'rank')]
    public string $rank = 'net_revenue';

    #[Url(as: 'top')]
    public int $top = 5;

    #[Url(as: 'grain')]
    public string $grain = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('analytics.view'), 403);
    }

    public function render(DashboardAnalytics $analytics, AnalyticsPages $pages): View
    {
        $user = $this->user();
        $f = $this->filter();
        $sales = $user->can('profitability.view');
        $loss = $user->can('loss_incident.view');
        $top = in_array($this->top, [5, 10, 15], true) ? $this->top : 5;
        $rank = in_array($this->rank, ['net_revenue', 'actual_loss', 'gross_profit', 'margin_pct'], true) ? $this->rank : 'net_revenue';

        $cards = $analytics->kpiCards($f);

        if (! $sales) {
            unset($cards['revenue'], $cards['brand']);
        }

        if (! $loss) {
            unset($cards['losses']);
        }

        return view('analytics.executive', [
            'f' => $f, 'query' => $f->toQuery(), 'cards' => $cards, 'canSales' => $sales, 'canLoss' => $loss, 'top' => $top, 'rank' => $rank,
            'trend' => $pages->data($user, 'revenue-loss', $f, ['granularity' => $this->grain])['trend'] ?? null,
            'leakage' => $analytics->leakage($f, $user->can('radar_finding.view'), $user->can('savings_measurement.view')),
            'revenueByIndustry' => $sales ? $analytics->revenueByIndustry($f) : [],
            'lossesByIndustry' => $loss ? $analytics->lossesByIndustry($f) : [],
            'products' => $sales ? $analytics->topProducts($f, 5) : [],
            'categories' => $loss ? array_slice($analytics->lossCategories($f), 0, 6) : [],
            'brands' => $sales ? array_slice($analytics->brandProfitability($f, $rank), 0, $top) : [],
            'industries' => $analytics->industryOverview($f),
            'incidents' => $loss ? array_map(fn ($i) => $i, $analytics->incidents($f, 6)) : [],
            'activity' => $user->can('audit_log.view') ? ActivityLog::query()->latest('id')->limit(6)->get() : collect(),
            'actions' => $this->actions($user),
            'demo' => (bool) app(SettingsService::class)->get('system.demo_data'),
            'links' => collect(AnalyticsPages::PAGES)->keys()->filter(fn (string $p) => $pages->allowed($user, $p))->all(),
        ] + $this->filterOptions());
    }

    /** @return list<array{label: string, icon: string, url: string}> only what this person may do */
    private function actions(User $user): array
    {
        $all = [
            ['Report loss', 'exclamation-triangle', 'loss.incidents.create', 'loss_incident.create'],
            ['New product', 'cube', 'masters.items.create', 'item.create'],
            ['Add brand', 'tag', 'masters.brands', 'brand.create'],
            ['Create order', 'check', 'sales.orders.direct', 'sales_order.create'],
            ['Generate report', 'chart-bar', 'reports.index', 'report.view'],
            ['Industries', 'layers', 'industries.index', 'industry_template.view'],
            ['Settings', 'cog', 'admin.settings', 'setting.view'],
        ];

        return collect($all)->filter(fn (array $a) => Route::has($a[2]) && $user->can($a[3]))
            ->map(fn (array $a) => ['label' => $a[0], 'icon' => $a[1], 'url' => route($a[2])])->values()->all();
    }
}

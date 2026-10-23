<?php

namespace App\Modules\Analytics\Http\Livewire;

use App\Models\User;
use App\Modules\Analytics\Models\KpiSnapshot;
use App\Modules\Analytics\Services\AnalyticsFilter;
use App\Modules\Analytics\Services\DashboardAnalytics;
use App\Modules\Analytics\Services\KpiCalculator;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\KpiDefinition;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One industry's dashboard: its KPIs with green / amber / red from the thresholds, worked out now for what the person can see (month to date), with the
 * last nightly snapshot beside it for people who work in every branch (a snapshot is company-wide, so it is never shown to anyone narrower).
 */
#[Title('Industry dashboard')]
class IndustryDashboard extends Component
{
    public IndustryTemplate $template;

    public function mount(IndustryTemplate $industry): void
    {
        abort_unless($this->user()->can('industry_template.view') && $this->user()->can('analytics.view'), 403);
        $this->template = $industry;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function render(KpiCalculator $calculator, IndustryTemplateService $templates, DashboardAnalytics $analytics, TenantContext $tenant): View
    {
        $enabled = $templates->isEnabled($this->template);
        $today = now()->startOfDay();
        $f = AnalyticsFilter::fromArray(['range' => 'this_month', 'industry' => (string) $this->template->id]);
        $company = $tenant->accessibleLocationIds() === null;
        $tiles = [];

        if ($enabled) {
            $kpis = KpiDefinition::query()->where('industry_template_id', $this->template->id)->where('version', $templates->versionFor($this->template))->where('is_active', true)->orderBy('id')->get();

            foreach ($kpis as $kpi) {
                $result = $calculator->supports($kpi) ? $calculator->compute($kpi, $this->template, $today->copy()->startOfMonth(), $today) : null;
                $snap = $company && $result !== null
                    ? KpiSnapshot::query()->where('kpi_definition_id', $kpi->id)->where('location_key', 0)->orderByDesc('period_start')->first() : null;

                $tiles[] = [
                    'kpi' => $kpi, 'measured' => $result !== null, 'value' => $result['value'] ?? null, 'status' => $result === null ? null : $templates->status($kpi, $result['value'] ?? null),
                    'snapshot' => $snap?->value, 'snapshot_at' => $snap?->computed_at,
                ];
            }
        }

        return view('analytics.industry', [
            'enabled' => $enabled, 'tiles' => $tiles, 'f' => $f, 'query' => $f->toQuery(), 'company' => $company,
            'revenue' => $this->user()->can('profitability.view') ? $analytics->revenueByIndustry($f) : [],
            'categories' => $this->user()->can('loss_incident.view') ? array_slice($analytics->lossCategories($f), 0, 5) : [],
        ]);
    }
}

<?php

namespace App\Modules\Analytics\Http\Livewire;

use App\Models\User;
use App\Modules\Analytics\Services\AnalyticsFilter;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Shared\Models\Location;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * The filter bar every analytics screen shares: period, industry, branch. It lives in the query string (so a drill-down, a reload and a shared link keep it)
 * and is always re-read through AnalyticsFilter, never used as typed. The industry and branch lists are what the person can see (tenant and branch scopes).
 */
trait FiltersAnalytics
{
    #[Url(as: 'range')]
    public string $range = 'this_year';

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    #[Url(as: 'industry')]
    public string $industry = '';

    #[Url(as: 'branch')]
    public string $branch = '';

    #[Url(as: 'category')]
    public string $category = '';

    protected function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    protected function filter(): AnalyticsFilter
    {
        return AnalyticsFilter::fromArray(['range' => $this->range, 'from' => $this->from, 'to' => $this->to, 'industry' => $this->industry, 'branch' => $this->branch, 'category' => $this->category]);
    }

    /** @return array<string, mixed> */
    protected function filterOptions(): array
    {
        return [
            'presets' => AnalyticsFilter::PRESETS,
            'industries' => app(IndustryTemplateService::class)->enabled()->map(fn (IndustryTemplate $t) => ['id' => $t->id, 'name' => $t->name])->all(),
            'branches' => Location::query()->orderBy('name')->get(['id', 'name'])->map(fn (Location $l) => ['id' => $l->id, 'name' => $l->name])->all(),
        ];
    }

    /** Drops what is only meaningful with the industry that was just changed. */
    public function updatedIndustry(): void
    {
        $this->category = '';
    }
}

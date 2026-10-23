<?php

namespace App\Modules\Analytics\Http\Livewire;

use App\Modules\Analytics\Services\AnalyticsPages;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** One of the six analytics pages (see AnalyticsPages). The data comes from the same place as the `?format=json` answer. */
#[Title('Analytics')]
class AnalyticsPage extends Component
{
    use FiltersAnalytics;

    public string $page = '';

    #[Url(as: 'sort')]
    public string $sort = 'net_revenue';

    #[Url(as: 'grain')]
    public string $grain = '';

    public function mount(string $page, AnalyticsPages $pages): void
    {
        abort_unless($pages->exists($page), 404);
        abort_unless($pages->allowed($this->user(), $page), 403);

        $this->page = $page;
    }

    public function render(AnalyticsPages $pages): View
    {
        $f = $this->filter();
        $data = $pages->data($this->user(), $this->page, $f, ['sort' => $this->sort, 'granularity' => $this->grain]);

        return view('analytics.page', [
            'f' => $f, 'query' => $f->toQuery(), 'data' => $data, 'def' => AnalyticsPages::PAGES[$this->page], 'slug' => $this->page,
            'links' => collect(AnalyticsPages::PAGES)->filter(fn ($d, $p) => $pages->allowed($this->user(), $p))->all(),
        ] + $this->filterOptions());
    }
}

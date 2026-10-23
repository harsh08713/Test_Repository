<?php

namespace App\Modules\Reports\Http\Livewire;

use App\Models\User;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Services\FollowupAgenda;
use App\Modules\Reports\Exceptions\InvalidReportFilters;
use App\Modules\Reports\Reports\Report;
use App\Modules\Reports\Services\ReportCatalog;
use App\Modules\Reports\Services\ReportExporter;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Shared\Models\Location;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Runs one report on the screen with its filters, headline figures and pages of rows, and asks for an Excel or PDF export (made later in the
 * queue). The report's own query restricts the rows to what this person may see; the filters here are only a request, checked again.
 */
#[Title('Report')]
class ReportRun extends Component
{
    use WithPagination;

    public string $reportKey = '';

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    #[Url(as: 'branch')]
    public string $branch = '';

    #[Url(as: 'person')]
    public string $person = '';

    #[Url(as: 'team')]
    public string $team = '';

    #[Url(as: 'source')]
    public string $source = '';

    #[Url(as: 'choice')]
    public string $choice = '';

    /** @var array<string, string> */
    public array $problems = [];

    public function mount(string $key, ReportCatalog $catalog): void
    {
        $report = $catalog->find($key);
        abort_if($report === null, 404);
        abort_unless($report->canRun($this->actor()), 403);

        $this->reportKey = $key;

        if ($report->startsOnThisMonth() && $this->from === '' && $this->to === '' && ! request()->has('from') && ! request()->has('to')) {
            $this->from = now()->startOfMonth()->toDateString();
            $this->to = now()->toDateString();
        }

        if (! request()->has('choice')) {
            $this->choice = $report->defaultChoice();
        }
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['from', 'to', 'branch', 'person', 'team', 'source', 'choice'], true)) {
            $this->resetPage();
        }
    }

    /** Ask for the report as a file: it is made in the background and appears under “My exports”. */
    public function requestExport(string $format, ReportCatalog $catalog, ReportExporter $exporter): void
    {
        $report = $this->report($catalog);
        $this->problems = [];

        try {
            $exporter->request($report, $this->filters(), $format, $this->actor());
        } catch (AuthorizationException) {
            abort(403);
        } catch (InvalidReportFilters $e) {
            $this->problems = $e->problems;

            return;
        }

        session()->flash('status', 'Your export is being made. It will be under “My exports” in a moment, and stays there for '.ReportExporter::KEEP_DAYS.' days.');
        $this->redirectRoute('reports.exports', navigate: true);
    }

    public function render(ReportCatalog $catalog): View
    {
        $report = $this->report($catalog);
        $user = $this->actor();
        $this->problems = [];
        $rows = null;
        $summary = [];

        try {
            $input = ReportInput::resolve($report, $this->filters(), $user);
            $page = $report->page($input, $user);
            $rows = $page->setCollection($page->getCollection()->map(fn ($row) => $report->present($row)));
            $summary = $report->summary($input, $user);
        } catch (InvalidReportFilters $e) {
            $this->problems = $e->problems;
        }

        $has = fn (string $filter) => in_array($filter, $report->filters(), true);

        return view('reports.run', [
            'report' => $report,
            'rows' => $rows,
            'summary' => $summary,
            'branches' => $has('branch') ? Location::query()->orderBy('name')->pluck('name', 'id')->all() : [],
            'people' => $has('person') ? ReportInput::people($user) : [],
            'teams' => $has('team') ? app(FollowupAgenda::class)->teams($user) : [],
            'sources' => $has('source') ? LeadSource::query()->orderBy('name')->pluck('name', 'id')->all() : [],
            'canExport' => $report->canExport($user),
        ]);
    }

    private function report(ReportCatalog $catalog): Report
    {
        $report = $catalog->find($this->reportKey);
        abort_if($report === null, 404);
        abort_unless($report->canRun($this->actor()), 403);

        return $report;
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @return array<string, string> */
    private function filters(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'branch' => $this->branch, 'person' => $this->person, 'team' => $this->team, 'source' => $this->source, 'choice' => $this->choice];
    }
}

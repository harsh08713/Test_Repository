<?php

namespace App\Modules\Loss\Http\Livewire;

use App\Modules\Industry\Models\WorkflowState;
use App\Modules\Industry\Services\IndustryTemplateService;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Loss\Services\LossFigures;
use App\Modules\Loss\Services\LossWorkflowService;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Support\FinancialYear;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The loss register: incidents of the branches the person works in, and the four loss values for the period — side by side, never added up. */
#[Title('Loss incidents')]
class LossIncidentList extends Component
{
    use WithPagination;

    public const PER_PAGE = 20;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'state')]
    public string $state = '';

    #[Url(as: 'severity')]
    public string $severity = '';

    #[Url(as: 'industry')]
    public string $industry = '';

    #[Url(as: 'branch')]
    public string $branch = '';

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', LossIncident::class);

        if ($this->from === '' || $this->to === '') {
            [$start] = FinancialYear::range(now());
            $this->from = $start->toDateString();
            $this->to = now()->toDateString();
        }
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'state', 'severity', 'industry', 'branch', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function render(LossFigures $figures, LossWorkflowService $workflow, IndustryTemplateService $templates): View
    {
        $from = $this->date($this->from) ?? now()->startOfYear();
        $to = $this->date($this->to) ?? now();
        $like = '%'.addcslashes(trim($this->search), '%_\\').'%';
        $industry = ctype_digit($this->industry) ? (int) $this->industry : null;
        $branch = ctype_digit($this->branch) ? (int) $this->branch : null;

        $incidents = LossIncident::query()
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('incident_number', 'like', $like)->orWhere('title', 'like', $like)))
            ->when($this->state !== '', fn (Builder $q) => $q->where('state', $this->state))
            ->when(array_key_exists($this->severity, LossIncident::SEVERITIES), fn (Builder $q) => $q->where('severity', $this->severity))
            ->when($industry !== null, fn (Builder $q) => $q->where('industry_template_id', $industry))
            ->when($branch !== null, fn (Builder $q) => $q->where('location_id', $branch))
            ->with(['template:id,name', 'category:id,name', 'location:id,name', 'owner:id,name'])
            ->withSum(['impactLines as actual_amount' => fn ($q) => $q->where('impact_type', 'actual')->where('validation_state', 'validated')->whereNull('superseded_at')], 'amount')
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(self::PER_PAGE);

        return view('loss.list', [
            'incidents' => $incidents,
            'figures' => $figures->forPeriod($from, $to, $industry, $branch),
            'stateNames' => $incidents->getCollection()->mapWithKeys(fn (LossIncident $i) => [$i->id => $workflow->stateName($i)])->all(),
            'templates' => $templates->enabled(),
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'severities' => LossIncident::SEVERITIES,
            // the states of every workflow in use: the default one and any template's own
            'stateOptions' => WorkflowState::query()->orderBy('sort_order')->pluck('name', 'code')->all(),
            'canReport' => Gate::allows('create', LossIncident::class),
        ]);
    }

    private function date(string $value): ?Carbon
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? Carbon::instance($parsed) : null;
    }
}

<?php

namespace App\Modules\CRM\Http\Livewire\Leads;

use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\CRM\Services\LeadFilters;
use App\Modules\CRM\Services\LeadTransferService;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The leads a person may see — their own, their team's, or everyone's (LeadAccess) — with the filters a working day
 * needs, and for those who may hand leads out, a way to select several and transfer them in one go.
 *
 * The filters only ever narrow what LeadAccess allows. The ids ticked for a transfer are just a request: the transfer
 * service re-reads every one of them through the tenant scopes and checks it on its own.
 */
#[Title('Leads')]
class LeadList extends Component
{
    use ScopesToPeople, WithPagination;

    public const PER_PAGE = 20;

    #[Url(as: 'q')]
    public string $search = '';

    /** 'open' (still being worked), '' (all), or one stage. */
    #[Url(as: 'stage')]
    public string $stage = 'open';

    /** A user id, or 'none' for the unassigned pool. */
    #[Url(as: 'owner')]
    public string $owner = '';

    #[Url(as: 'branch')]
    public string $branch = '';

    #[Url(as: 'source')]
    public string $source = '';

    /** 'new' (a prospect), 'existing' (an inquiry from a customer) or '' (both). */
    #[Url(as: 'type')]
    public string $type = '';

    /** Next follow-up: '', 'overdue', 'today', 'week' or 'none'. */
    #[Url(as: 'due')]
    public string $due = '';

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    /** @var array<array-key, mixed> ids of the leads ticked for a transfer, as the browser sent them (they may be on other pages) */
    public array $selected = [];

    public bool $transferOpen = false;

    public string $transferTo = '';

    public string $reason = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Lead::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'stage', 'owner', 'branch', 'source', 'type', 'due', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function setStage(string $stage): void
    {
        $this->stage = $stage === 'open' || $stage === '' || LeadStage::tryFrom($stage) !== null ? $stage : 'open';
        $this->resetPage();
    }

    // ---- selection and transfer -----------------------------------------------------------------------------

    /** Tick every lead on the page that is showing. */
    public function selectPage(): void
    {
        Gate::authorize('transferAny', Lead::class);

        $ids = $this->filtered()->forPage($this->getPage(), self::PER_PAGE)->pluck('leads.id')->map(fn ($id) => (string) $id)->all();
        $this->selected = array_slice(array_values(array_unique([...$this->selectedIds(), ...$ids])), 0, LeadTransferService::MAX_PER_TRANSFER);
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function openTransfer(): void
    {
        Gate::authorize('transferAny', Lead::class);

        $this->resetErrorBag();
        $this->transferTo = '';
        $this->reason = '';

        if ($this->selectedIds() === []) {
            $this->addError('leads', 'Tick the leads you want to transfer first.');

            return;
        }

        $this->transferOpen = true;
    }

    public function closeTransfer(): void
    {
        $this->transferOpen = false;
        $this->transferTo = '';
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function transfer(LeadTransferService $service): void
    {
        Gate::authorize('transferAny', Lead::class);
        $this->resetErrorBag();

        if (! ctype_digit($this->transferTo)) {
            $this->addError('to_user_id', 'Choose who the leads go to.');

            return;
        }

        try {
            // The service checks every lead and the new owner itself, whatever was ticked or sent.
            $moved = $service->transfer($this->selectedIds(), (int) $this->transferTo, $this->reason, $this->actor());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());   // keyed leads / to_user_id / reason, as the dialog shows them

            return;
        }

        $count = $moved->count();
        $name = $this->transferTargets()[(int) $this->transferTo] ?? 'the new owner';

        $this->selected = [];
        $this->closeTransfer();
        session()->flash('status', $count === 1 ? "1 lead transferred to {$name}." : "{$count} leads transferred to {$name}.");
    }

    /** @return list<string> the ticked ids, digits only, capped */
    private function selectedIds(): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($id) => is_scalar($id) ? (string) $id : '', $this->selected), fn (string $id) => ctype_digit($id))));
    }

    /**
     * Who the signed-in person may hand leads to: themselves and the people they manage (anyone, for `sales.scope_all`).
     *
     * @return array<int, string>
     */
    private function transferTargets(): array
    {
        $actor = $this->actor();
        $people = $this->visiblePeople();
        $managed = app(SalesScope::class)->managedUserIds($actor);

        return $managed === null ? $people : array_intersect_key($people, array_flip([...$managed, (int) $actor->getKey()]));
    }

    // ---- the list -------------------------------------------------------------------------------------------

    /**
     * Everything the person may see, narrowed by every filter except the stage (the stage tabs count over this).
     *
     * @return Builder<Lead>
     */
    private function base(): Builder
    {
        // who may see a lead is LeadAccess; the filters (shared with the mobile API) only narrow that
        return app(LeadFilters::class)->apply(app(LeadAccess::class)->restrict(Lead::query(), $this->actor()), [
            'search' => $this->search, 'owner' => $this->owner, 'branch' => $this->branch, 'source' => $this->source, 'type' => $this->type,
            'due' => $this->due, 'from' => $this->from, 'to' => $this->to,
        ]);
    }

    /** @return Builder<Lead> */
    private function filtered(): Builder
    {
        $stage = LeadStage::tryFrom($this->stage);

        return $this->base()
            ->when($this->stage === 'open', fn (Builder $q) => $q->open())
            ->when($stage !== null, fn (Builder $q) => $q->where('leads.stage', $stage?->value))
            // what needs attention first when the list is about follow-ups; otherwise the newest lead first
            ->when(in_array($this->due, ['overdue', 'today', 'week'], true), fn (Builder $q) => $q->orderBy('leads.next_followup_at'))
            ->orderByDesc('leads.lead_date')->orderByDesc('leads.id');
    }

    public function render(): View
    {
        $actor = $this->actor();
        $canTransfer = Gate::allows('transferAny', Lead::class);

        $leads = $this->filtered()->with(['assignee:id,name', 'location:id,name,code', 'source:id,name'])->paginate(self::PER_PAGE);

        /** @var array<string, int> $counts */
        $counts = $this->base()->toBase()->selectRaw('leads.stage as stage, count(*) as total')->groupBy('leads.stage')
            ->pluck('total', 'stage')->map(fn ($n) => (int) $n)->all();

        $open = 0;
        $tabs = [];
        foreach (LeadStage::cases() as $stage) {
            $count = $counts[$stage->value] ?? 0;
            $open += $stage->isOpen() ? $count : 0;

            // Deal, Quotation and Won appear once leads start to be converted (later phases)
            if (! $stage->isSetBySystem() || $count > 0 || $this->stage === $stage->value) {
                $tabs[] = [$stage->value, $stage->label(), $count];
            }
        }
        $tabs = [['open', 'Open', $open], ...$tabs, ['', 'All', array_sum($counts)]];

        return view('crm.leads.list', [
            'leads' => $leads,
            'tabs' => $tabs,
            'people' => $this->visiblePeople(),
            'showPool' => $this->visibleIds() === null || $actor->can('lead.assign'),
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'sources' => LeadSource::query()->orderBy('name')->pluck('name', 'id')->all(),
            'canCreate' => Gate::allows('create', Lead::class),
            'canCreateInquiry' => Gate::allows('createInquiry', Lead::class),
            'canImport' => Gate::allows('import', Lead::class),
            'canTransfer' => $canTransfer,
            'transferTargets' => $canTransfer && $this->transferOpen ? $this->transferTargets() : [],
            'selectedCount' => count($this->selectedIds()),
            'maxTransfer' => LeadTransferService::MAX_PER_TRANSFER,
            'now' => now(),
        ]);
    }
}

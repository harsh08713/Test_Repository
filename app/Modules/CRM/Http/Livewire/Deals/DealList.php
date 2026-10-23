<?php

namespace App\Modules\CRM\Http\Livewire\Deals;

use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Services\DealAccess;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The deals a person may see — their own, their team's, everyone's (DealAccess), or one they are asked to approve — with the
 * filters a pipeline review needs and what the open ones add up to. The filters only ever narrow what DealAccess allows.
 */
#[Title('Deals')]
class DealList extends Component
{
    use ScopesToPeople, WithPagination;

    public const PER_PAGE = 20;

    #[Url(as: 'q')]
    public string $search = '';

    /** 'open' (still being worked), '' (all), or one stage. */
    #[Url(as: 'stage')]
    public string $stage = 'open';

    /** A user id. */
    #[Url(as: 'owner')]
    public string $owner = '';

    #[Url(as: 'branch')]
    public string $branch = '';

    /** An approval status: draft, pending, approved or rejected. */
    #[Url(as: 'approval')]
    public string $approval = '';

    /** Expected close: '', 'late' (date passed, still open), 'month' (this calendar month). */
    #[Url(as: 'closing')]
    public string $closing = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Deal::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'stage', 'owner', 'branch', 'approval', 'closing'], true)) {
            $this->resetPage();
        }
    }

    public function setStage(string $stage): void
    {
        $this->stage = $stage === 'open' || $stage === '' || DealStage::tryFrom($stage) !== null ? $stage : 'open';
        $this->resetPage();
    }

    /**
     * Everything the person may see, narrowed by every filter except the stage (the stage tabs count over this).
     *
     * @return Builder<Deal>
     */
    private function base(): Builder
    {
        $like = '%'.addcslashes(trim($this->search), '%_\\').'%';
        $digits = preg_replace('/\D+/', '', $this->search) ?? '';

        return app(DealAccess::class)->restrict(Deal::query(), $this->actor())
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('deals.party_name', 'like', $like)->orWhere('deals.deal_number', 'like', $like)->orWhere('deals.contact_person', 'like', $like)
                ->when(strlen($digits) >= 4, fn (Builder $m) => $m->orWhere('deals.mobile', 'like', "%{$digits}%"))))
            ->when(ctype_digit($this->owner), fn (Builder $q) => $q->where('deals.assigned_to', (int) $this->owner))
            ->when(ctype_digit($this->branch), fn (Builder $q) => $q->where('deals.location_id', (int) $this->branch))
            ->when(ApprovalStatus::tryFrom($this->approval) !== null, fn (Builder $q) => $q->where('deals.approval_status', $this->approval))
            ->when($this->closing === 'late', fn (Builder $q) => $q->open()->where('deals.expected_close_date', '<', now()->toDateString()))
            ->when($this->closing === 'month', fn (Builder $q) => $q->whereBetween('deals.expected_close_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]));
    }

    /** @return Builder<Deal> */
    private function filtered(): Builder
    {
        $stage = DealStage::tryFrom($this->stage);

        return $this->base()
            ->when($this->stage === 'open', fn (Builder $q) => $q->open())
            ->when($stage !== null, fn (Builder $q) => $q->where('deals.stage', $stage?->value))
            ->orderBy('deals.expected_close_date')->orderByDesc('deals.id');
    }

    public function render(): View
    {
        $deals = $this->filtered()->with(['assignee:id,name', 'location:id,name,code', 'customer:id,code,name'])->withCount('items')->paginate(self::PER_PAGE);

        /** @var array<string, int> $counts */
        $counts = $this->base()->toBase()->selectRaw('deals.stage as stage, count(*) as total')->groupBy('deals.stage')
            ->pluck('total', 'stage')->map(fn ($n) => (int) $n)->all();

        $open = 0;
        $tabs = [];
        foreach (DealStage::cases() as $stage) {
            $count = $counts[$stage->value] ?? 0;
            $open += $stage->isOpen() ? $count : 0;

            // Quotation and Won appear once documents are made from deals (later phases)
            if (! $stage->isSetBySystem() || $count > 0 || $this->stage === $stage->value) {
                $tabs[] = [$stage->value, $stage->label(), $count];
            }
        }
        $tabs = [['open', 'Open', $open], ...$tabs, ['', 'All', array_sum($counts)]];

        // what the open deals add up to, and what that is worth weighted by how likely each is
        $pipeline = $this->base()->open()->toBase()->selectRaw('coalesce(sum(deals.expected_value), 0) as value, coalesce(sum(deals.expected_value * deals.probability / 100), 0) as weighted')->first();

        return view('crm.deals.list', [
            'deals' => $deals,
            'tabs' => $tabs,
            'pipelineValue' => (string) ($pipeline->value ?? '0'),
            'pipelineWeighted' => (string) ($pipeline->weighted ?? '0'),
            'pipelineCount' => $open,
            'people' => $this->visiblePeople(),
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'approvals' => ApprovalStatus::cases(),
            'today' => now()->startOfDay(),
        ]);
    }
}

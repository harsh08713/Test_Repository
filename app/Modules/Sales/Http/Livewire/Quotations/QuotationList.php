<?php

namespace App\Modules\Sales\Http\Livewire\Quotations;

use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\Sales\Enums\QuotationState;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Services\QuotationAccess;
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
 * The quotations a person may see — those of their own leads, their team's, everyone's (QuotationAccess), or one they are asked to
 * approve. The "Live" tab lists draft, sent and accepted revisions; the others list one state; "All" lists every revision. The filters
 * only ever narrow what QuotationAccess allows.
 */
#[Title('Quotations')]
class QuotationList extends Component
{
    use ScopesToPeople, WithPagination;

    public const PER_PAGE = 20;

    #[Url(as: 'q')]
    public string $search = '';

    /** 'live' (draft, sent or accepted), '' (every revision), or one state. */
    #[Url(as: 'state')]
    public string $state = 'live';

    #[Url(as: 'branch')]
    public string $branch = '';

    /** An approval status: draft, pending, approved or rejected. */
    #[Url(as: 'approval')]
    public string $approval = '';

    /** '' or 'expiring' (sent, valid for another week at most). */
    #[Url(as: 'validity')]
    public string $validity = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Quotation::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'state', 'branch', 'approval', 'validity'], true)) {
            $this->resetPage();
        }
    }

    public function setState(string $state): void
    {
        $this->state = $state === 'live' || $state === '' || QuotationState::tryFrom($state) !== null ? $state : 'live';
        $this->resetPage();
    }

    /**
     * Everything the person may see, narrowed by every filter except the state (the tabs count over this).
     *
     * @return Builder<Quotation>
     */
    private function base(): Builder
    {
        $like = '%'.addcslashes(trim($this->search), '%_\\').'%';

        return app(QuotationAccess::class)->restrict(Quotation::query(), $this->actor())
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('quotations.party_name', 'like', $like)->orWhere('quotations.quotation_number', 'like', $like)->orWhere('quotations.contact_person', 'like', $like)))
            ->when(ctype_digit($this->branch), fn (Builder $q) => $q->where('quotations.location_id', (int) $this->branch))
            ->when(ApprovalStatus::tryFrom($this->approval) !== null, fn (Builder $q) => $q->where('quotations.approval_status', $this->approval))
            ->when($this->validity === 'expiring', fn (Builder $q) => $q->where('quotations.state', QuotationState::Sent->value)
                ->whereBetween('quotations.valid_until', [now()->toDateString(), now()->addDays(7)->toDateString()]));
    }

    /** @return Builder<Quotation> */
    private function filtered(): Builder
    {
        $state = QuotationState::tryFrom($this->state);

        return $this->base()
            ->when($this->state === 'live', fn (Builder $q) => $q->whereIn('quotations.state', [QuotationState::Draft->value, QuotationState::Sent->value, QuotationState::Accepted->value]))
            ->when($state !== null, fn (Builder $q) => $q->where('quotations.state', $state?->value))
            ->orderByDesc('quotations.id');
    }

    public function render(): View
    {
        $quotations = $this->filtered()->with(['location:id,name,code'])->paginate(self::PER_PAGE);

        /** @var array<string, int> $counts */
        $counts = $this->base()->toBase()->selectRaw('quotations.state as state, count(*) as total')->groupBy('quotations.state')
            ->pluck('total', 'state')->map(fn ($n) => (int) $n)->all();

        $live = 0;
        $tabs = [];
        foreach (QuotationState::cases() as $state) {
            $count = $counts[$state->value] ?? 0;
            $live += $state->isLive() ? $count : 0;

            if ($state !== QuotationState::Superseded || $count > 0 || $this->state === $state->value) {
                $tabs[] = [$state->value, $state->label(), $count];
            }
        }
        $tabs = [['live', 'Live', $live], ...$tabs, ['', 'All', array_sum($counts)]];

        // what is standing with customers right now: sent and not yet answered
        $out = $this->base()->where('quotations.state', QuotationState::Sent->value)->toBase()
            ->selectRaw('count(*) as total, coalesce(sum(quotations.grand_total), 0) as value')->first();

        return view('sales.quotations.list', [
            'quotations' => $quotations,
            'tabs' => $tabs,
            'outCount' => (int) ($out->total ?? 0),
            'outValue' => (string) ($out->value ?? '0'),
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'approvals' => ApprovalStatus::cases(),
            'today' => now()->startOfDay(),
        ]);
    }
}

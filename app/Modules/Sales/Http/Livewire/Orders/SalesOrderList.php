<?php

namespace App\Modules\Sales\Http\Livewire\Orders;

use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\Sales\Enums\SalesOrderState;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderAccess;
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
 * The sales orders a person may see — the ones credited to them, their team's, everyone's (SalesOrderAccess; Accounts and Operations see all) or
 * one they are asked to approve. The "Live" tab lists every order that is not cancelled; the others list one state; "All" lists everything. The
 * filters only ever narrow what SalesOrderAccess allows.
 */
#[Title('Sales orders')]
class SalesOrderList extends Component
{
    use ScopesToPeople, WithPagination;

    public const PER_PAGE = 20;

    #[Url(as: 'q')]
    public string $search = '';

    /** 'live' (not cancelled), '' (every order), or one state. */
    #[Url(as: 'state')]
    public string $state = 'live';

    #[Url(as: 'branch')]
    public string $branch = '';

    /** An approval status: draft, pending, approved or rejected. */
    #[Url(as: 'approval')]
    public string $approval = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', SalesOrder::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'state', 'branch', 'approval'], true)) {
            $this->resetPage();
        }
    }

    public function setState(string $state): void
    {
        $this->state = $state === 'live' || $state === '' || SalesOrderState::tryFrom($state) !== null ? $state : 'live';
        $this->resetPage();
    }

    /**
     * Everything the person may see, narrowed by every filter except the state (the tabs count over this).
     *
     * @return Builder<SalesOrder>
     */
    private function base(): Builder
    {
        $like = '%'.addcslashes(trim($this->search), '%_\\').'%';

        return app(SalesOrderAccess::class)->restrict(SalesOrder::query(), $this->actor())
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('sales_orders.party_name', 'like', $like)->orWhere('sales_orders.so_number', 'like', $like)->orWhere('sales_orders.customer_po_number', 'like', $like)))
            ->when(ctype_digit($this->branch), fn (Builder $q) => $q->where('sales_orders.location_id', (int) $this->branch))
            ->when(ApprovalStatus::tryFrom($this->approval) !== null, fn (Builder $q) => $q->where('sales_orders.approval_status', $this->approval));
    }

    public function render(): View
    {
        $state = SalesOrderState::tryFrom($this->state);

        $orders = $this->base()
            ->when($this->state === 'live', fn (Builder $q) => $q->where('sales_orders.state', '<>', SalesOrderState::Cancelled->value))
            ->when($state !== null, fn (Builder $q) => $q->where('sales_orders.state', $state?->value))
            ->with(['location:id,name,code', 'owner:id,name'])->orderByDesc('sales_orders.id')->paginate(self::PER_PAGE);

        /** @var array<string, int> $counts */
        $counts = $this->base()->toBase()->selectRaw('sales_orders.state as state, count(*) as total')->groupBy('sales_orders.state')
            ->pluck('total', 'state')->map(fn ($n) => (int) $n)->all();

        $live = ($counts['open'] ?? 0) + ($counts['invoiced'] ?? 0);
        $tabs = [['live', 'Live', $live]];
        foreach (SalesOrderState::cases() as $case) {
            $tabs[] = [$case->value, $case->label(), $counts[$case->value] ?? 0];
        }
        $tabs[] = ['', 'All', array_sum($counts)];

        // what is on the books: approved orders that are not cancelled
        $standing = $this->base()->standing()->toBase()->selectRaw('count(*) as total, coalesce(sum(sales_orders.grand_total), 0) as value')->first();
        $waiting = $this->base()->awaitingApproval()->count();

        return view('sales.orders.list', [
            'orders' => $orders,
            'tabs' => $tabs,
            'standingCount' => (int) ($standing->total ?? 0),
            'standingValue' => (string) ($standing->value ?? '0'),
            'waitingCount' => $waiting,
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'approvals' => ApprovalStatus::cases(),
            'canCreate' => Gate::allows('create', SalesOrder::class),
        ]);
    }
}

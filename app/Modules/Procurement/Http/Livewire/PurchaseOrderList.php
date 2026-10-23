<?php

namespace App\Modules\Procurement\Http\Livewire;

use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Purchase orders of the branches the person works in, newest first. */
#[Title('Purchase orders')]
class PurchaseOrderList extends Component
{
    use WithPagination;

    public const PER_PAGE = 20;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'approval')]
    public string $approval = '';

    #[Url(as: 'branch')]
    public string $branch = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', PurchaseOrder::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'approval', 'branch'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $like = '%'.addcslashes(trim($this->search), '%_\\').'%';

        $orders = PurchaseOrder::query()
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('po_number', 'like', $like)->orWhere('supplier_name', 'like', $like)))
            ->when(in_array($this->approval, ['draft', 'pending', 'approved', 'rejected'], true), fn (Builder $q) => $q->where('approval_status', $this->approval))
            ->when($this->approval === 'cancelled', fn (Builder $q) => $q->where('state', 'cancelled'))
            ->when(ctype_digit($this->branch), fn (Builder $q) => $q->where('location_id', (int) $this->branch))
            ->with('location:id,name,code')->orderByDesc('id')->paginate(self::PER_PAGE);

        return view('procurement.order-list', [
            'orders' => $orders,
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'canCreate' => Gate::allows('create', PurchaseOrder::class),
        ]);
    }
}

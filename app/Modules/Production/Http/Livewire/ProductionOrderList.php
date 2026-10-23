<?php

namespace App\Modules\Production\Http\Livewire;

use App\Modules\Production\Models\ProductionOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Production orders: searched by number or item, filtered by state, with what has been made so far. */
#[Title('Production orders')]
class ProductionOrderList extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'state')]
    public string $state = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', ProductionOrder::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'state'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $term = trim($this->search);
        $like = '%'.addcslashes($term, '%_\\').'%';

        $orders = ProductionOrder::query()->with('location:id,name')
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('order_number', 'like', $like)->orWhere('item_name', 'like', $like)))
            ->when(array_key_exists($this->state, ProductionOrder::STATES), fn (Builder $q) => $q->where('state', $this->state))
            ->orderByDesc('id')->paginate(self::PER_PAGE);

        $made = DB::table('production_outputs')->whereIn('production_order_id', $orders->pluck('id'))->groupBy('production_order_id')
            ->selectRaw('production_order_id as id, sum(good_quantity) as good, sum(rejected_quantity) as rejected')->get()->keyBy('id');

        return view('production.order-list', ['orders' => $orders, 'made' => $made, 'states' => ProductionOrder::STATES, 'canCreate' => Gate::allows('create', ProductionOrder::class)]);
    }
}

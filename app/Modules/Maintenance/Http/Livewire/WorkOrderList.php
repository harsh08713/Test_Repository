<?php

namespace App\Modules\Maintenance\Http\Livewire;

use App\Modules\Maintenance\Models\MaintenanceOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Maintenance work orders: searched by number, asset or title; filtered by state, type, and mine. Overdue ones are marked. */
#[Title('Maintenance work orders')]
class WorkOrderList extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'state')]
    public string $state = '';

    #[Url(as: 'type')]
    public string $type = '';

    #[Url(as: 'mine')]
    public bool $mine = false;

    public function mount(): void
    {
        Gate::authorize('viewAny', MaintenanceOrder::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'state', 'type', 'mine'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $term = trim($this->search);
        $like = '%'.addcslashes($term, '%_\\').'%';

        return view('maintenance.order-list', [
            'orders' => MaintenanceOrder::query()->with(['asset:id,code,name', 'assignee:id,name'])
                ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('order_number', 'like', $like)->orWhere('title', 'like', $like)->orWhereHas('asset', fn (Builder $a) => $a->where('name', 'like', $like)->orWhere('code', 'like', $like))))
                ->when($this->state === 'open_work', fn (Builder $q) => $q->whereIn('state', ['open', 'in_progress']))
                ->when(array_key_exists($this->state, MaintenanceOrder::STATES), fn (Builder $q) => $q->where('state', $this->state))
                ->when(array_key_exists($this->type, MaintenanceOrder::TYPES), fn (Builder $q) => $q->where('type', $this->type))
                ->when($this->mine, fn (Builder $q) => $q->where('assigned_to', Auth::id()))
                ->orderByRaw("field(state, 'in_progress', 'open', 'completed', 'cancelled')")->orderByRaw("field(priority, 'urgent', 'high', 'normal', 'low')")->orderBy('due_on')->orderByDesc('id')->paginate(self::PER_PAGE),
            'states' => MaintenanceOrder::STATES, 'types' => MaintenanceOrder::TYPES, 'priorities' => MaintenanceOrder::PRIORITIES, 'canCreate' => Gate::allows('create', MaintenanceOrder::class),
        ]);
    }
}

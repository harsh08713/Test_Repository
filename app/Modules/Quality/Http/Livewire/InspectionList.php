<?php

namespace App\Modules\Quality\Http\Livewire;

use App\Modules\Quality\Models\Inspection;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Inspections: searched by number, item or batch, filtered by state. */
#[Title('Inspections')]
class InspectionList extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'state')]
    public string $state = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Inspection::class);
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

        return view('quality.inspection-list', [
            'inspections' => Inspection::query()->with(['batch:id,batch_no', 'location:id,name'])
                ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('inspection_number', 'like', $like)->orWhere('item_name', 'like', $like)->orWhereHas('batch', fn (Builder $b) => $b->where('batch_no', 'like', $like))))
                ->when(array_key_exists($this->state, Inspection::STATES), fn (Builder $q) => $q->where('state', $this->state))
                ->orderByDesc('id')->paginate(self::PER_PAGE),
            'states' => Inspection::STATES, 'canCreate' => Gate::allows('create', Inspection::class),
        ]);
    }
}

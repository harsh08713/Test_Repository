<?php

namespace App\Modules\Production\Http\Livewire;

use App\Modules\Production\Models\Bom;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Bills of materials: searched by code or item, with the approval state and whether each is in use. */
#[Title('Bills of materials')]
class BomList extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Bom::class);
    }

    public function updating(string $property): void
    {
        if ($property === 'search') {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $term = trim($this->search);
        $like = '%'.addcslashes($term, '%_\\').'%';

        return view('production.bom-list', [
            'boms' => Bom::query()->with('item:id,code,name')->withCount('components')
                ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('code', 'like', $like)->orWhereHas('item', fn (Builder $i) => $i->where('name', 'like', $like)->orWhere('code', 'like', $like))))
                ->orderByDesc('id')->paginate(self::PER_PAGE),
            'canCreate' => Gate::allows('create', Bom::class),
        ]);
    }
}

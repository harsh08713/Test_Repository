<?php

namespace App\Modules\Maintenance\Http\Livewire;

use App\Modules\Maintenance\Models\Asset;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Assets: searched by code, name or serial number, with how many work orders are open on each. */
#[Title('Assets')]
class AssetList extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Asset::class);
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

        return view('maintenance.asset-list', [
            'assets' => Asset::query()->with('location:id,name')->withCount(['orders as open_orders_count' => fn ($q) => $q->whereIn('state', ['open', 'in_progress'])])
                ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like)->orWhere('serial_no', 'like', $like)))
                ->orderBy('code')->paginate(self::PER_PAGE),
            'categories' => Asset::CATEGORIES, 'criticality' => Asset::CRITICALITY, 'canCreate' => Gate::allows('create', Asset::class),
        ]);
    }
}

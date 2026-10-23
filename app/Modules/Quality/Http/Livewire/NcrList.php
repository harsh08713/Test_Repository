<?php

namespace App\Modules\Quality\Http\Livewire;

use App\Modules\Quality\Models\NonConformance;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Non-conformance reports: searched by number, item or batch, filtered by state and severity. */
#[Title('Non-conformances')]
class NcrList extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'state')]
    public string $state = '';

    #[Url(as: 'severity')]
    public string $severity = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', NonConformance::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'state', 'severity'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $term = trim($this->search);
        $like = '%'.addcslashes($term, '%_\\').'%';

        return view('quality.ncr-list', [
            'ncrs' => NonConformance::query()->with(['batch:id,batch_no', 'location:id,name'])
                ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('ncr_number', 'like', $like)->orWhere('item_name', 'like', $like)->orWhereHas('batch', fn (Builder $b) => $b->where('batch_no', 'like', $like))))
                ->when(array_key_exists($this->state, NonConformance::STATES), fn (Builder $q) => $q->where('state', $this->state))
                ->when(array_key_exists($this->severity, NonConformance::SEVERITIES), fn (Builder $q) => $q->where('severity', $this->severity))
                ->orderByDesc('id')->paginate(self::PER_PAGE),
            'states' => NonConformance::STATES, 'severities' => NonConformance::SEVERITIES, 'canCreate' => Gate::allows('create', NonConformance::class),
        ]);
    }
}

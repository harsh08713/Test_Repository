<?php

namespace App\Modules\Quality\Http\Livewire;

use App\Modules\Quality\Models\InspectionPlan;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Inspection plans: searched by code, name or item, with the stage and the approval state. */
#[Title('Inspection plans')]
class PlanList extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', InspectionPlan::class);
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

        return view('quality.plan-list', [
            'plans' => InspectionPlan::query()->with('item:id,code,name')->withCount('parameters')
                ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like)->orWhereHas('item', fn (Builder $i) => $i->where('name', 'like', $like))))
                ->orderByDesc('id')->paginate(self::PER_PAGE),
            'stages' => InspectionPlan::STAGES, 'canCreate' => Gate::allows('create', InspectionPlan::class),
        ]);
    }
}

<?php

namespace App\Modules\Maintenance\Http\Livewire;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Maintenance\Models\MaintenancePlan;
use App\Modules\Maintenance\Services\MaintenancePlanService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Preventive schedules: what is done to which asset how often, and when it next falls due; switched on and off from here. */
#[Title('Preventive schedules')]
class ScheduleList extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', MaintenancePlan::class);
    }

    public function updating(string $property): void
    {
        if ($property === 'search') {
            $this->resetPage();
        }
    }

    public function toggle(MaintenancePlanService $service, int $id): void
    {
        $plan = MaintenancePlan::query()->findOrFail($id);
        Gate::authorize('update', $plan);

        try {
            $service->setStatus($plan, $plan->status !== 'active', Auth::user());
        } catch (FieldRuleException $e) {
            $this->addError('workflow', $e->getMessage());
        }
    }

    public function render(): View
    {
        $term = trim($this->search);
        $like = '%'.addcslashes($term, '%_\\').'%';

        return view('maintenance.schedule-list', [
            'plans' => MaintenancePlan::query()->with('asset:id,code,name')
                ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like)->orWhereHas('asset', fn (Builder $a) => $a->where('name', 'like', $like)->orWhere('code', 'like', $like))))
                ->orderBy('next_due_on')->paginate(self::PER_PAGE),
            'canCreate' => Gate::allows('create', MaintenancePlan::class), 'canEdit' => Gate::allows('update', new MaintenancePlan),
        ]);
    }
}

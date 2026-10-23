<?php

namespace App\Modules\CRM\Http\Livewire\RoutePlans;

use App\Modules\CRM\Enums\RoutePlanStatus;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\RoutePlan;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Route plans of the people the signed-in person manages (their own for a salesperson), by day, person, branch and state. */
#[Title('Route plans')]
class RoutePlanList extends Component
{
    use ScopesToPeople, WithPagination;

    /** From this day on (YYYY-MM-DD); today by default so the list is about what is coming. */
    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    #[Url(as: 'person')]
    public string $person = '';

    #[Url(as: 'branch')]
    public string $branch = '';

    #[Url(as: 'state')]
    public string $state = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', RoutePlan::class);

        if ($this->from === '') {
            $this->from = now()->toDateString();
        }
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['from', 'to', 'person', 'branch', 'state'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $valid = fn (string $day) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && Carbon::hasFormat($day, 'Y-m-d');

        $plans = $this->limitToVisible(RoutePlan::query())
            ->with(['user:id,name', 'location:id,name', 'attendance:id,route_plan_id,marked_at,decision'])
            ->withCount('stops')
            ->when($valid($this->from), fn ($q) => $q->where('plan_date', '>=', $this->from))
            ->when($valid($this->to), fn ($q) => $q->where('plan_date', '<=', $this->to))
            ->when(ctype_digit($this->person), fn ($q) => $q->where('user_id', (int) $this->person))
            ->when(ctype_digit($this->branch), fn ($q) => $q->where('location_id', (int) $this->branch))
            ->when(RoutePlanStatus::tryFrom($this->state) !== null, fn ($q) => $q->where('status', $this->state))
            ->orderBy('plan_date')
            ->orderBy('user_id')
            ->paginate(15);

        return view('crm.plans.list', [
            'plans' => $plans,
            'people' => $this->visiblePeople(),
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'states' => RoutePlanStatus::cases(),
            'canCreate' => Gate::allows('create', RoutePlan::class),
        ]);
    }
}

<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobOpening;
use App\Modules\HR\Services\RecruitmentService;
use App\Modules\Masters\Models\Department;
use App\Modules\Masters\Models\Designation;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Job openings: the list with how many people applied and were hired, and a panel to add or change one. */
#[Title('Job openings')]
class OpeningList extends Component
{
    use ActsAsStaff;

    #[Url(as: 'state')]
    public string $state = '';

    public bool $formOpen = false;

    public ?int $editingId = null;

    /** @var array<string, string> */
    public array $f = [];

    public function mount(): void
    {
        Gate::authorize('viewAny', JobOpening::class);
    }

    public function create(RecruitmentService $service): void
    {
        Gate::authorize('create', JobOpening::class);
        $this->resetErrorBag();
        $this->editingId = null;
        $this->f = ['code' => $service->suggestCode(), 'title' => '', 'location_id' => '', 'department_id' => '', 'designation_id' => '', 'hiring_manager_id' => '', 'positions' => '1', 'description' => ''];
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $o = JobOpening::query()->findOrFail($id);
        Gate::authorize('update', $o);
        $this->resetErrorBag();
        $this->editingId = $o->id;
        $this->f = ['code' => $o->code, 'title' => $o->title, 'location_id' => (string) $o->location_id, 'department_id' => (string) $o->department_id, 'designation_id' => (string) $o->designation_id,
            'hiring_manager_id' => (string) $o->hiring_manager_id, 'positions' => (string) $o->positions, 'description' => (string) $o->description];
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->formOpen = false;
        $this->resetErrorBag();
    }

    public function save(RecruitmentService $service): void
    {
        $editing = $this->editingId === null ? null : JobOpening::query()->findOrFail($this->editingId);

        if ($this->attempt(fn () => $service->saveOpening($this->f, $this->actor(), $editing), $editing === null ? 'Opening added as a draft.' : 'Opening saved.')) {
            $this->formOpen = false;
        }
    }

    public function render(RecruitmentService $service): View
    {
        $openings = JobOpening::query()->with(['department:id,name'])->when(array_key_exists($this->state, JobOpening::STATES), fn ($q) => $q->where('state', $this->state))->orderByRaw("field(state, 'open', 'draft', 'closed')")->orderByDesc('id')->get();
        $counts = JobApplication::query()->whereIn('job_opening_id', $openings->pluck('id'))->toBase()->groupBy('job_opening_id', 'stage')->selectRaw('job_opening_id, stage, count(*) as n')->get();

        return view('hr.openings', [
            'openings' => $openings, 'states' => JobOpening::STATES, 'canCreate' => Gate::allows('create', JobOpening::class),
            'applied' => $counts->groupBy('job_opening_id')->map(fn ($rows) => (int) $rows->sum('n')), 'hired' => $counts->where('stage', 'hired')->pluck('n', 'job_opening_id'),
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'departments' => Department::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'designations' => Designation::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'managers' => Employee::query()->whereIn('employment_status', ['active', 'notice'])->orderBy('name')->get(['id', 'code', 'name'])->mapWithKeys(fn (Employee $e) => [$e->id => $e->code.' · '.$e->name])->all(),
        ]);
    }
}

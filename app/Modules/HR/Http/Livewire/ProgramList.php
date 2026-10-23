<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\TrainingEnrolment;
use App\Modules\HR\Models\TrainingProgram;
use App\Modules\HR\Services\TrainingService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Training programs: what is planned, who is going, and a panel to add or change one. */
#[Title('Training programs')]
class ProgramList extends Component
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
        Gate::authorize('viewAny', TrainingProgram::class);
    }

    public function create(): void
    {
        Gate::authorize('create', TrainingProgram::class);
        $this->resetErrorBag();
        $this->editingId = null;
        $this->f = ['code' => '', 'title' => '', 'trainer' => '', 'mode' => 'in_person', 'venue' => '', 'starts_on' => now()->addDays(14)->toDateString(), 'ends_on' => now()->addDays(14)->toDateString(), 'capacity' => '20'];
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $p = TrainingProgram::query()->findOrFail($id);
        Gate::authorize('update', $p);
        $this->resetErrorBag();
        $this->editingId = $p->id;
        $this->f = ['code' => $p->code, 'title' => $p->title, 'trainer' => (string) $p->trainer, 'mode' => $p->mode, 'venue' => (string) $p->venue,
            'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on->toDateString(), 'capacity' => (string) $p->capacity];
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->formOpen = false;
        $this->resetErrorBag();
    }

    public function save(TrainingService $service): void
    {
        $editing = $this->editingId === null ? null : TrainingProgram::query()->findOrFail($this->editingId);

        if ($this->attempt(fn () => $service->saveProgram($this->f, $this->actor(), $editing), 'Program saved.')) {
            $this->formOpen = false;
        }
    }

    public function render(): View
    {
        $programs = TrainingProgram::query()->when(array_key_exists($this->state, TrainingProgram::STATES), fn ($q) => $q->where('state', $this->state))->orderByDesc('starts_on')->get();
        $seats = TrainingEnrolment::query()->whereIn('training_program_id', $programs->pluck('id'))->whereIn('state', ['enrolled', 'completed'])->toBase()->groupBy('training_program_id')->selectRaw('training_program_id, count(*) as n')->pluck('n', 'training_program_id');

        return view('hr.programs', ['programs' => $programs, 'seats' => $seats, 'states' => TrainingProgram::STATES, 'modes' => TrainingProgram::MODES, 'canCreate' => Gate::allows('create', TrainingProgram::class), 'canEdit' => $this->actor()->can('training.edit')]);
    }
}

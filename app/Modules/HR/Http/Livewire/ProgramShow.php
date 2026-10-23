<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\TrainingEnrolment;
use App\Modules\HR\Models\TrainingProgram;
use App\Modules\HR\Services\EmployeeAccess;
use App\Modules\HR\Services\TrainingService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** One program: who is enrolled, enrolling people (HR anyone; a manager their own line), recording outcomes, completing or cancelling it. */
#[Title('Training program')]
class ProgramShow extends Component
{
    use ActsAsStaff;

    #[Locked]
    public int $programId = 0;

    public string $employeeId = '';

    /** @var array<int|string, string> enrolment id => outcome chosen */
    public array $outcome = [];

    /** @var array<int|string, string> enrolment id => score typed */
    public array $score = [];

    public function mount(TrainingProgram $program): void
    {
        Gate::authorize('view', $program);
        $this->programId = $program->id;
    }

    private function program(): TrainingProgram
    {
        $program = TrainingProgram::query()->findOrFail($this->programId);
        Gate::authorize('view', $program);

        return $program;
    }

    public function enrolPerson(TrainingService $service): void
    {
        $employee = ctype_digit($this->employeeId) ? Employee::query()->find((int) $this->employeeId) : null;

        if ($employee === null) {
            $this->resetErrorBag();
            $this->addError('employee_id', 'Choose the person.');

            return;
        }

        if ($this->attempt(fn () => $service->enrol($this->program(), $employee, $this->actor()), 'Enrolled; the person has been told.')) {
            $this->employeeId = '';
        }
    }

    public function recordOutcome(TrainingService $service, int $enrolmentId): void
    {
        $row = TrainingEnrolment::query()->where('training_program_id', $this->programId)->findOrFail($enrolmentId);
        $this->attempt(fn () => $service->record($row, $this->outcome[$enrolmentId] ?? '', $this->score[$enrolmentId] ?? null, $this->actor()), 'Outcome recorded.');
    }

    public function completeIt(TrainingService $service): void
    {
        $this->attempt(fn () => $service->completeProgram($this->program(), $this->actor()), 'The program is completed.');
    }

    public function cancelIt(TrainingService $service): void
    {
        $this->attempt(fn () => $service->cancelProgram($this->program(), $this->actor()), 'The program is cancelled and the people enrolled have been told.');
    }

    public function render(TrainingService $service, EmployeeAccess $access): View
    {
        $program = $this->program();
        $user = $this->actor();
        $rows = TrainingEnrolment::query()->where('training_program_id', $program->id)->with('employee:id,name,code,user_id,reports_to_id')->orderBy('id')->get();

        foreach ($rows as $r) {
            $this->outcome[$r->id] ??= $r->state === 'enrolled' ? '' : $r->state;
            $this->score[$r->id] ??= (string) $r->score;
        }

        $own = $access->own($user);
        $pickable = $user->can('training.edit') ? Employee::query() : ($own !== null && $user->can('training.enrol_team') ? Employee::query()->whereIn('id', $access->belowIds($own->id)) : Employee::query()->whereRaw('1 = 0'));
        $already = $rows->pluck('employee_id');

        return view('hr.program-show', [
            'p' => $program, 'rows' => $rows, 'taken' => $service->seatsTaken($program), 'states' => TrainingEnrolment::STATES, 'programStates' => TrainingProgram::STATES, 'modes' => TrainingProgram::MODES,
            'people' => $pickable->whereIn('employment_status', ['active', 'notice'])->whereNotIn('id', $already)->orderBy('name')->get(['id', 'code', 'name'])->mapWithKeys(fn (Employee $e) => [$e->id => $e->code.' · '.$e->name])->all(),
            'canEnrol' => $program->state === 'planned' && ($user->can('training.edit') || ($own !== null && $user->can('training.enrol_team'))),
            'canHr' => $user->can('training.edit'),
            'manages' => $rows->mapWithKeys(fn (TrainingEnrolment $r) => [$r->id => $r->employee !== null && $service->manages($user, $r->employee)])->all(),
            'started' => ! $program->starts_on->isFuture(),
        ]);
    }
}

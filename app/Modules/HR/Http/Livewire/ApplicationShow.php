<?php

namespace App\Modules\HR\Http\Livewire;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Interview;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\Offer;
use App\Modules\HR\Services\RecruitmentService;
use App\Modules\Masters\Models\Designation;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** One application: where it stands and what can be done next (move, interview, offer, hire). Every step goes through RecruitmentService. */
#[Title('Application')]
class ApplicationShow extends Component
{
    use ActsAsStaff;

    #[Locked]
    public int $applicationId = 0;

    /** which panel is open: reason | interview | offer | answer | '' */
    public string $panel = '';

    public string $reasonTo = 'rejected';

    public string $note = '';

    /** @var array<string, string> */
    public array $slot = ['scheduled_at' => '', 'mode' => 'in_person', 'interviewer_id' => ''];

    /** @var array<string, string> */
    public array $offerForm = ['designation_id' => '', 'joining_date' => '', 'note' => ''];

    /** @var array<string, string> */
    public array $answer = ['answer' => 'accepted', 'on' => ''];

    public function mount(JobApplication $application): void
    {
        Gate::authorize('view', $application);
        $this->applicationId = $application->id;
        $this->answer['on'] = now()->toDateString();
    }

    private function application(): JobApplication
    {
        $application = JobApplication::query()->findOrFail($this->applicationId);
        Gate::authorize('view', $application);

        return $application;
    }

    public function openPanel(string $panel): void
    {
        $this->resetErrorBag();
        $this->panel = $this->panel === $panel ? '' : $panel;
        $this->note = '';

        if ($panel === 'interview' && $this->panel === 'interview') {
            $this->slot = ['scheduled_at' => now()->addDay()->setTime(11, 0)->format('Y-m-d\TH:i'), 'mode' => 'in_person', 'interviewer_id' => ''];
        }

        if ($panel === 'offer' && $this->panel === 'offer') {
            $this->offerForm = ['designation_id' => '', 'joining_date' => now()->addDays(30)->toDateString(), 'note' => ''];
        }
    }

    public function startReason(string $to): void
    {
        $this->openPanel('reason');
        $this->panel = 'reason';
        $this->reasonTo = in_array($to, ['rejected', 'withdrawn'], true) ? $to : 'rejected';
    }

    public function advanceTo(RecruitmentService $service, string $to): void
    {
        $this->attempt(fn () => $service->moveStage($this->application(), $to, null, $this->actor()), 'Moved to '.strtolower(JobApplication::STAGES[$to] ?? $to).'.');
    }

    public function confirmReason(RecruitmentService $service): void
    {
        if ($this->attempt(fn () => $service->moveStage($this->application(), $this->reasonTo, $this->note, $this->actor()), 'Application '.$this->reasonTo.'.')) {
            $this->panel = '';
        }
    }

    public function scheduleIt(RecruitmentService $service): void
    {
        $interviewer = ctype_digit($this->slot['interviewer_id']) ? (int) $this->slot['interviewer_id'] : 0;

        if ($this->attempt(fn () => $service->scheduleInterview($this->application(), $this->slot['scheduled_at'], $this->slot['mode'], $interviewer, $this->actor()), 'Interview booked; the interviewer has been told.')) {
            $this->panel = '';
        }
    }

    public function closeInterviewAs(RecruitmentService $service, int $id, string $as): void
    {
        $interview = Interview::query()->where('application_id', $this->applicationId)->findOrFail($id);
        $this->attempt(fn () => $service->closeInterview($interview, $as, $this->actor()), $as === 'cancelled' ? 'Interview cancelled.' : 'Marked as a no-show.');
    }

    public function makeTheOffer(RecruitmentService $service): void
    {
        $designation = ctype_digit($this->offerForm['designation_id']) ? (int) $this->offerForm['designation_id'] : null;

        if ($this->attempt(fn () => $service->makeOffer($this->application(), $designation, $this->offerForm['joining_date'], $this->offerForm['note'], $this->actor()), 'Offer sent.')) {
            $this->panel = '';
        }
    }

    public function answerOffer(RecruitmentService $service): void
    {
        $offer = Offer::query()->where('application_id', $this->applicationId)->firstOrFail();

        if ($this->attempt(fn () => $service->respondToOffer($offer, $this->answer['answer'], $this->answer['on'], $this->actor()), 'The answer is recorded.')) {
            $this->panel = '';
        }
    }

    public function hireIt(RecruitmentService $service): void
    {
        $this->attempt(fn () => $service->hire($this->application(), $this->actor()), 'Hired: the employee file has been made (see the link above).');
    }

    public function render(): View
    {
        $application = JobApplication::query()->with(['candidate', 'opening'])->findOrFail($this->applicationId);
        Gate::authorize('view', $application);
        $user = $this->actor();

        return view('hr.application-show', [
            'a' => $application, 'stages' => JobApplication::STAGES, 'moves' => JobApplication::MOVES[$application->stage] ?? [],
            'canEdit' => Gate::allows('update', $application), 'canHire' => $user->can('employee.create'),
            'interviews' => Interview::query()->where('application_id', $application->id)->with('interviewer:id,name')->orderBy('round')->orderBy('id')->get(), 'modes' => Interview::MODES,
            'offer' => Offer::query()->where('application_id', $application->id)->first(),
            'events' => $application->events()->with('recorder:id,name')->orderByDesc('id')->get(),
            'people' => User::query()->where('is_active', true)->whereIn('id', DB::table('company_user')->where('company_id', $application->company_id)->pluck('user_id'))->orderBy('name')->get(['id', 'name'])->pluck('name', 'id')->all(),
            'designations' => Designation::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'employee' => $application->hired_employee_id === null ? null : Employee::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $application->company_id)->find($application->hired_employee_id),
        ]);
    }
}

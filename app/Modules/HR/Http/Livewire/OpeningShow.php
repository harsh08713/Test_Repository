<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\Candidate;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobOpening;
use App\Modules\HR\Services\RecruitmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** One opening: its details, opening and closing it, and everyone who applied (adding a candidate, new or on file, as an applicant). */
#[Title('Job opening')]
class OpeningShow extends Component
{
    use ActsAsStaff;

    #[Locked]
    public int $openingId = 0;

    /** which panel is open: apply | close | '' */
    public string $panel = '';

    public string $candidateId = '';

    /** @var array<string, string> */
    public array $new = ['name' => '', 'mobile' => '', 'email' => '', 'source' => 'other', 'current_employer' => '', 'experience_years' => ''];

    public string $closeReason = '';

    public function mount(JobOpening $opening): void
    {
        Gate::authorize('view', $opening);
        $this->openingId = $opening->id;
    }

    private function opening(): JobOpening
    {
        $opening = JobOpening::query()->findOrFail($this->openingId);
        Gate::authorize('view', $opening);

        return $opening;
    }

    public function openPanel(string $panel): void
    {
        $this->resetErrorBag();
        $this->panel = $this->panel === $panel ? '' : $panel;
    }

    public function openIt(RecruitmentService $service): void
    {
        $this->attempt(fn () => $service->openOpening($this->opening(), $this->actor()), 'The opening is open for applications.');
    }

    public function closeIt(RecruitmentService $service): void
    {
        if ($this->attempt(fn () => $service->closeOpening($this->opening(), $this->closeReason, $this->actor()), 'The opening is closed.')) {
            $this->panel = '';
            $this->closeReason = '';
        }
    }

    public function addApplicant(RecruitmentService $service): void
    {
        $this->attempt(function () use ($service) {
            // a new candidate and the application are made together: if the application is refused, the candidate is not left behind
            DB::transaction(function () use ($service) {
                $user = $this->actor();
                $opening = $this->opening();
                $candidate = ctype_digit($this->candidateId) ? Candidate::query()->find((int) $this->candidateId) : $service->saveCandidate($this->new, $user);

                if ($candidate === null) {
                    throw ValidationException::withMessages(['candidate_id' => 'Choose a candidate, or leave this empty and fill in the new one.']);
                }

                $service->apply($candidate, $opening, $user);
            });

            $this->candidateId = '';
            $this->new = ['name' => '', 'mobile' => '', 'email' => '', 'source' => 'other', 'current_employer' => '', 'experience_years' => ''];
            $this->panel = '';
        }, 'Applicant added.');
    }

    public function render(RecruitmentService $service): View
    {
        $opening = $this->opening();
        $applications = JobApplication::query()->where('job_opening_id', $opening->id)->with(['candidate:id,name,mobile,email,source'])->orderByRaw("field(stage, 'offer', 'interview', 'screening', 'applied', 'hired', 'rejected', 'withdrawn')")->orderBy('id')->get();
        $applied = $applications->pluck('candidate_id');

        return view('hr.opening-show', [
            'o' => $opening->load(['department', 'designation', 'location']), 'applications' => $applications, 'hired' => $service->hired($opening), 'stages' => JobApplication::STAGES,
            'canEdit' => Gate::allows('update', $opening),
            'candidates' => Candidate::query()->whereNotIn('id', $applied)->orderBy('name')->limit(300)->get(['id', 'name', 'mobile'])->mapWithKeys(fn (Candidate $c) => [$c->id => $c->name.($c->mobile ? ' · '.$c->mobile : '')])->all(),
            'sources' => Candidate::SOURCES, 'manager' => $opening->hiring_manager_id === null ? null : Employee::query()->find($opening->hiring_manager_id),
        ]);
    }
}

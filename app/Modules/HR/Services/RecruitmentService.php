<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\HR\Models\ApplicationEvent;
use App\Modules\HR\Models\Candidate;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Interview;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobOpening;
use App\Modules\HR\Models\Offer;
use App\Modules\Masters\Models\Department;
use App\Modules\Masters\Models\Designation;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one door for recruitment: openings, candidates, applications and their stages, interviews, offers and hiring.
 *
 *   - An opening is draft → open → closed (it can be re-opened); people apply only to an open one, once each; an application is by one candidate to one opening.
 *   - An application moves applied → screening → interview; rejecting or withdrawing needs a reason and cancels what is still planned for it. **Offer and hired are reached only by
 *     `makeOffer` and `hire`**, never by moving the stage. Every move is written to the append-only application history.
 *   - An interview is booked for a future time with an active login of the company as interviewer; **only that interviewer writes its feedback**, and not before the day.
 *   - An offer needs an interview a person recommended hiring on; it carries no money (salary is payroll). Accepting it makes the application ready to hire; declining withdraws the application.
 *   - `hire` makes the employee file through `EmployeeService::create`, once (the application row is locked, and `hired_employee_id` is unique), and never more people than the opening has
 *     positions (the opening row is locked): when the last position is filled the opening closes itself. A new hire starts on probation for six months.
 *   - Everything here needs `recruitment.create` / `recruitment.edit`, except the interviewer's feedback.
 */
final class RecruitmentService
{
    public const PROBATION_MONTHS = 6;

    public const OFFER_AHEAD_DAYS = 90;

    public function __construct(private readonly EmployeeService $employees, private readonly HrNotifier $notifier, private readonly TenantContext $tenant) {}

    private function company(): int
    {
        return (int) $this->tenant->activeCompanyId();
    }

    private function need(User $actor, string $action): void
    {
        if (! $actor->can('recruitment.'.$action)) {
            throw new AuthorizationException('You may not do this in recruitment.');
        }
    }

    public function suggestCode(): string
    {
        $max = 0;

        foreach (JobOpening::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->company())->where('code', 'like', 'JOB-%')->pluck('code') as $code) {
            if (preg_match('/^JOB-(\d+)$/', (string) $code, $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'JOB-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    // ---- openings ----------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input  code, title, location_id, department_id, designation_id, hiring_manager_id, positions, description
     *
     * @throws ValidationException
     */
    public function saveOpening(array $input, User $actor, ?JobOpening $editing = null): JobOpening
    {
        $this->need($actor, $editing === null ? 'create' : 'edit');
        $errors = [];
        $id = fn (string $k) => is_numeric($input[$k] ?? null) && (int) $input[$k] > 0 ? (int) $input[$k] : null;
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $title = trim((string) ($input['title'] ?? ''));
        $positions = trim((string) ($input['positions'] ?? '1'));

        if (preg_match('/^[A-Z0-9._\/-]{1,30}$/', $code) !== 1) {
            $errors['code'] = 'Enter a reference: letters, digits and . _ - / only (up to 30).';
        } elseif (JobOpening::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->company())->where('code', $code)->when($editing !== null, fn ($q) => $q->whereKeyNot($editing->id))->exists()) {
            $errors['code'] = 'That reference is already used.';
        }

        if ($title === '' || mb_strlen($title) > 150) {
            $errors['title'] = 'Enter the job title (up to 150 characters).';
        }

        if (preg_match('/^\d{1,2}$/', $positions) !== 1 || (int) $positions < 1 || (int) $positions > 50) {
            $errors['positions'] = 'Enter how many people (1 to 50).';
        } elseif ($editing !== null && (int) $positions < $this->hired($editing)) {
            $errors['positions'] = 'Already '.$this->hired($editing).' hired: the positions cannot be fewer than that.';
        }

        $location = $id('location_id');

        if ($location === null || ! Location::query()->whereKey($location)->exists()) {
            $errors['location_id'] = 'Choose the branch the person will work in.';
        }

        $department = $id('department_id');
        $designation = $id('designation_id');
        $manager = $id('hiring_manager_id');

        if ($department !== null && ! Department::query()->where('status', 'active')->whereKey($department)->exists()) {
            $errors['department_id'] = 'Choose an active department.';
        }

        if ($designation !== null && ! Designation::query()->where('status', 'active')->whereKey($designation)->exists()) {
            $errors['designation_id'] = 'Choose an active designation.';
        }

        if ($manager !== null && ! Employee::query()->whereIn('employment_status', ['active', 'notice'])->whereKey($manager)->exists()) {
            $errors['hiring_manager_id'] = 'Choose an employee who is still with the company.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $opening = $editing ?? new JobOpening;
        $opening->fill(['code' => $code, 'title' => $title, 'department_id' => $department, 'designation_id' => $designation, 'hiring_manager_id' => $manager, 'positions' => (int) $positions,
            'description' => $this->text($input['description'] ?? null, 4000)]);

        if ($editing === null) {
            $opening->forceFill(['location_id' => $location, 'state' => 'draft']);
        }

        $opening->save();

        return $opening;
    }

    /** @throws ValidationException */
    public function openOpening(JobOpening $opening, User $actor): JobOpening
    {
        $this->need($actor, 'edit');

        if ($opening->state === 'open') {
            throw ValidationException::withMessages(['state' => 'It is already open.']);
        }

        if ($this->hired($opening) >= $opening->positions) {
            throw ValidationException::withMessages(['state' => 'All the positions are filled: raise the number of positions to open it again.']);
        }

        $opening->forceFill(['state' => 'open', 'opened_on' => $opening->opened_on ?? now()->toDateString(), 'closed_on' => null, 'close_reason' => null])->save();

        return $opening;
    }

    /** @throws ValidationException */
    public function closeOpening(JobOpening $opening, string $reason, User $actor): JobOpening
    {
        $this->need($actor, 'edit');
        $reason = trim($reason);

        if ($opening->state === 'closed') {
            throw ValidationException::withMessages(['state' => 'It is already closed.']);
        }

        if ($reason === '' || mb_strlen($reason) > 300) {
            throw ValidationException::withMessages(['close_reason' => 'Say why it is closed (up to 300 characters).']);
        }

        $opening->forceFill(['state' => 'closed', 'closed_on' => now()->toDateString(), 'close_reason' => $reason])->save();

        return $opening;
    }

    public function hired(JobOpening $opening): int
    {
        return JobApplication::query()->where('job_opening_id', $opening->id)->where('stage', 'hired')->count();
    }

    // ---- candidates and applications ----------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input  name, mobile, email, source, current_employer, experience_years, notes
     *
     * @throws ValidationException
     */
    public function saveCandidate(array $input, User $actor, ?Candidate $editing = null): Candidate
    {
        $this->need($actor, $editing === null ? 'create' : 'edit');
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        $mobile = trim((string) ($input['mobile'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $source = (string) ($input['source'] ?? 'other');
        $years = trim((string) ($input['experience_years'] ?? ''));

        if ($name === '' || mb_strlen($name) > 150) {
            $errors['name'] = 'Enter the name (up to 150 characters).';
        }

        if ($mobile !== '' && preg_match('/^[6-9]\d{9}$/', $mobile) !== 1) {
            $errors['mobile'] = 'Enter a 10-digit mobile number.';
        } elseif ($mobile !== '' && Candidate::query()->where('mobile', $mobile)->when($editing !== null, fn ($q) => $q->whereKeyNot($editing->id))->exists()) {
            $errors['mobile'] = 'A candidate with that mobile number is already on file.';
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid e-mail address.';
        }

        if ($mobile === '' && $email === '') {
            $errors['mobile'] = 'Enter a mobile number or an e-mail address, so the person can be reached.';
        }

        if (! array_key_exists($source, Candidate::SOURCES)) {
            $errors['source'] = 'Choose where the candidate came from.';
        }

        if ($years !== '' && (preg_match('/^\d{1,2}(\.\d)?$/', $years) !== 1 || (float) $years > 60)) {
            $errors['experience_years'] = 'Enter the years of experience (0 to 60).';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $candidate = $editing ?? new Candidate;
        $candidate->fill(['name' => $name, 'mobile' => $mobile === '' ? null : $mobile, 'email' => $email === '' ? null : $email, 'source' => $source,
            'current_employer' => $this->text($input['current_employer'] ?? null, 150), 'experience_years' => $years === '' ? null : $years, 'notes' => $this->text($input['notes'] ?? null, 4000)]);
        $candidate->save();

        return $candidate;
    }

    /** @throws ValidationException */
    public function apply(Candidate $candidate, JobOpening $opening, User $actor): JobApplication
    {
        $this->need($actor, 'create');

        if ($opening->state !== 'open') {
            throw ValidationException::withMessages(['job_opening_id' => 'That opening is not open for applications.']);
        }

        return DB::transaction(function () use ($candidate, $opening, $actor) {
            JobOpening::query()->whereKey($opening->id)->lockForUpdate()->first();

            if (JobApplication::query()->where('candidate_id', $candidate->id)->where('job_opening_id', $opening->id)->exists()) {
                throw ValidationException::withMessages(['candidate_id' => 'This candidate has already applied for that opening.']);
            }

            $application = new JobApplication;
            $application->forceFill(['company_id' => $candidate->company_id, 'candidate_id' => $candidate->id, 'job_opening_id' => $opening->id, 'stage' => 'applied'])->save();
            $this->event($application, null, 'applied', null, $actor);

            return $application;
        });
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function moveStage(JobApplication $application, string $to, ?string $note, User $actor): JobApplication
    {
        $this->need($actor, 'edit');
        $note = $this->text($note, 300);

        return DB::transaction(function () use ($application, $to, $note, $actor) {
            $locked = JobApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if (! in_array($to, JobApplication::MOVES[$locked->stage] ?? [], true)) {
                throw ValidationException::withMessages(['stage' => 'An application that is '.strtolower(JobApplication::STAGES[$locked->stage] ?? $locked->stage).' cannot be moved to '.strtolower(JobApplication::STAGES[$to] ?? $to).'.']);
            }

            if (in_array($to, ['rejected', 'withdrawn'], true)) {
                if ($note === null) {
                    throw ValidationException::withMessages(['note' => 'Say why.']);
                }

                $this->closeLooseEnds($locked);
                $locked->forceFill(['reject_reason' => $note]);
            }

            $from = $locked->stage;
            $locked->forceFill(['stage' => $to])->save();
            $this->event($locked, $from, $to, $note, $actor);

            return $locked;
        });
    }

    // ---- interviews --------------------------------------------------------------------------------------------------------------

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function scheduleInterview(JobApplication $application, string $at, string $mode, int $interviewerId, User $actor): Interview
    {
        $this->need($actor, 'edit');
        $errors = [];
        $when = $this->moment($at);
        $interviewer = User::query()->where('is_active', true)->find($interviewerId);

        if ($when === null || $when->lt(CarbonImmutable::now()->subMinutes(5)) || $when->gt(CarbonImmutable::now()->addDays(120))) {
            $errors['scheduled_at'] = 'Choose a date and time from now to four months ahead.';
        }

        if (! array_key_exists($mode, Interview::MODES)) {
            $errors['mode'] = 'Choose how the interview will be held.';
        }

        if ($interviewer === null || ! $this->tenant->userCanAccessCompany($interviewer, $this->company())) {
            $errors['interviewer_id'] = 'Choose an interviewer who has a login in this company.';
        }

        if ($errors !== [] || $when === null || $interviewer === null) {
            throw ValidationException::withMessages($errors);
        }

        $interview = DB::transaction(function () use ($application, $when, $mode, $interviewer, $actor) {
            $locked = JobApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->stage, ['applied', 'screening', 'interview'], true)) {
                throw ValidationException::withMessages(['stage' => 'An application that is '.strtolower(JobApplication::STAGES[$locked->stage] ?? $locked->stage).' cannot have another interview.']);
            }

            if ($locked->stage !== 'interview') {
                $from = $locked->stage;
                $locked->forceFill(['stage' => 'interview'])->save();
                $this->event($locked, $from, 'interview', 'Interview scheduled', $actor);
            }

            $round = (int) Interview::query()->where('application_id', $locked->id)->where('state', '!=', 'cancelled')->max('round') + 1;
            $interview = new Interview;
            $interview->forceFill(['company_id' => $locked->company_id, 'application_id' => $locked->id, 'round' => $round, 'scheduled_at' => $when, 'mode' => $mode, 'interviewer_id' => $interviewer->id, 'state' => 'scheduled'])->save();

            return $interview;
        });

        $candidate = Candidate::query()->find($application->candidate_id);
        $who = $candidate === null ? 'a candidate' : $candidate->name;
        $this->notifier->tell($interviewer, $actor, 'interview_scheduled', 'Interview to take: '.$who,
            'You are to interview '.$who.' (round '.$interview->round.', '.strtolower(Interview::MODES[$mode]).') on '.$when->format('d M Y, H:i').'.', route('hr.my-interviews'));

        return $interview;
    }

    /**
     * Only the interviewer, and not before the day of the interview.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function recordFeedback(Interview $interview, int $rating, string $recommendation, string $feedback, User $actor): Interview
    {
        if ($interview->interviewer_id !== $actor->id) {
            throw new AuthorizationException('Only the interviewer can write the feedback.');
        }

        $errors = [];
        $feedback = trim($feedback);

        if ($rating < 1 || $rating > 5) {
            $errors['rating'] = 'Rate from 1 to 5.';
        }

        if (! in_array($recommendation, ['hire', 'hold', 'reject'], true)) {
            $errors['recommendation'] = 'Choose hire, hold or reject.';
        }

        if (mb_strlen($feedback) < 10 || mb_strlen($feedback) > 2000) {
            $errors['feedback'] = 'Write what you found (10 to 2000 characters).';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($interview, $rating, $recommendation, $feedback) {
            $locked = Interview::query()->whereKey($interview->id)->lockForUpdate()->firstOrFail();

            if ($locked->state !== 'scheduled') {
                throw ValidationException::withMessages(['state' => 'This interview is already '.$locked->state.'.']);
            }

            if ($locked->scheduled_at->startOfDay()->isFuture()) {
                throw ValidationException::withMessages(['state' => 'The interview is on '.$locked->scheduled_at->format('d M').': feedback can be written from that day.']);
            }

            $locked->forceFill(['state' => 'done', 'rating' => $rating, 'recommendation' => $recommendation, 'feedback' => $feedback])->save();

            return $locked;
        });
    }

    /** HR marks a planned interview as cancelled or the candidate as not having come. */
    public function closeInterview(Interview $interview, string $as, User $actor): Interview
    {
        $this->need($actor, 'edit');

        if (! in_array($as, ['cancelled', 'no_show'], true) || $interview->state !== 'scheduled') {
            throw ValidationException::withMessages(['state' => 'Only a planned interview can be cancelled or marked as a no-show.']);
        }

        $interview->forceFill(['state' => $as])->save();

        return $interview;
    }

    // ---- offer and hire ----------------------------------------------------------------------------------------------------------

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function makeOffer(JobApplication $application, ?int $designationId, string $joiningDate, ?string $note, User $actor): Offer
    {
        $this->need($actor, 'edit');
        $errors = [];
        $joining = $this->day($joiningDate);

        if ($joining === null || $joining->lt(CarbonImmutable::today()) || $joining->gt(CarbonImmutable::today()->addDays(self::OFFER_AHEAD_DAYS))) {
            $errors['joining_date'] = 'Give a joining date from today to '.self::OFFER_AHEAD_DAYS.' days ahead.';
        }

        if ($designationId !== null && ! Designation::query()->where('status', 'active')->whereKey($designationId)->exists()) {
            $errors['designation_id'] = 'Choose an active designation.';
        }

        if ($errors !== [] || $joining === null) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($application, $designationId, $joining, $note, $actor) {
            $locked = JobApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->stage !== 'interview') {
                throw ValidationException::withMessages(['stage' => 'An offer is made at the interview stage.']);
            }

            if (! Interview::query()->where('application_id', $locked->id)->where('state', 'done')->where('recommendation', 'hire')->exists()) {
                throw ValidationException::withMessages(['stage' => 'No interviewer has recommended hiring this person yet.']);
            }

            $opening = JobOpening::query()->findOrFail($locked->job_opening_id);

            if ($opening->state !== 'open') {
                throw ValidationException::withMessages(['stage' => 'The opening is not open.']);
            }

            if (Offer::query()->where('application_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['stage' => 'An offer has already been made for this application.']);
            }

            $offer = new Offer;
            $offer->forceFill(['company_id' => $locked->company_id, 'application_id' => $locked->id, 'designation_id' => $designationId ?? $opening->designation_id, 'joining_date' => $joining->toDateString(), 'state' => 'sent', 'note' => $this->text($note, 300)])->save();
            $locked->forceFill(['stage' => 'offer'])->save();
            $this->event($locked, 'interview', 'offer', 'Offer sent for joining on '.$joining->format('d M Y'), $actor);

            return $offer;
        });
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function respondToOffer(Offer $offer, string $answer, string $on, User $actor): Offer
    {
        $this->need($actor, 'edit');
        $day = $this->day($on);

        if (! in_array($answer, ['accepted', 'declined'], true) || $day === null || $day->isFuture()) {
            throw ValidationException::withMessages(['answer' => 'Say whether it was accepted or declined, and the day (not in the future).']);
        }

        return DB::transaction(function () use ($offer, $answer, $day, $actor) {
            $locked = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $application = JobApplication::query()->whereKey($locked->application_id)->lockForUpdate()->firstOrFail();

            if ($locked->state !== 'sent' || $application->stage !== 'offer') {
                throw ValidationException::withMessages(['answer' => 'This offer has already been answered or withdrawn.']);
            }

            $locked->forceFill(['state' => $answer, 'responded_on' => $day->toDateString()])->save();

            if ($answer === 'declined') {
                $application->forceFill(['stage' => 'withdrawn', 'reject_reason' => 'The candidate declined the offer.'])->save();
                $this->event($application, 'offer', 'withdrawn', 'The candidate declined the offer.', $actor);
            }

            return $locked;
        });
    }

    /**
     * Make the employee file of an accepted offer.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function hire(JobApplication $application, User $actor): Employee
    {
        $this->need($actor, 'edit');

        if (! $actor->can('employee.create')) {
            throw new AuthorizationException('Making the employee file needs the right to add employees.');
        }

        return DB::transaction(function () use ($application, $actor) {
            $opening = JobOpening::query()->whereKey($application->job_opening_id)->lockForUpdate()->firstOrFail();
            $locked = JobApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $offer = Offer::query()->where('application_id', $locked->id)->first();

            if ($locked->stage !== 'offer' || $offer === null || $offer->state !== 'accepted') {
                throw ValidationException::withMessages(['stage' => 'Only an application whose offer has been accepted can be hired.']);
            }

            if ($locked->hired_employee_id !== null) {
                throw ValidationException::withMessages(['stage' => 'This person has already been hired.']);
            }

            if ($opening->state !== 'open' || $this->hired($opening) >= $opening->positions) {
                throw ValidationException::withMessages(['stage' => 'The opening has no position left.']);
            }

            $candidate = Candidate::query()->findOrFail($locked->candidate_id);

            if (JobApplication::query()->where('candidate_id', $candidate->id)->where('stage', 'hired')->exists()) {
                throw ValidationException::withMessages(['stage' => 'This candidate has already been hired through another opening.']);
            }

            $manager = $opening->hiring_manager_id === null ? null : Employee::query()->whereIn('employment_status', ['active', 'notice'])->find($opening->hiring_manager_id);
            $joining = CarbonImmutable::instance($offer->joining_date);

            $employee = $this->employees->create([
                'code' => $this->employees->suggestCode(), 'name' => $candidate->name, 'mobile' => $candidate->mobile ?? '', 'personal_email' => $candidate->email ?? '',
                'location_id' => (string) $opening->location_id, 'department_id' => (string) ($opening->department_id ?? ''), 'designation_id' => (string) ($offer->designation_id ?? ''),
                'reports_to_id' => $manager === null ? '' : (string) $manager->id, 'employment_type' => 'probation', 'joining_date' => $joining->toDateString(),
                'probation_end_date' => $joining->addMonths(self::PROBATION_MONTHS)->toDateString(), 'remarks' => 'Hired through '.$opening->code.' ('.$opening->title.').',
            ], $actor);

            $locked->forceFill(['stage' => 'hired', 'hired_employee_id' => $employee->id])->save();
            $this->event($locked, 'offer', 'hired', 'Employee file '.$employee->code.' made.', $actor);

            if ($this->hired($opening) >= $opening->positions) {
                $opening->forceFill(['state' => 'closed', 'closed_on' => now()->toDateString(), 'close_reason' => 'All positions filled.'])->save();
            }

            if ($manager !== null) {
                $this->notifier->tell($this->notifier->loginOf($manager), $actor, 'new_joiner', $candidate->name.' will join your team', $candidate->name.' joins on '.$joining->format('d M Y').' as '.$opening->title.'.', route('hr.employees.show', $employee));
            }

            return $employee;
        });
    }

    // ---- internals -------------------------------------------------------------------------------------------------------------------

    /** Rejecting or withdrawing: what was still planned for the application is called off. */
    private function closeLooseEnds(JobApplication $application): void
    {
        Interview::query()->where('application_id', $application->id)->where('state', 'scheduled')->update(['state' => 'cancelled']);
        Offer::query()->where('application_id', $application->id)->where('state', 'sent')->update(['state' => 'withdrawn']);
    }

    private function event(JobApplication $application, ?string $from, string $to, ?string $note, ?User $by): void
    {
        $row = new ApplicationEvent;
        $row->forceFill(['company_id' => $application->company_id, 'application_id' => $application->id, 'from_stage' => $from, 'to_stage' => $to, 'note' => $note, 'recorded_by' => $by?->id, 'created_at' => now()])->save();
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function day(string $value): ?CarbonImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? CarbonImmutable::instance($parsed) : null;
    }

    private function moment(string $value): ?CarbonImmutable
    {
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $value);

            if ($parsed !== false && $parsed->format($format) === $value) {
                return CarbonImmutable::instance($parsed);
            }
        }

        return null;
    }
}

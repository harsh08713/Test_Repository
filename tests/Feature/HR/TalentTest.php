<?php

use App\Modules\HR\Http\Livewire\ApplicationShow;
use App\Modules\HR\Http\Livewire\CandidateList;
use App\Modules\HR\Http\Livewire\CycleList;
use App\Modules\HR\Http\Livewire\MyInterviews;
use App\Modules\HR\Http\Livewire\MyPerformance;
use App\Modules\HR\Http\Livewire\OpeningList;
use App\Modules\HR\Http\Livewire\OpeningShow;
use App\Modules\HR\Http\Livewire\ProgramShow;
use App\Modules\HR\Http\Livewire\TeamPerformance;
use App\Modules\HR\Models\ApplicationEvent;
use App\Modules\HR\Models\Candidate;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Interview;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobOpening;
use App\Modules\HR\Models\Offer;
use App\Modules\HR\Models\PerformanceGoal;
use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\ReviewCycle;
use App\Modules\HR\Models\TrainingEnrolment;
use App\Modules\HR\Notifications\HrNotice;
use App\Modules\HR\Services\EmployeeAccess;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\HR\Services\PerformanceService;
use App\Modules\HR\Services\RecruitmentService;
use App\Modules\HR\Services\TrainingService;
use App\Modules\Masters\Models\Department;
use App\Modules\Masters\Models\Designation;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * H4: recruitment (openings, candidates, applications, interviews, offers, hiring), performance (cycles, goals, reviews) and training. "Today" is Monday 12 October 2026.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');

    $this->hrUser = makeUser(['HR Manager'], [$this->acme]);
    $this->bossUser = makeUser(['Sales Manager'], [$this->acme]);
    $this->workerUser = makeUser(['Tele-caller'], [$this->acme]);
    $this->otherUser = makeUser(['Tele-caller'], [$this->acme]);
    $this->outsider = makeUser(['HR Manager'], [$this->globex]);

    $this->sales = inCompany($this->acme, fn () => Department::query()->create(['code' => 'SALES', 'name' => 'Sales']));
    $this->exec = inCompany($this->acme, fn () => Designation::query()->create(['code' => 'EXEC', 'name' => 'Executive']));

    $this->as = function ($user) {
        $this->actingAs($user);
        app(TenantContext::class)->flush();
        app(EmployeeAccess::class)->flush();

        return $user;
    };
    $this->hire = function (string $code, string $name, $user = null, array $over = []) {
        ($this->as)($this->hrUser);
        $e = app(EmployeeService::class)->create(['code' => $code, 'name' => $name, 'location_id' => (string) $this->ahd->id, 'joining_date' => '2026-01-05', 'employment_type' => 'permanent', ...$over], $this->hrUser);

        if ($user !== null) {
            app(EmployeeService::class)->linkUser($e, $user, $this->hrUser);
        }

        return $e;
    };
    $this->boss = ($this->hire)('B1', 'Boss', $this->bossUser);
    $this->worker = ($this->hire)('W1', 'Worker', $this->workerUser, ['reports_to_id' => (string) $this->boss->id]);
    $this->other = ($this->hire)('O1', 'Other', $this->otherUser);

    $this->rec = app(RecruitmentService::class);
    $this->perf = app(PerformanceService::class);
    $this->train = app(TrainingService::class);

    $this->openCycle = function () {
        ($this->as)($this->hrUser);
        $cycle = $this->perf->saveCycle(['code' => 'C1', 'name' => 'Annual', 'period_start' => '2026-04-01', 'period_end' => '2026-12-31'], $this->hrUser);
        $this->perf->openCycle($cycle, $this->hrUser);

        return $cycle->fresh();
    };
    $this->openingInput = fn (array $over = []) => ['code' => 'JOB-0001', 'title' => 'Sales Executive', 'location_id' => (string) $this->ahd->id, 'department_id' => (string) $this->sales->id,
        'designation_id' => (string) $this->exec->id, 'hiring_manager_id' => (string) $this->boss->id, 'positions' => '1', 'description' => 'Sell things', ...$over];
    $this->opening = function (array $over = [], bool $open = true) {
        ($this->as)($this->hrUser);
        $o = $this->rec->saveOpening(($this->openingInput)($over), $this->hrUser);

        return $open ? $this->rec->openOpening($o, $this->hrUser) : $o;
    };
    $this->applicant = function (string $name, string $mobile, ?JobOpening $opening = null) {
        ($this->as)($this->hrUser);
        $c = $this->rec->saveCandidate(['name' => $name, 'mobile' => $mobile, 'email' => strtolower(str_replace(' ', '.', $name)).'@mail.test', 'source' => 'referral'], $this->hrUser);

        return $this->rec->apply($c, $opening ?? ($this->opening)(), $this->hrUser);
    };
    // an application with a hire recommendation, an accepted offer, and the clock moved to the day after the interview
    $this->readyToHire = function (JobApplication $a) {
        ($this->as)($this->hrUser);
        $day = now()->addDay()->setTime(11, 0);
        $i = $this->rec->scheduleInterview($a, $day->format('Y-m-d\TH:i'), 'in_person', $this->otherUser->id, $this->hrUser);
        Carbon::setTestNow($day->copy()->setTime(12, 0));
        ($this->as)($this->otherUser);
        $this->rec->recordFeedback($i, 4, 'hire', 'Strong on communication and numbers.', $this->otherUser);
        ($this->as)($this->hrUser);
        $offer = $this->rec->makeOffer($a->fresh(), null, now()->addDays(20)->toDateString(), 'Welcome', $this->hrUser);
        $this->rec->respondToOffer($offer, 'accepted', now()->toDateString(), $this->hrUser);

        return $a->fresh();
    };
});

afterEach(fn () => Carbon::setTestNow());

// ---- openings and candidates ----------------------------------------------------------------------------------------------------------------

it('saves an opening with its rules, suggests a reference, opens and closes it, and keeps it to HR', function () {
    ($this->as)($this->hrUser);
    expect($this->rec->suggestCode())->toBe('JOB-0001');

    $o = $this->rec->saveOpening(($this->openingInput)(), $this->hrUser);
    expect($o->state)->toBe('draft')->and($this->rec->suggestCode())->toBe('JOB-0002');

    $try = fn (array $over) => fn () => $this->rec->saveOpening(($this->openingInput)(['code' => 'JOB-0009', ...$over]), $this->hrUser);
    expect($try(['title' => ' ']))->toThrow(ValidationException::class, 'job title')
        ->and($try(['positions' => '0']))->toThrow(ValidationException::class, '1 to 50')
        ->and($try(['location_id' => '9999']))->toThrow(ValidationException::class, 'branch')
        ->and($try(['hiring_manager_id' => '9999']))->toThrow(ValidationException::class, 'still with the company')
        ->and($try(['code' => 'JOB-0001']))->toThrow(ValidationException::class, 'already used');

    $this->rec->openOpening($o, $this->hrUser);
    expect($o->fresh()->state)->toBe('open')->and($o->fresh()->opened_on->toDateString())->toBe('2026-10-12')
        ->and(fn () => $this->rec->openOpening($o->fresh(), $this->hrUser))->toThrow(ValidationException::class, 'already open')
        ->and(fn () => $this->rec->closeOpening($o->fresh(), ' ', $this->hrUser))->toThrow(ValidationException::class, 'Say why');
    $this->rec->closeOpening($o->fresh(), 'Position cancelled', $this->hrUser);
    expect($o->fresh()->state)->toBe('closed')->and($o->fresh()->close_reason)->toBe('Position cancelled');
    $this->rec->openOpening($o->fresh(), $this->hrUser);                                          // it can be re-opened
    expect($o->fresh()->state)->toBe('open');

    expect(fn () => ($this->as)($this->bossUser) && $this->rec->saveOpening(($this->openingInput)(['code' => 'JOB-0077']), $this->bossUser))->toThrow(AuthorizationException::class);
});

it('keeps a candidate once, reachable, and valid', function () {
    ($this->as)($this->hrUser);
    $save = fn (array $over) => fn () => $this->rec->saveCandidate(['name' => 'Asha', 'mobile' => '9876500001', 'source' => 'portal', ...$over], $this->hrUser);

    $c = ($save([]))();
    expect($c->mobile)->toBe('9876500001')
        ->and($save(['name' => '']))->toThrow(ValidationException::class, 'name')
        ->and($save(['mobile' => '12345']))->toThrow(ValidationException::class, '10-digit')
        ->and($save(['mobile' => '9876500001']))->toThrow(ValidationException::class, 'already on file')
        ->and($save(['mobile' => '', 'email' => '']))->toThrow(ValidationException::class, 'reached')
        ->and($save(['mobile' => '9876500002', 'email' => 'nope']))->toThrow(ValidationException::class, 'valid e-mail')
        ->and($save(['mobile' => '9876500003', 'source' => 'smoke']))->toThrow(ValidationException::class, 'came from')
        ->and($save(['mobile' => '9876500004', 'experience_years' => '99']))->toThrow(ValidationException::class, 'experience');

    // changing the same candidate keeps their own number without a clash
    $again = $this->rec->saveCandidate(['name' => 'Asha K', 'mobile' => '9876500001', 'source' => 'portal'], $this->hrUser, $c);
    expect($again->name)->toBe('Asha K')->and(Candidate::query()->count())->toBe(1);
});

// ---- applications and stages -----------------------------------------------------------------------------------------------------------------

it('lets someone apply to an open opening once, and records every move', function () {
    $opening = ($this->opening)();
    $a = ($this->applicant)('Asha', '9876500001', $opening);
    expect($a->stage)->toBe('applied')->and(ApplicationEvent::query()->where('application_id', $a->id)->count())->toBe(1);

    expect(fn () => $this->rec->apply(Candidate::query()->first(), $opening, $this->hrUser))->toThrow(ValidationException::class, 'already applied');

    $draft = ($this->opening)(['code' => 'JOB-0002'], false);
    expect(fn () => $this->rec->apply(Candidate::query()->first(), $draft, $this->hrUser))->toThrow(ValidationException::class, 'not open');

    $this->rec->moveStage($a, 'screening', null, $this->hrUser);
    $events = ApplicationEvent::query()->where('application_id', $a->id)->orderBy('id')->get();
    expect($events->pluck('to_stage')->all())->toBe(['applied', 'screening'])->and($events[1]->from_stage)->toBe('applied')->and($events[1]->recorded_by)->toBe($this->hrUser->id)
        ->and(fn () => $events[0]->forceFill(['note' => 'x'])->save())->toThrow(LogicException::class)->and(fn () => $events[0]->delete())->toThrow(LogicException::class);
});

it('moves an application only where it may go, asks why for a rejection, and calls off what was planned', function () {
    $a = ($this->applicant)('Asha', '9876500001');

    expect(fn () => $this->rec->moveStage($a, 'offer', null, $this->hrUser))->toThrow(ValidationException::class, 'cannot be moved')
        ->and(fn () => $this->rec->moveStage($a, 'hired', null, $this->hrUser))->toThrow(ValidationException::class, 'cannot be moved')
        ->and(fn () => $this->rec->moveStage($a, 'interview', null, $this->hrUser))->toThrow(ValidationException::class, 'cannot be moved')   // screening comes first
        ->and(fn () => $this->rec->moveStage($a, 'rejected', ' ', $this->hrUser))->toThrow(ValidationException::class, 'Say why');

    $i = $this->rec->scheduleInterview($a, '2026-10-14T10:00', 'video', $this->otherUser->id, $this->hrUser);
    expect($a->fresh()->stage)->toBe('interview');                                              // booking an interview moves it there

    $this->rec->moveStage($a->fresh(), 'rejected', 'Not the profile we need', $this->hrUser);
    expect($a->fresh()->stage)->toBe('rejected')->and($a->fresh()->reject_reason)->toBe('Not the profile we need')->and($i->fresh()->state)->toBe('cancelled')
        ->and(fn () => $this->rec->moveStage($a->fresh(), 'screening', null, $this->hrUser))->toThrow(ValidationException::class, 'cannot be moved');
});

it('books an interview in the future with a login of the company, and only that person writes the feedback', function () {
    $a = ($this->applicant)('Asha', '9876500001');
    $book = fn (string $at, ?int $who = null, string $mode = 'phone') => fn () => $this->rec->scheduleInterview($a->fresh(), $at, $mode, $who ?? $this->otherUser->id, $this->hrUser);

    expect($book('2026-10-01T10:00'))->toThrow(ValidationException::class, 'from now')
        ->and($book('2027-06-01T10:00'))->toThrow(ValidationException::class, 'four months')
        ->and($book('garbage'))->toThrow(ValidationException::class)
        ->and($book('2026-10-14T10:00', $this->outsider->id))->toThrow(ValidationException::class, 'login in this company')
        ->and($book('2026-10-14T10:00', null, 'telepathy'))->toThrow(ValidationException::class, 'how the interview');

    $i1 = ($book('2026-10-14T10:00'))();
    $i2 = ($book('2026-10-16T10:00', $this->bossUser->id))();
    expect($i1->round)->toBe(1)->and($i2->round)->toBe(2);
    Notification::assertSentTo($this->otherUser, HrNotice::class, fn (HrNotice $n) => $n->kind === 'interview_scheduled' && str_contains($n->message, 'round 1'));

    // feedback: the interviewer only, from the day, with a real rating
    $give = fn ($user, int $rating = 4, string $rec = 'hire', string $text = 'A clear, confident candidate.') => fn () => ($this->as)($user) && $this->rec->recordFeedback($i1->fresh(), $rating, $rec, $text, $user);
    expect($give($this->bossUser))->toThrow(AuthorizationException::class, 'Only the interviewer')
        ->and($give($this->hrUser))->toThrow(AuthorizationException::class)
        ->and($give($this->otherUser))->toThrow(ValidationException::class, 'from that day');

    Carbon::setTestNow('2026-10-14 15:00:00');
    expect($give($this->otherUser, 6))->toThrow(ValidationException::class, '1 to 5')
        ->and($give($this->otherUser, 3, 'maybe'))->toThrow(ValidationException::class, 'hire, hold or reject')
        ->and($give($this->otherUser, 3, 'hold', 'short'))->toThrow(ValidationException::class, '10 to 2000');
    ($give($this->otherUser, 5))();
    expect($i1->fresh())->toMatchArray(['state' => 'done', 'rating' => 5, 'recommendation' => 'hire'])
        ->and($give($this->otherUser))->toThrow(ValidationException::class, 'already done');
});

it('makes an offer only after someone recommends hiring, once, and withdraws the application when it is declined', function () {
    $a = ($this->applicant)('Asha', '9876500001');
    ($this->as)($this->hrUser);
    $i = $this->rec->scheduleInterview($a, '2026-10-13T11:00', 'in_person', $this->otherUser->id, $this->hrUser);

    expect(fn () => $this->rec->makeOffer($a->fresh(), null, '2026-11-02', null, $this->hrUser))->toThrow(ValidationException::class, 'recommended hiring');

    Carbon::setTestNow('2026-10-13 12:00:00');
    ($this->as)($this->otherUser);
    $this->rec->recordFeedback($i, 3, 'hold', 'Needs more time on the product side.', $this->otherUser);
    ($this->as)($this->hrUser);
    expect(fn () => $this->rec->makeOffer($a->fresh(), null, '2026-11-02', null, $this->hrUser))->toThrow(ValidationException::class, 'recommended hiring');   // "hold" is not "hire"

    $second = $this->rec->scheduleInterview($a->fresh(), '2026-10-14T11:00', 'in_person', $this->otherUser->id, $this->hrUser);
    Carbon::setTestNow('2026-10-14 12:00:00');
    ($this->as)($this->otherUser);
    $this->rec->recordFeedback($second, 5, 'hire', 'Much better the second time round.', $this->otherUser);

    ($this->as)($this->hrUser);
    expect(fn () => $this->rec->makeOffer($a->fresh(), null, '2026-10-01', null, $this->hrUser))->toThrow(ValidationException::class, 'joining date')
        ->and(fn () => $this->rec->makeOffer($a->fresh(), null, '2027-06-01', null, $this->hrUser))->toThrow(ValidationException::class, 'joining date')
        ->and(fn () => $this->rec->makeOffer($a->fresh(), 9999, '2026-11-02', null, $this->hrUser))->toThrow(ValidationException::class, 'designation');

    $offer = $this->rec->makeOffer($a->fresh(), null, '2026-11-02', 'Welcome aboard', $this->hrUser);
    expect($offer->state)->toBe('sent')->and($offer->designation_id)->toBe($this->exec->id)->and($a->fresh()->stage)->toBe('offer')
        ->and(fn () => $this->rec->makeOffer($a->fresh(), null, '2026-11-02', null, $this->hrUser))->toThrow(ValidationException::class, 'interview stage');

    expect(fn () => $this->rec->respondToOffer($offer, 'maybe', '2026-10-14', $this->hrUser))->toThrow(ValidationException::class)
        ->and(fn () => $this->rec->respondToOffer($offer, 'accepted', '2026-10-20', $this->hrUser))->toThrow(ValidationException::class, 'not in the future');
    $this->rec->respondToOffer($offer, 'declined', '2026-10-14', $this->hrUser);
    expect($offer->fresh()->state)->toBe('declined')->and($a->fresh()->stage)->toBe('withdrawn')->and($a->fresh()->reject_reason)->toContain('declined')
        ->and(fn () => $this->rec->respondToOffer($offer->fresh(), 'accepted', '2026-10-14', $this->hrUser))->toThrow(ValidationException::class, 'already been answered');
});

it('hires an accepted offer into a real employee file, once, and closes the opening when the last position is filled', function () {
    $opening = ($this->opening)(['positions' => '2']);
    $a = ($this->readyToHire)(($this->applicant)('Asha Kapoor', '9876500001', $opening));

    ($this->as)($this->hrUser);
    $e = $this->rec->hire($a, $this->hrUser);

    expect($e->name)->toBe('Asha Kapoor')->and($e->code)->toBe('EMP-00001')->and($e->mobile)->toBe('9876500001')->and($e->personal_email)->toBe('asha.kapoor@mail.test')
        ->and($e->department_id)->toBe($this->sales->id)->and($e->designation_id)->toBe($this->exec->id)->and($e->reports_to_id)->toBe($this->boss->id)
        ->and($e->location_id)->toBe($this->ahd->id)->and($e->joining_date->toDateString())->toBe('2026-11-02')->and($e->employment_type)->toBe('probation')
        ->and($e->probation_end_date->toDateString())->toBe('2027-05-02')                                      // six months after joining
        ->and($a->fresh()->stage)->toBe('hired')->and($a->fresh()->hired_employee_id)->toBe($e->id)
        ->and($opening->fresh()->state)->toBe('open')                                                          // one of two positions
        ->and(ApplicationEvent::query()->where('application_id', $a->id)->orderByDesc('id')->first()->note)->toContain($e->code);
    Notification::assertSentTo($this->bossUser, HrNotice::class, fn (HrNotice $n) => $n->kind === 'new_joiner');

    expect(fn () => $this->rec->hire($a->fresh(), $this->hrUser))->toThrow(ValidationException::class, 'accepted can be hired');   // not twice

    // the second position fills and the opening closes itself
    $b = ($this->readyToHire)(($this->applicant)('Bina Shah', '9876500002', $opening->fresh()));
    $this->rec->hire($b, $this->hrUser);
    expect($opening->fresh()->state)->toBe('closed')->and($opening->fresh()->close_reason)->toBe('All positions filled.')->and(Employee::query()->where('remarks', 'like', 'Hired through JOB-0001%')->count())->toBe(2)
        ->and(fn () => $this->rec->openOpening($opening->fresh(), $this->hrUser))->toThrow(ValidationException::class, 'All the positions are filled');
});

it('refuses to hire beyond the positions, without an accepted offer, or without the right to add employees', function () {
    $opening = ($this->opening)(['positions' => '1']);
    $a = ($this->readyToHire)(($this->applicant)('Asha', '9876500001', $opening));
    $b = ($this->applicant)('Bina', '9876500002', $opening);                                   // a second applicant to the same single position

    ($this->as)($this->hrUser);
    expect(fn () => $this->rec->hire($b, $this->hrUser))->toThrow(ValidationException::class, 'accepted can be hired');

    // an admin without employee.create cannot hire
    $recruiter = makeUser([], [$this->acme]);
    $recruiter->givePermissionTo(['recruitment.edit', 'recruitment.view']);
    expect(fn () => ($this->as)($recruiter) && $this->rec->hire($a, $recruiter))->toThrow(AuthorizationException::class, 'add employees');

    ($this->as)($this->hrUser);
    $this->rec->hire($a, $this->hrUser);
    expect($opening->fresh()->state)->toBe('closed')->and(Employee::query()->count())->toBe(4);
});

// ---- performance ----------------------------------------------------------------------------------------------------------------------------

it('opens a cycle by making one review for everyone who had joined by its end, once', function () {
    ($this->hire)('L1', 'Late joiner', null, ['joining_date' => '2026-12-15']);
    ($this->hire)('L2', 'After the cycle', null, ['joining_date' => '2027-01-05']);
    ($this->as)($this->hrUser);

    $cycle = $this->perf->saveCycle(['code' => 'C1', 'name' => 'Annual review', 'period_start' => '2026-04-01', 'period_end' => '2026-12-31'], $this->hrUser);
    expect($cycle->state)->toBe('draft');

    $try = fn (array $over) => fn () => $this->perf->saveCycle(['code' => 'C2', 'name' => 'x', 'period_start' => '2026-04-01', 'period_end' => '2026-12-31', ...$over], $this->hrUser);
    expect($try(['code' => 'C1']))->toThrow(ValidationException::class, 'already used')
        ->and($try(['period_end' => '2026-03-01']))->toThrow(ValidationException::class, 'last not before the first')
        ->and($try(['name' => '']))->toThrow(ValidationException::class, 'name');

    $made = $this->perf->openCycle($cycle, $this->hrUser);
    expect($made)->toBe(4)                                                                                                    // boss, worker, other and the late joiner; not L2, who joins after the end
        ->and(PerformanceReview::query()->where('review_cycle_id', $cycle->id)->count())->toBe(4)
        ->and(PerformanceReview::query()->where('employee_id', $this->worker->id)->first()->reviewer_id)->toBe($this->bossUser->id)   // the manager's login, fixed at the start
        ->and(fn () => $this->perf->openCycle($cycle->fresh(), $this->hrUser))->toThrow(ValidationException::class, 'Only a draft')
        ->and(fn () => $this->perf->saveCycle(['code' => 'C1', 'name' => 'New name', 'period_start' => '2026-04-01', 'period_end' => '2026-12-31'], $this->hrUser, $cycle->fresh()))->toThrow(ValidationException::class, 'Only a draft');

    // asking for a review again makes nothing new
    expect($this->perf->ensureReview($this->worker, $cycle->fresh())->id)->toBe(PerformanceReview::query()->where('employee_id', $this->worker->id)->value('id'))
        ->and(PerformanceReview::query()->count())->toBe(4);
});

it('lets the manager line and HR set weighted goals, and never the person themself', function () {
    $cycle = ($this->openCycle)();

    $set = fn ($user, array $goal, $who = null) => function () use ($user, $goal, $who, $cycle) {
        ($this->as)($user);

        return $this->perf->saveGoal($who ?? $this->worker, $cycle->fresh(), $goal, $user);
    };

    expect($set($this->workerUser, ['title' => 'Own goal', 'weight' => '50']))->toThrow(AuthorizationException::class)
        ->and($set($this->otherUser, ['title' => 'Nosy', 'weight' => '50']))->toThrow(AuthorizationException::class);

    $g1 = ($set($this->bossUser, ['title' => 'Close 20 deals', 'description' => 'By December', 'weight' => '60']))();
    $g2 = ($set($this->hrUser, ['title' => 'Learn the product', 'weight' => '40']))();
    expect(PerformanceGoal::query()->where('employee_id', $this->worker->id)->count())->toBe(2)
        ->and($set($this->bossUser, ['title' => 'One too many', 'weight' => '1']))->toThrow(ValidationException::class, '0 left')   // 60 + 40 = 100
        ->and($set($this->bossUser, ['title' => '', 'weight' => '10']))->toThrow(ValidationException::class, 'goal is')
        ->and($set($this->bossUser, ['title' => 'Bad weight', 'weight' => '101']))->toThrow(ValidationException::class, '1 to 100');

    // changing a goal re-checks the total without counting itself
    ($this->as)($this->bossUser);
    $this->perf->saveGoal($this->worker, $cycle->fresh(), ['title' => 'Close 25 deals', 'weight' => '60'], $this->bossUser, $g1);
    expect($g1->fresh()->title)->toBe('Close 25 deals')
        ->and(fn () => $this->perf->saveGoal($this->worker, $cycle->fresh(), ['title' => 'x', 'weight' => '70'], $this->bossUser, $g1))->toThrow(ValidationException::class, '60 left');

    // at most ten goals
    ($this->as)($this->hrUser);
    foreach (range(1, 8) as $n) {
        $this->perf->saveGoal($this->other, $cycle->fresh(), ['title' => 'Goal '.$n, 'weight' => '5'], $this->hrUser);
    }
    $this->perf->saveGoal($this->other, $cycle->fresh(), ['title' => 'Goal 9', 'weight' => '5'], $this->hrUser);
    $this->perf->saveGoal($this->other, $cycle->fresh(), ['title' => 'Goal 10', 'weight' => '5'], $this->hrUser);
    expect(fn () => $this->perf->saveGoal($this->other, $cycle->fresh(), ['title' => 'Goal 11', 'weight' => '5'], $this->hrUser))->toThrow(ValidationException::class, 'At most 10');

    // removing
    ($this->as)($this->bossUser);
    $this->perf->removeGoal($g2, $this->bossUser);
    expect(PerformanceGoal::query()->where('employee_id', $this->worker->id)->count())->toBe(1)
        ->and(fn () => ($this->as)($this->workerUser) && $this->perf->removeGoal($g1, $this->workerUser))->toThrow(AuthorizationException::class);
});

it('lets the person update their own progress, works out the weighted score by hand, and fixes the goals once the manager has rated', function () {
    ($this->as)($this->hrUser);
    $cycle = $this->perf->saveCycle(['code' => 'C1', 'name' => 'Annual', 'period_start' => '2026-04-01', 'period_end' => '2026-12-31'], $this->hrUser);
    $this->perf->openCycle($cycle, $this->hrUser);
    $g1 = $this->perf->saveGoal($this->worker, $cycle->fresh(), ['title' => 'Deals', 'weight' => '60'], $this->hrUser);
    $g2 = $this->perf->saveGoal($this->worker, $cycle->fresh(), ['title' => 'Product', 'weight' => '40'], $this->hrUser);
    expect($this->perf->score($this->worker, $cycle))->toBe('0.0')->and($this->perf->score($this->other, $cycle))->toBeNull();

    ($this->as)($this->workerUser);
    $this->perf->setProgress($g1, 50, $this->workerUser);
    $this->perf->setProgress($g2, 100, $this->workerUser);
    expect($this->perf->score($this->worker, $cycle))->toBe('70.0')                                                          // (60 x 50 + 40 x 100) / 100
        ->and(fn () => $this->perf->setProgress($g1, 101, $this->workerUser))->toThrow(ValidationException::class, '0 to 100')
        ->and(fn () => ($this->as)($this->otherUser) && $this->perf->setProgress($g1, 10, $this->otherUser))->toThrow(AuthorizationException::class);

    // self review → manager review → the goals are fixed
    ($this->as)($this->workerUser);
    $review = PerformanceReview::query()->where('employee_id', $this->worker->id)->firstOrFail();
    $this->perf->selfReview($review, 4, 'I met most of my goals this period.', $this->workerUser);
    ($this->as)($this->bossUser);
    $this->perf->managerReview($review->fresh(), 3, 'Solid, but the deal count fell short.', $this->bossUser);

    expect(fn () => $this->perf->setProgress($g1, 90, $this->bossUser))->toThrow(ValidationException::class, 'goals are fixed')
        ->and(fn () => $this->perf->saveGoal($this->worker, $cycle->fresh(), ['title' => 'New', 'weight' => '1'], $this->bossUser))->toThrow(ValidationException::class, 'goals are fixed');
});

it('runs a review from the person to the manager to HR, never lets anyone rate themself, and locks it when final', function () {
    ($this->as)($this->hrUser);
    $cycle = $this->perf->saveCycle(['code' => 'C1', 'name' => 'Annual', 'period_start' => '2026-04-01', 'period_end' => '2026-12-31'], $this->hrUser);
    $this->perf->openCycle($cycle, $this->hrUser);
    $review = PerformanceReview::query()->where('employee_id', $this->worker->id)->firstOrFail();

    // the manager waits for the person
    expect(fn () => ($this->as)($this->bossUser) && $this->perf->managerReview($review, 3, 'A fair period overall.', $this->bossUser))->toThrow(ValidationException::class, 'not done their self-review')
        ->and(fn () => ($this->as)($this->bossUser) && $this->perf->selfReview($review, 3, 'Rating for someone else.', $this->bossUser))->toThrow(AuthorizationException::class, 'Only the person');

    ($this->as)($this->workerUser);
    expect(fn () => $this->perf->selfReview($review, 9, 'Far too generous to myself.', $this->workerUser))->toThrow(ValidationException::class, '1 to 5')
        ->and(fn () => $this->perf->selfReview($review, 4, 'short', $this->workerUser))->toThrow(ValidationException::class, '10 to 2000');
    $this->perf->selfReview($review, 4, 'I did well on every goal this year.', $this->workerUser);
    expect($review->fresh()->state)->toBe('self_done');
    Notification::assertSentTo($this->bossUser, HrNotice::class, fn (HrNotice $n) => $n->kind === 'review_waiting');

    // they may change their mind until the manager has rated
    $this->perf->selfReview($review->fresh(), 3, 'On reflection, a little less well than I said.', $this->workerUser);
    expect($review->fresh()->self_rating)->toBe(3)
        ->and(fn () => $this->perf->managerReview($review->fresh(), 3, 'I rate myself, which I must not.', $this->workerUser))->toThrow(AuthorizationException::class, 'yourself')
        ->and(fn () => ($this->as)($this->otherUser) && $this->perf->managerReview($review->fresh(), 3, 'Not their manager at all.', $this->otherUser))->toThrow(AuthorizationException::class, 'Only their manager');

    ($this->as)($this->bossUser);
    $this->perf->managerReview($review->fresh(), 5, 'Outstanding results, well beyond the plan.', $this->bossUser);
    expect($review->fresh())->toMatchArray(['state' => 'manager_done', 'manager_rating' => 5, 'reviewer_id' => $this->bossUser->id])
        ->and(fn () => ($this->as)($this->workerUser) && $this->perf->selfReview($review->fresh(), 4, 'Changing it after the manager rated.', $this->workerUser))->toThrow(ValidationException::class, 'already rated');

    // HR makes it final: only with the right, only after the manager, 1–5, locked afterwards
    expect(fn () => $this->perf->finalise($review->fresh(), null, $this->bossUser))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->as)($this->hrUser) && $this->perf->finalise($review->fresh(), 9, $this->hrUser))->toThrow(ValidationException::class, '1 to 5');
    $this->perf->finalise($review->fresh(), 4, $this->hrUser);      // HR moderates 5 down to 4
    expect($review->fresh())->toMatchArray(['state' => 'finalised', 'final_rating' => 4, 'finalised_by' => $this->hrUser->id])
        ->and(fn () => $review->fresh()->forceFill(['final_rating' => 5])->save())->toThrow(LogicException::class)
        ->and(fn () => $this->perf->finalise($review->fresh(), null, $this->hrUser))->toThrow(ValidationException::class, 'Only a review the manager has rated');
    Notification::assertSentTo($this->workerUser, HrNotice::class, fn (HrNotice $n) => $n->kind === 'review_final' && str_contains($n->message, '4 of 5'));

    // a closed cycle takes no more
    $this->perf->closeCycle($cycle->fresh(), $this->hrUser);
    $other = PerformanceReview::query()->where('employee_id', $this->other->id)->firstOrFail();
    expect(fn () => ($this->as)($this->otherUser) && $this->perf->selfReview($other, 3, 'Too late: the cycle is closed.', $this->otherUser))->toThrow(ValidationException::class, 'not open');
});

// ---- training -------------------------------------------------------------------------------------------------------------------------------

it('plans a program, enrols within the seats, and lets a manager enrol only their own line', function () {
    ($this->as)($this->hrUser);
    $program = $this->train->saveProgram(['code' => 'T1', 'title' => 'Selling skills', 'trainer' => 'A. Rao', 'mode' => 'in_person', 'venue' => 'Room 2', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-21', 'capacity' => '2'], $this->hrUser);

    $try = fn (array $over) => fn () => $this->train->saveProgram(['code' => 'T2', 'title' => 'x', 'mode' => 'online', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-21', 'capacity' => '5', ...$over], $this->hrUser);
    expect($try(['code' => 'T1']))->toThrow(ValidationException::class, 'already used')
        ->and($try(['ends_on' => '2026-10-19']))->toThrow(ValidationException::class, 'last not before the first')
        ->and($try(['capacity' => '0']))->toThrow(ValidationException::class, '1 to 500')
        ->and($try(['mode' => 'carrier pigeon']))->toThrow(ValidationException::class, 'in person or online');

    $this->train->enrol($program, $this->worker, $this->hrUser);                                       // HR enrols anyone
    expect(fn () => $this->train->enrol($program, $this->worker, $this->hrUser))->toThrow(ValidationException::class, 'already enrolled');
    Notification::assertSentTo($this->workerUser, HrNotice::class, fn (HrNotice $n) => $n->kind === 'training_enrolled');

    // a manager enrols their own line, not others, not themself
    expect(fn () => ($this->as)($this->bossUser) && $this->train->enrol($program, $this->other, $this->bossUser))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->as)($this->bossUser) && $this->train->enrol($program, $this->boss, $this->bossUser))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->as)($this->workerUser) && $this->train->enrol($program, $this->worker, $this->workerUser))->toThrow(AuthorizationException::class);

    ($this->as)($this->hrUser);
    $this->train->enrol($program, $this->boss, $this->hrUser);
    expect($this->train->seatsTaken($program))->toBe(2)
        ->and(fn () => $this->train->enrol($program, $this->other, $this->hrUser))->toThrow(ValidationException::class, 'is full (2 seats)')
        ->and(fn () => $this->train->saveProgram(['code' => 'T1', 'title' => 'Selling skills', 'mode' => 'in_person', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-21', 'capacity' => '1'], $this->hrUser, $program->fresh()))
        ->toThrow(ValidationException::class, 'Already 2 people');

    DB::table('employees')->where('id', $this->other->id)->update(['employment_status' => 'exited']);
    $roomy = $this->train->saveProgram(['code' => 'T3', 'title' => 'Open seats', 'mode' => 'online', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'capacity' => '10'], $this->hrUser);
    expect(fn () => $this->train->enrol($roomy, $this->other->fresh(), $this->hrUser))->toThrow(ValidationException::class, 'has left');
});

it('records outcomes from the first day, completes only when every outcome is in, and tells people when it is cancelled', function () {
    ($this->as)($this->hrUser);
    $program = $this->train->saveProgram(['code' => 'T1', 'title' => 'Selling skills', 'mode' => 'in_person', 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-15', 'capacity' => '5'], $this->hrUser);
    $w = $this->train->enrol($program, $this->worker, $this->hrUser);
    $o = $this->train->enrol($program, $this->other, $this->hrUser);

    expect(fn () => $this->train->record($w, 'completed', '80', $this->hrUser))->toThrow(ValidationException::class, 'from that day')
        ->and(fn () => $this->train->completeProgram($program->fresh(), $this->hrUser))->toThrow(ValidationException::class, 'planned program that has started');

    Carbon::setTestNow('2026-10-15 17:00:00');
    expect(fn () => $this->train->record($w, 'completed', '180', $this->hrUser))->toThrow(ValidationException::class, 'score')
        ->and(fn () => $this->train->record($w, 'absent', '50', $this->hrUser))->toThrow(ValidationException::class, 'completed training only')
        ->and(fn () => $this->train->record($w, 'sleeping', null, $this->hrUser))->toThrow(ValidationException::class, 'Choose completed')
        ->and(fn () => ($this->as)($this->workerUser) && $this->train->record($w, 'completed', '90', $this->workerUser))->toThrow(AuthorizationException::class);

    // a manager records their own line
    ($this->as)($this->bossUser);
    $this->train->record($w, 'completed', '82.5', $this->bossUser);
    expect($w->fresh())->toMatchArray(['state' => 'completed', 'score' => '82.5']);

    ($this->as)($this->hrUser);
    expect(fn () => $this->train->completeProgram($program->fresh(), $this->hrUser))->toThrow(ValidationException::class, '1 person has no outcome yet');
    $this->train->record($o, 'absent', null, $this->hrUser);
    $this->train->completeProgram($program->fresh(), $this->hrUser);
    expect($program->fresh()->state)->toBe('completed')
        ->and(fn () => $this->train->record($o, 'completed', '60', $this->hrUser))->toThrow(ValidationException::class, 'outcomes are fixed')
        ->and(fn () => $this->train->cancelProgram($program->fresh(), $this->hrUser))->toThrow(ValidationException::class, 'Only a planned program');

    // cancelling a planned one drops everyone and tells them
    $later = $this->train->saveProgram(['code' => 'T2', 'title' => 'Excel', 'mode' => 'online', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-10', 'capacity' => '5'], $this->hrUser);
    $this->train->enrol($later, $this->worker, $this->hrUser);
    $this->train->cancelProgram($later, $this->hrUser);
    expect($later->fresh()->state)->toBe('cancelled')->and(TrainingEnrolment::query()->where('training_program_id', $later->id)->value('state'))->toBe('dropped');
    Notification::assertSentTo($this->workerUser, HrNotice::class, fn (HrNotice $n) => $n->kind === 'training_cancelled');
});

// ---- screens ---------------------------------------------------------------------------------------------------------------------------------

it('adds an opening, an applicant and moves an application from the screens, and refuses what the service refuses', function () {
    ($this->as)($this->hrUser);

    Livewire::test(OpeningList::class)->call('create')->set('f.title', 'Accountant')->set('f.location_id', (string) $this->ahd->id)->set('f.positions', '2')->call('save')->assertHasNoErrors();
    $opening = JobOpening::query()->where('title', 'Accountant')->firstOrFail();
    expect($opening->state)->toBe('draft');
    Livewire::test(OpeningList::class)->call('create')->set('f.title', '')->set('f.location_id', (string) $this->ahd->id)->call('save')->assertHasErrors(['title']);

    Livewire::test(OpeningShow::class, ['opening' => $opening])->call('openIt')->assertHasNoErrors()->call('openPanel', 'apply')
        ->set('new.name', 'Chitra Nair')->set('new.mobile', '9876500009')->call('addApplicant')->assertHasNoErrors();
    expect(JobApplication::query()->where('job_opening_id', $opening->id)->count())->toBe(1);

    // a refused application leaves no candidate behind
    $closed = ($this->opening)(['code' => 'JOB-0050'], false);
    $before = Candidate::query()->count();
    Livewire::test(OpeningShow::class, ['opening' => $closed])->call('openPanel', 'apply')->set('new.name', 'Nobody Yet')->set('new.mobile', '9876500010')->call('addApplicant')->assertHasErrors(['job_opening_id']);
    expect(Candidate::query()->count())->toBe($before);

    $application = JobApplication::query()->firstOrFail();
    Livewire::test(ApplicationShow::class, ['application' => $application])->call('advanceTo', 'screening')->assertHasNoErrors()
        ->call('startReason', 'rejected')->call('confirmReason')->assertHasErrors(['note'])
        ->set('note', 'Not enough experience')->call('confirmReason')->assertHasNoErrors();
    expect($application->fresh()->stage)->toBe('rejected');

    Livewire::test(CandidateList::class)->assertSee('Chitra Nair')->call('create')->set('f.name', 'Dev Menon')->set('f.mobile', '9876500011')->call('save')->assertHasNoErrors();
    expect(Candidate::query()->where('name', 'Dev Menon')->exists())->toBeTrue();
});

it('books an interview and lets the interviewer write the feedback from their own screen', function () {
    $a = ($this->applicant)('Asha', '9876500001');
    ($this->as)($this->hrUser);

    Livewire::test(ApplicationShow::class, ['application' => $a])->call('openPanel', 'interview')->set('slot.scheduled_at', '2026-10-13T11:00')->set('slot.interviewer_id', (string) $this->otherUser->id)
        ->call('scheduleIt')->assertHasNoErrors();
    $interview = Interview::query()->firstOrFail();

    ($this->as)($this->otherUser);
    Carbon::setTestNow('2026-10-13 12:00:00');
    Livewire::test(MyInterviews::class)->assertSee('Asha')->call('startFeedback', $interview->id)->set('form.rating', '4')->set('form.recommendation', 'hire')->set('form.feedback', 'Good grasp of the basics.')
        ->call('sendFeedback')->assertHasNoErrors();
    expect($interview->fresh()->state)->toBe('done');

    // somebody else's interview is not theirs to open
    ($this->as)($this->bossUser);
    Livewire::test(MyInterviews::class)->assertDontSee('Asha')->set('giving', $interview->id)->set('form.rating', '3')->call('sendFeedback')->assertNotFound();
});

it('lets a person do their self-review and a manager rate and HR finalise, all from the screens', function () {
    ($this->as)($this->hrUser);
    Livewire::test(CycleList::class)->call('create')->set('f.code', 'C1')->set('f.name', 'Annual')->set('f.period_start', '2026-04-01')->set('f.period_end', '2026-12-31')->call('save')->assertHasNoErrors();
    $cycle = ReviewCycle::query()->firstOrFail();
    Livewire::test(CycleList::class)->call('openIt', $cycle->id)->assertHasNoErrors();
    expect(PerformanceReview::query()->count())->toBe(3);

    ($this->as)($this->bossUser);
    Livewire::test(TeamPerformance::class)->assertSee('Worker')->assertDontSee('Other')->call('pick', $this->worker->id)->set('goal.title', 'Close deals')->set('goal.weight', '100')->call('saveGoal')->assertHasNoErrors();
    $goal = PerformanceGoal::query()->firstOrFail();

    ($this->as)($this->workerUser);
    Livewire::test(MyPerformance::class)->assertSee('Close deals')->set('progress.'.$goal->id, '40')->call('saveProgress', $goal->id)->assertHasNoErrors()->assertSee('40.0%')
        ->set('self.rating', '4')->set('self.comments', 'A good period for me.')->call('saveSelfReview')->assertHasNoErrors();

    ($this->as)($this->bossUser);
    Livewire::test(TeamPerformance::class)->call('pick', $this->worker->id)->set('rate.rating', '4')->set('rate.comments', 'Agreed: a good period.')->call('saveManagerReview')->assertHasNoErrors()
        ->call('makeFinal')->assertHasErrors(['action']);                                           // a manager cannot make it final

    ($this->as)($this->hrUser);
    Livewire::test(TeamPerformance::class)->call('pick', $this->worker->id)->call('makeFinal')->assertHasNoErrors();
    expect(PerformanceReview::query()->where('employee_id', $this->worker->id)->first())->toMatchArray(['state' => 'finalised', 'final_rating' => 4]);

    ($this->as)($this->workerUser);
    Livewire::test(MyPerformance::class)->assertSee('4 of 5');
});

it('enrols and records from the program screen, and shows people their own training', function () {
    ($this->as)($this->hrUser);
    $program = $this->train->saveProgram(['code' => 'T1', 'title' => 'Selling skills', 'mode' => 'in_person', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-13', 'capacity' => '3'], $this->hrUser);

    $component = Livewire::test(ProgramShow::class, ['program' => $program])->set('employeeId', (string) $this->worker->id)->call('enrolPerson')->assertHasNoErrors();
    $row = TrainingEnrolment::query()->firstOrFail();
    $component->set('outcome.'.$row->id, 'completed')->set('score.'.$row->id, '88')->call('recordOutcome', $row->id)->assertHasNoErrors();
    expect($row->fresh())->toMatchArray(['state' => 'completed', 'score' => '88.0']);

    ($this->as)($this->workerUser);
    $this->get('/hr/training')->assertOk()->assertSee('Selling skills')->assertSee('Completed')->assertSee('88');
    ($this->as)($this->otherUser);
    $this->get('/hr/training')->assertOk()->assertSee('not enrolled');
});

it('opens the talent pages for those who may, keeps the rest out, and puts them in the menu', function () {
    ($this->as)($this->hrUser);
    foreach (['/hr/recruitment/openings', '/hr/recruitment/candidates', '/hr/recruitment/interviews', '/hr/performance/cycles', '/hr/performance', '/hr/performance/team', '/hr/training/programs', '/hr/training'] as $url) {
        $this->get($url)->assertOk();
    }

    ($this->as)($this->workerUser);
    foreach (['/hr/recruitment/interviews', '/hr/performance', '/hr/training'] as $url) {
        $this->get($url)->assertOk();
    }
    foreach (['/hr/recruitment/openings', '/hr/recruitment/candidates', '/hr/performance/cycles', '/hr/performance/team', '/hr/training/programs'] as $url) {
        $this->get($url)->assertForbidden();
    }

    ($this->as)($this->bossUser);
    $this->get('/hr/performance/team')->assertOk();
    $this->get('/hr/training/programs')->assertOk();
    $this->get('/hr/recruitment/openings')->assertForbidden();
    $this->get('/dashboard')->assertOk()->assertSee('Team performance')->assertSee('My interviews')->assertDontSee('Job openings');

    ($this->as)($this->hrUser);
    $this->get('/dashboard')->assertOk()->assertSee('Job openings')->assertSee('Review cycles')->assertSee('Training programs');

    ($this->as)($this->outsider);
    expect(JobOpening::query()->count())->toBe(0);
});

it('has the talent permissions on the right roles', function () {
    expect($this->hrUser->can('recruitment.edit'))->toBeTrue()->and($this->hrUser->can('performance.finalise'))->toBeTrue()->and($this->hrUser->can('performance.view_all'))->toBeTrue()
        ->and($this->hrUser->can('training.edit'))->toBeTrue()->and($this->hrUser->can('review_cycle.edit'))->toBeTrue()
        ->and($this->bossUser->can('performance.edit'))->toBeTrue()->and($this->bossUser->can('training.enrol_team'))->toBeTrue()->and($this->bossUser->can('performance.finalise'))->toBeFalse()
        ->and($this->bossUser->can('recruitment.view'))->toBeFalse()->and($this->workerUser->can('performance.view'))->toBeFalse();
});

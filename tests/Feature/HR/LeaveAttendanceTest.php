<?php

use App\Modules\HR\Http\Livewire\AttendanceCorrections;
use App\Modules\HR\Http\Livewire\HolidayList;
use App\Modules\HR\Http\Livewire\LeaveBalances;
use App\Modules\HR\Http\Livewire\LeaveRequestList;
use App\Modules\HR\Http\Livewire\MyAttendance;
use App\Modules\HR\Http\Livewire\MyLeave;
use App\Modules\HR\Http\Livewire\ShiftList;
use App\Modules\HR\Http\Livewire\TeamAttendance;
use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\AttendancePunch;
use App\Modules\HR\Models\AttendanceRegularisation;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveLedgerEntry;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\Shift;
use App\Modules\HR\Notifications\HrNotice;
use App\Modules\HR\Services\AttendanceService;
use App\Modules\HR\Services\EmployeeAccess;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\HR\Services\LeaveAccrual;
use App\Modules\HR\Services\LeaveService;
use App\Modules\HR\Services\WorkCalendar;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use App\Modules\Workspace\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * H2: leave (ledger, accrual, requests, decisions) and attendance (punches, the day-close, corrections). "Today" is Monday 12 October 2026; 15 October is a holiday; the weekly
 * off is Sunday. Everything is hand-calculated; see the comments.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');

    $this->hrUser = makeUser(['HR Manager'], [$this->acme]);
    $this->bossUser = makeUser(['Sales Manager'], [$this->acme]);
    $this->workerUser = makeUser(['Tele-caller'], [$this->acme]);
    $this->otherUser = makeUser(['Tele-caller'], [$this->acme]);
    $this->outsider = makeUser(['HR Manager'], [$this->globex]);

    $this->cl = inCompany($this->acme, fn () => LeaveType::query()->create(['code' => 'CL', 'name' => 'Casual leave', 'annual_quota' => '12', 'accrual' => 'yearly', 'carry_forward_max' => '5', 'allow_half_day' => true]));
    $this->sl = inCompany($this->acme, fn () => LeaveType::query()->create(['code' => 'SL', 'name' => 'Sick leave', 'annual_quota' => '12', 'accrual' => 'monthly', 'allow_half_day' => false]));
    $this->lwp = inCompany($this->acme, fn () => LeaveType::query()->create(['code' => 'LWP', 'name' => 'Leave without pay', 'is_paid' => false, 'annual_quota' => '0', 'accrual' => 'none', 'allow_negative' => true, 'allow_half_day' => true]));
    inCompany($this->acme, fn () => Holiday::query()->create(['holiday_date' => '2026-10-15', 'name' => 'Festival']));

    $this->hire = function (string $code, string $name, $user = null, array $over = []) {
        $this->actingAs($this->hrUser);
        app(TenantContext::class)->flush();
        app(EmployeeAccess::class)->flush();
        $e = app(EmployeeService::class)->create(['code' => $code, 'name' => $name, 'location_id' => (string) $this->ahd->id, 'joining_date' => '2026-01-05', 'employment_type' => 'permanent', ...$over], $this->hrUser);

        if ($user !== null) {
            app(EmployeeService::class)->linkUser($e, $user, $this->hrUser);
        }

        return $e;
    };
    $this->boss = ($this->hire)('B1', 'Boss', $this->bossUser);
    $this->worker = ($this->hire)('W1', 'Worker', $this->workerUser, ['reports_to_id' => (string) $this->boss->id]);
    $this->other = ($this->hire)('O1', 'Other', $this->otherUser);

    $this->leave = app(LeaveService::class);
    $this->attendance = app(AttendanceService::class);

    $this->as = function ($user) {
        $this->actingAs($user);
        app(TenantContext::class)->flush();
        app(EmployeeAccess::class)->flush();

        return $user;
    };
    $this->grant = function () {   // everyone gets this year's leave
        ($this->as)($this->hrUser);
        app(LeaveAccrual::class)->run(now());
    };
    $this->apply = function (Employee $e, $user, LeaveType $type, string $from, string $to, ?string $half = null, string $reason = 'Family function') {
        ($this->as)($user);

        return $this->leave->apply($e, $type, $from, $to, $half, $reason, $user);
    };
});

afterEach(fn () => Carbon::setTestNow());

// ---- the calendar ------------------------------------------------------------------------------------------------------------------

it('counts working days around holidays and the weekly off, by the company setting', function () {
    ($this->as)($this->hrUser);
    $calendar = app(WorkCalendar::class);

    // Mon 12 – Mon 19 Oct: 15th is a holiday, Sunday 18th is off => 12, 13, 14, 16, 17, 19
    expect($calendar->workingDays(CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-19')))->toBe(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-16', '2026-10-17', '2026-10-19'])
        ->and($calendar->isWeeklyOff(CarbonImmutable::parse('2026-10-18')))->toBeTrue()->and($calendar->isWeeklyOff(CarbonImmutable::parse('2026-10-17')))->toBeFalse();

    $settings = app(SettingsService::class);
    $settings->set('hr.weekly_off', 'saturday_sunday');
    expect($calendar->isWeeklyOff(CarbonImmutable::parse('2026-10-17')))->toBeTrue();

    $settings->set('hr.weekly_off', 'alternate_saturday');   // October 2026: Saturdays are 3, 10, 17, 24, 31 => the 2nd and 4th are the 10th and 24th
    expect($calendar->isWeeklyOff(CarbonImmutable::parse('2026-10-10')))->toBeTrue()->and($calendar->isWeeklyOff(CarbonImmutable::parse('2026-10-24')))->toBeTrue()
        ->and($calendar->isWeeklyOff(CarbonImmutable::parse('2026-10-03')))->toBeFalse()->and($calendar->isWeeklyOff(CarbonImmutable::parse('2026-10-17')))->toBeFalse()
        ->and($calendar->isWeeklyOff(CarbonImmutable::parse('2026-10-11')))->toBeTrue();   // Sunday

    $settings->set('hr.weekly_off', 'none');
    expect($calendar->isWeeklyOff(CarbonImmutable::parse('2026-10-11')))->toBeFalse();
});

// ---- accrual ------------------------------------------------------------------------------------------------------------------------

it('grants the yearly quota once at the start of the year and in proportion to someone who joins during it', function () {
    $late = ($this->hire)('L1', 'Late joiner', null, ['joining_date' => '2026-07-15']);
    ($this->grant)();

    $cl = fn (Employee $e) => $this->leave->balance($e, $this->cl, 2026);
    expect($cl($this->worker))->toBe('12.0')            // joined in January: the whole quota
        ->and($cl($late))->toBe('9.0');                 // joined in July: April is month 0, July is month 3 => 9 months left => 12 x 9 / 12

    $before = LeaveLedgerEntry::query()->count();
    expect(app(LeaveAccrual::class)->run(now()))->toBe(0)->and(LeaveLedgerEntry::query()->count())->toBe($before);   // a second run writes nothing
});

it('grants the monthly quota a twelfth at a time, in halves that add up to the quota, from the joining month', function () {
    $late = ($this->hire)('L1', 'Late joiner', null, ['joining_date' => '2026-07-15']);
    ($this->grant)();

    // 12 a year = 1 a month: April to October is 7 months; the July joiner gets July to October = 4
    expect($this->leave->balance($this->worker, $this->sl, 2026))->toBe('7.0')->and($this->leave->balance($late, $this->sl, 2026))->toBe('4.0');

    // 15 a year does not divide by twelve: in halves, 1.5, 1, 1.5 … and the twelve months make exactly 15
    $odd = inCompany($this->acme, fn () => LeaveType::query()->create(['code' => 'EL', 'name' => 'Earned leave', 'annual_quota' => '15', 'accrual' => 'monthly', 'allow_half_day' => true]));
    ($this->as)($this->hrUser);
    app(LeaveAccrual::class)->run(CarbonImmutable::parse('2027-03-31'));
    expect($this->leave->balance($this->worker, $odd, 2026))->toBe('15.0');
});

it('carries forward for someone who was here last year', function () {
    $old = ($this->hire)('OLD', 'Old hand', null, ['joining_date' => '2024-06-01']);
    ($this->as)($this->hrUser);
    app(LeaveAccrual::class)->run(CarbonImmutable::parse('2025-10-01'));              // 2025: the yearly grant of 12
    $this->leave->adjust($old, $this->cl, '-4', 'Used before the system', $this->hrUser, '2025-10-02');
    expect($this->leave->balance($old, $this->cl, 2025))->toBe('8.0');

    app(LeaveAccrual::class)->run(CarbonImmutable::parse('2026-04-02'));
    expect($this->leave->balance($old, $this->cl, 2026))->toBe('17.0')               // 5 carried (the limit) + 12 granted for the new year
        ->and($this->leave->balance($old, $this->cl, 2025))->toBe('5.0');            // 8 less the 3 that lapsed

    $lapse = LeaveLedgerEntry::query()->where('employee_id', $old->id)->where('leave_type_id', $this->cl->id)->where('entry_type', 'lapse')->firstOrFail();
    expect($lapse->days)->toBe('-3.0')->and($lapse->leave_year)->toBe(2025);

    app(LeaveAccrual::class)->run(CarbonImmutable::parse('2026-04-03'));              // nothing twice
    expect(LeaveLedgerEntry::query()->where('employee_id', $old->id)->where('leave_type_id', $this->cl->id)->where('entry_type', 'lapse')->count())->toBe(1)
        ->and($this->leave->balance($old, $this->cl, 2026))->toBe('17.0');
});

it('never changes or removes a ledger line, and writes a line once whatever is tried', function () {
    ($this->grant)();
    $entry = LeaveLedgerEntry::query()->firstOrFail();

    expect(fn () => $entry->forceFill(['days' => '99'])->save())->toThrow(LogicException::class)->and(fn () => $entry->delete())->toThrow(LogicException::class);
    expect(fn () => $this->leave->post($this->worker, $this->cl, 2026, 'accrual', '1.0', 'Y2026', now(), null, null, 'again'))->toThrow(UniqueConstraintViolationException::class);
});

// ---- applying -------------------------------------------------------------------------------------------------------------------------

it('refuses a request that cannot be: dates, half days, overlap, balance, holidays, a person who has left', function () {
    ($this->grant)();
    ($this->apply)($this->worker, $this->workerUser, $this->cl, '2026-10-12', '2026-10-14');   // 12, 13, 14 => 3 days, waiting

    $try = fn (string $from, string $to, ?string $half = null, ?LeaveType $type = null, string $reason = 'Trip', ?Employee $who = null) => fn () => ($this->apply)($who ?? $this->worker, ($who ?? $this->worker)->id === $this->worker->id ? $this->workerUser : $this->otherUser, $type ?? $this->cl, $from, $to, $half, $reason);

    expect($try('2026-10-14', '2026-10-16'))->toThrow(ValidationException::class, 'overlaps')                          // 14th is taken
        ->and($try('2026-10-15', '2026-10-15'))->toThrow(ValidationException::class, 'no working day')                 // the holiday
        ->and($try('2026-10-18', '2026-10-18'))->toThrow(ValidationException::class, 'no working day')                 // a Sunday
        ->and($try('2026-10-20', '2026-10-19'))->toThrow(ValidationException::class, 'the last not before the first')
        ->and($try('2026-09-01', '2026-09-02'))->toThrow(ValidationException::class, '30 days back')
        ->and($try('2027-12-01', '2027-12-02'))->toThrow(ValidationException::class, 'within a year')
        ->and($try('2027-03-30', '2027-04-02'))->toThrow(ValidationException::class, 'two financial years')
        ->and($try('2026-10-20', '2026-10-22', 'first'))->toThrow(ValidationException::class, 'single day')
        ->and($try('2026-10-20', '2026-10-20', 'first', $this->sl))->toThrow(ValidationException::class, 'half day')     // sick leave: no half days
        ->and($try('2026-10-20', '2026-10-20', 'middle'))->toThrow(ValidationException::class, 'first or the second')
        ->and($try('2026-10-20', '2026-10-20', null, null, '  '))->toThrow(ValidationException::class, 'Say why')
        // 12 granted, 3 waiting => 9 left; asking for 11 working days 20–31 Oct (the Sunday 25th is off)
        ->and($try('2026-10-20', '2026-10-31'))->toThrow(ValidationException::class, 'You have 9 day(s)');

    // unpaid leave has no limit
    expect(($this->apply)($this->worker, $this->workerUser, $this->lwp, '2026-10-20', '2026-10-31')->days)->toBe('11.0');   // 20–24 Oct is 5 days, 26–31 Oct is 6

    // someone who has left
    DB::table('employees')->where('id', $this->other->id)->update(['employment_status' => 'exited']);
    expect($try('2026-10-20', '2026-10-20', null, null, 'Trip', $this->other->fresh()))->toThrow(ValidationException::class, 'left the company');
});

it('lets a person apply for themself, and HR for anyone, but nobody else', function () {
    ($this->grant)();

    expect(fn () => ($this->as)($this->otherUser) && $this->leave->apply($this->worker, $this->cl, '2026-10-20', '2026-10-20', null, 'x', $this->otherUser))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->as)($this->bossUser) && $this->leave->apply($this->worker, $this->cl, '2026-10-20', '2026-10-20', null, 'x', $this->bossUser))->toThrow(AuthorizationException::class);

    ($this->as)($this->hrUser);
    $r = $this->leave->apply($this->worker, $this->cl, '2026-10-20', '2026-10-20', null, 'On their behalf', $this->hrUser);
    expect($r->employee_id)->toBe($this->worker->id);
});

it('lets a first-half and a second-half request share a day, and nothing else', function () {
    ($this->grant)();
    $a = ($this->apply)($this->worker, $this->workerUser, $this->cl, '2026-10-20', '2026-10-20', 'first');
    $b = ($this->apply)($this->worker, $this->workerUser, $this->cl, '2026-10-20', '2026-10-20', 'second');
    expect($a->days)->toBe('0.5')->and($b->days)->toBe('0.5');

    expect(fn () => ($this->apply)($this->worker, $this->workerUser, $this->cl, '2026-10-20', '2026-10-20', 'first'))->toThrow(ValidationException::class, 'overlaps')
        ->and(fn () => ($this->apply)($this->worker, $this->workerUser, $this->cl, '2026-10-20', '2026-10-20'))->toThrow(ValidationException::class, 'overlaps');
});

// ---- deciding -----------------------------------------------------------------------------------------------------------------------

it('takes the days from the ledger when the manager approves, once, and gives them back when it is cancelled', function () {
    ($this->grant)();
    $r = ($this->apply)($this->worker, $this->workerUser, $this->cl, '2026-10-20', '2026-10-22');   // 3 working days, in the future

    ($this->as)($this->bossUser);
    $this->leave->approve($r, $this->bossUser, 'Enjoy');
    $r->refresh();
    expect($r->state)->toBe('approved')->and($r->decided_by)->toBe($this->bossUser->id)->and($r->decision_note)->toBe('Enjoy')
        ->and($this->leave->balance($this->worker, $this->cl, 2026))->toBe('9.0')                        // 12 - 3
        ->and(LeaveLedgerEntry::query()->where('leave_request_id', $r->id)->where('entry_type', 'taken')->count())->toBe(1)
        ->and(fn () => $this->leave->approve($r, $this->bossUser))->toThrow(ValidationException::class, 'already');

    // a decided request does not change
    expect(fn () => $r->forceFill(['days' => '9.0'])->save())->toThrow(LogicException::class);

    // the person takes it back before it starts: the days return
    ($this->as)($this->workerUser);
    $this->leave->cancel($r, $this->workerUser, 'Plans changed');
    expect($r->fresh()->state)->toBe('cancelled')->and($this->leave->balance($this->worker, $this->cl, 2026))->toBe('12.0')
        ->and(LeaveLedgerEntry::query()->where('leave_request_id', $r->id)->pluck('entry_type')->sort()->values()->all())->toBe(['reversal', 'taken'])
        ->and(fn () => $this->leave->cancel($r, $this->workerUser, 'Again'))->toThrow(ValidationException::class);
});

it('lets the right people decide and refuses everyone else, the person themself most of all', function () {
    ($this->grant)();
    $r = ($this->apply)($this->worker, $this->workerUser, $this->cl, '2026-10-20', '2026-10-20');

    expect(fn () => ($this->as)($this->workerUser) && $this->leave->approve($r, $this->workerUser))->toThrow(AuthorizationException::class, 'your own')       // themself
        ->and(fn () => ($this->as)($this->otherUser) && $this->leave->approve($r, $this->otherUser))->toThrow(AuthorizationException::class)                      // not their manager, no right
        ->and(fn () => ($this->as)($this->bossUser) && $this->leave->reject($r, $this->bossUser, ' '))->toThrow(ValidationException::class, 'Say why');

    // the manager above the manager also may (HR here), the boss may not decide their own
    ($this->as)($this->bossUser);
    $mine = ($this->apply)($this->boss, $this->bossUser, $this->cl, '2026-10-21', '2026-10-21');
    expect(fn () => $this->leave->approve($mine, $this->bossUser))->toThrow(AuthorizationException::class, 'your own');

    ($this->as)($this->hrUser);
    $this->leave->approve($mine, $this->hrUser);
    expect($mine->fresh()->state)->toBe('approved');

    ($this->as)($this->bossUser);
    $this->leave->reject($r, $this->bossUser, 'Busy week');
    expect($r->fresh()->state)->toBe('rejected')->and($r->fresh()->decision_note)->toBe('Busy week')->and($this->leave->balance($this->worker, $this->cl, 2026))->toBe('12.0');
});

it('checks the balance again when it is approved, and lets only the manager line or HR cancel leave that has started', function () {
    ($this->grant)();
    $r = ($this->apply)($this->worker, $this->workerUser, $this->cl, '2026-10-12', '2026-10-14');    // starts today: 3 days

    ($this->as)($this->hrUser);
    $this->leave->adjust($this->worker, $this->cl, '-10', 'Correction', $this->hrUser);               // 12 - 10 = 2 left, the request asks for 3
    expect(fn () => $this->leave->approve($r, $this->hrUser))->toThrow(ValidationException::class, 'no longer enough');

    $this->leave->adjust($this->worker, $this->cl, '10', 'Put back', $this->hrUser);
    $this->leave->approve($r, $this->hrUser);

    // it has started (today is the 12th): the person may not cancel it themself; the manager and HR may
    expect(fn () => ($this->as)($this->workerUser) && $this->leave->cancel($r, $this->workerUser, 'Changed my mind'))->toThrow(ValidationException::class, 'has started')
        ->and(fn () => ($this->as)($this->otherUser) && $this->leave->cancel($r, $this->otherUser, 'Nosy'))->toThrow(AuthorizationException::class);

    ($this->as)($this->bossUser);
    $this->leave->cancel($r, $this->bossUser, 'Needed at work');
    expect($r->fresh()->state)->toBe('cancelled')->and($this->leave->balance($this->worker, $this->cl, 2026))->toBe('12.0');
});

it('lets HR adjust a balance with a reason, in halves, and nobody else', function () {
    ($this->as)($this->hrUser);
    $e = $this->leave->adjust($this->worker, $this->cl, '2.5', 'Joining bonus', $this->hrUser);
    expect($e->entry_type)->toBe('adjustment')->and($e->days)->toBe('2.5')->and($this->leave->balance($this->worker, $this->cl, 2026))->toBe('2.5');

    expect(fn () => $this->leave->adjust($this->worker, $this->cl, '0', 'x', $this->hrUser))->toThrow(ValidationException::class)
        ->and(fn () => $this->leave->adjust($this->worker, $this->cl, '1.3', 'x', $this->hrUser))->toThrow(ValidationException::class)
        ->and(fn () => $this->leave->adjust($this->worker, $this->cl, '1', '', $this->hrUser))->toThrow(ValidationException::class, 'Say why')
        ->and(fn () => $this->leave->adjust($this->worker, $this->cl, '1', 'x', $this->bossUser))->toThrow(AuthorizationException::class);
});

// ---- punching and the day-close -----------------------------------------------------------------------------------------------------

it('lets a person punch in and out for themself, alternating, at the server’s time', function () {
    ($this->as)($this->workerUser);
    $at = fn (string $t) => CarbonImmutable::parse('2026-10-12 '.$t);

    expect(fn () => $this->attendance->punch($this->worker, 'out', $this->workerUser, null, $at('09:00')))->toThrow(ValidationException::class, 'Punch in first');
    $this->attendance->punch($this->worker, 'in', $this->workerUser, '10.0.0.1', $at('09:20'));
    expect(fn () => $this->attendance->punch($this->worker, 'in', $this->workerUser, null, $at('09:25')))->toThrow(ValidationException::class, 'already punched in');

    expect($this->attendance->today($this->worker, $at('11:20')))->toMatchArray(['state' => 'in', 'worked_minutes' => 120, 'punches' => 1]);
    $this->attendance->punch($this->worker, 'out', $this->workerUser, null, $at('13:00'));
    expect(fn () => $this->attendance->punch($this->worker, 'out', $this->workerUser, null, $at('13:05')))->toThrow(ValidationException::class, 'already punched out')
        ->and($this->attendance->today($this->worker, $at('15:00')))->toMatchArray(['state' => 'out', 'worked_minutes' => 220]);   // 09:20 to 13:00

    // nobody punches for another, and a punch is never changed
    expect(fn () => $this->attendance->punch($this->other, 'in', $this->workerUser, null, $at('10:00')))->toThrow(AuthorizationException::class)
        ->and(fn () => AttendancePunch::query()->firstOrFail()->delete())->toThrow(LogicException::class);
});

it('closes a day by the shift: present, half day, absent, late, no punch out', function () {
    ($this->as)($this->hrUser);
    $punch = function (Employee $e, string $day, string $in, ?string $out) {
        foreach ([['in', $in], ['out', $out]] as [$dir, $t]) {
            if ($t !== null) {
                $p = new AttendancePunch;
                $p->forceFill(['company_id' => $e->company_id, 'employee_id' => $e->id, 'punched_at' => CarbonImmutable::parse($day.' '.$t), 'direction' => $dir, 'source' => 'web', 'created_at' => now()])->save();
            }
        }
    };
    $close = fn (Employee $e, string $day) => $this->attendance->closeDay($e, CarbonImmutable::parse($day))->fresh();

    // default shift (none set): 09:30–18:30, grace 15, full 480 min, half 240 min
    $punch($this->worker, '2026-10-05', '09:30', '18:30');      // 540 min
    $punch($this->worker, '2026-10-06', '09:50', '18:30');      // 520 min, 20 late
    $punch($this->worker, '2026-10-07', '09:30', '14:00');      // 270 min: half day
    $punch($this->worker, '2026-10-08', '09:30', '12:00');      // 150 min: absent
    $punch($this->worker, '2026-10-09', '09:30', null);         // never out

    expect($close($this->worker, '2026-10-05'))->toMatchArray(['status' => 'present', 'worked_minutes' => 540, 'late_minutes' => 0])
        ->and($close($this->worker, '2026-10-06'))->toMatchArray(['status' => 'present', 'worked_minutes' => 520, 'late_minutes' => 20])
        ->and($close($this->worker, '2026-10-07'))->toMatchArray(['status' => 'half_day', 'worked_minutes' => 270])
        ->and($close($this->worker, '2026-10-08'))->toMatchArray(['status' => 'absent', 'worked_minutes' => 150])
        ->and($close($this->worker, '2026-10-09'))->toMatchArray(['status' => 'absent', 'missing_out' => true, 'worked_minutes' => 0])
        ->and($close($this->worker, '2026-10-10'))->toMatchArray(['status' => 'absent'])                   // Saturday, a working day under "Sunday only", nobody came
        ->and($close($this->worker, '2026-10-11'))->toMatchArray(['status' => 'weekly_off'])               // Sunday
        ->and($close($this->worker, '2026-10-15'))->toMatchArray(['status' => 'holiday']);

    // closing again changes nothing and adds no row
    $rows = AttendanceDay::query()->count();
    $close($this->worker, '2026-10-05');
    expect(AttendanceDay::query()->count())->toBe($rows);

    // a shift of the company's own: starts 10:00, grace 5, a full day is 7 hours
    inCompany($this->acme, fn () => Shift::query()->create(['code' => 'LATE', 'name' => 'Late shift', 'start_time' => '10:00', 'end_time' => '19:00', 'grace_minutes' => 5, 'full_day_minutes' => 420, 'half_day_minutes' => 200, 'is_default' => true]));
    $punch($this->worker, '2026-10-02', '10:10', '17:20');      // 430 min: present; 10 late (after the 5-minute grace, counted from 10:00)
    expect($close($this->worker, '2026-10-02'))->toMatchArray(['status' => 'present', 'late_minutes' => 10, 'worked_minutes' => 430]);
});

it('turns approved leave into leave or a half day on the day-close, and re-closes days already closed', function () {
    ($this->grant)();
    ($this->as)($this->hrUser);
    $this->attendance->closeDay($this->worker, CarbonImmutable::parse('2026-10-08'));              // closed as absent: nobody came, no leave yet
    expect(AttendanceDay::query()->where('employee_id', $this->worker->id)->first()->status)->toBe('absent');

    $r = ($this->apply)($this->worker, $this->workerUser, $this->cl, '2026-10-08', '2026-10-08');  // asked for afterwards
    ($this->as)($this->bossUser);
    $this->leave->approve($r, $this->bossUser);                                                    // approving closes the day again

    $day = AttendanceDay::query()->where('employee_id', $this->worker->id)->whereDate('work_date', '2026-10-08')->firstOrFail();
    expect($day->status)->toBe('leave')->and($day->leave_days)->toBe('1.0');

    $half = ($this->apply)($this->worker, $this->workerUser, $this->cl, '2026-10-09', '2026-10-09', 'second');
    ($this->as)($this->bossUser);
    $this->leave->approve($half, $this->bossUser);
    $d9 = $this->attendance->closeDay($this->worker, CarbonImmutable::parse('2026-10-09'));
    expect($d9->status)->toBe('half_day')->and($d9->leave_days)->toBe('0.5');

    // cancelling it puts the day back as it was
    $this->leave->cancel($r, $this->bossUser, 'Mistake');
    expect(AttendanceDay::query()->where('employee_id', $this->worker->id)->whereDate('work_date', '2026-10-08')->firstOrFail()->status)->toBe('absent');
});

it('closes yesterday for everyone each night, catches up a missed week, skips those not yet here or gone, and repeats harmlessly', function () {
    ($this->as)($this->hrUser);
    $future = ($this->hire)('F1', 'Not yet', null, ['joining_date' => '2026-10-12']);   // joins today: nothing to close before today
    DB::table('employees')->where('id', $this->other->id)->update(['employment_status' => 'exited', 'exit_date' => '2026-10-08']);

    // joined 5 Jan: for a run on the 12th the catch-up reaches back to the 5th: 5,6,7,8,9,10,11 = 7 days for each of the two still here
    $written = $this->attendance->sweep(now());
    $forWorker = AttendanceDay::query()->where('employee_id', $this->worker->id)->count();
    $forOther = AttendanceDay::query()->where('employee_id', $this->other->id)->orderBy('work_date')->get();

    expect($forWorker)->toBe(7)->and(AttendanceDay::query()->where('employee_id', $future->id)->count())->toBe(0)
        ->and($forOther->pluck('work_date')->map(fn ($d) => $d->toDateString())->last())->toBe('2026-10-08')    // nothing after the last working day
        ->and($forOther)->toHaveCount(4)                                                                         // 5, 6, 7, 8
        ->and($written)->toBe(7 + 7 + 4);                                                                        // boss, worker, other

    expect($this->attendance->sweep(now()))->toBe(0);
    $this->artisan('hr:attendance-close')->assertSuccessful();
    expect(AttendanceDay::query()->count())->toBe(18);
});

// ---- corrections ----------------------------------------------------------------------------------------------------------------------

it('lets a person ask to put a day right, their manager approve it, and the punches be added once', function () {
    ($this->as)($this->workerUser);
    $at = CarbonImmutable::parse('2026-10-09 09:30');
    $p = new AttendancePunch;
    $p->forceFill(['company_id' => $this->worker->company_id, 'employee_id' => $this->worker->id, 'punched_at' => $at, 'direction' => 'in', 'source' => 'web', 'created_at' => now()])->save();
    ($this->as)($this->hrUser);
    $this->attendance->closeDay($this->worker, CarbonImmutable::parse('2026-10-09'));
    expect(AttendanceDay::query()->where('employee_id', $this->worker->id)->whereDate('work_date', '2026-10-09')->first())->toMatchArray(['status' => 'absent', 'missing_out' => true]);

    ($this->as)($this->workerUser);
    $req = $this->attendance->requestRegularisation($this->worker, '2026-10-09', '09:30', '18:30', 'Forgot to punch out', $this->workerUser);
    expect($req->state)->toBe('pending')
        ->and(fn () => $this->attendance->requestRegularisation($this->worker, '2026-10-09', '09:00', '18:00', 'Again', $this->workerUser))->toThrow(ValidationException::class, 'already waiting');

    expect(fn () => $this->attendance->approveRegularisation($req, $this->workerUser))->toThrow(AuthorizationException::class, 'your own')
        ->and(fn () => ($this->as)($this->otherUser) && $this->attendance->approveRegularisation($req, $this->otherUser))->toThrow(AuthorizationException::class);

    ($this->as)($this->bossUser);
    $this->attendance->approveRegularisation($req, $this->bossUser, 'Fine');
    $day = AttendanceDay::query()->where('employee_id', $this->worker->id)->whereDate('work_date', '2026-10-09')->firstOrFail();
    expect($req->fresh()->state)->toBe('approved')->and($day->status)->toBe('present')->and($day->worked_minutes)->toBe(540)->and($day->missing_out)->toBeFalse()
        ->and(AttendancePunch::query()->where('regularisation_id', $req->id)->pluck('source')->unique()->all())->toBe(['regularised'])
        ->and(AttendancePunch::query()->where('regularisation_id', $req->id)->count())->toBe(2)
        ->and(fn () => $this->attendance->approveRegularisation($req, $this->bossUser))->toThrow(ValidationException::class, 'already been decided')
        ->and(fn () => $req->fresh()->forceFill(['reason' => 'edited'])->save())->toThrow(LogicException::class);
});

it('refuses corrections that make no sense, and lets the person take one back or the manager turn it down', function () {
    ($this->as)($this->workerUser);
    $ask = fn (string $date, string $in = '09:30', string $out = '18:30', string $reason = 'Forgot') => fn () => $this->attendance->requestRegularisation($this->worker, $date, $in, $out, $reason, $this->workerUser);

    expect($ask('2026-09-01'))->toThrow(ValidationException::class, 'last 30 days')
        ->and($ask('2026-10-13'))->toThrow(ValidationException::class, 'last 30 days')            // tomorrow
        ->and($ask('2026-10-09', '18:30', '09:30'))->toThrow(ValidationException::class, 'after the time in')
        ->and($ask('2026-10-09', '9:30', '18:30'))->toThrow(ValidationException::class, 'hours and minutes')
        ->and($ask('2026-10-09', '09:30', '18:30', ' '))->toThrow(ValidationException::class, 'what happened')
        ->and(fn () => $this->attendance->requestRegularisation($this->other, '2026-10-09', '09:30', '18:30', 'x', $this->workerUser))->toThrow(AuthorizationException::class);

    $a = ($ask('2026-10-08'))();
    $this->attendance->cancelRegularisation($a, $this->workerUser);
    expect($a->fresh()->state)->toBe('cancelled');

    $b = ($ask('2026-10-07'))();
    ($this->as)($this->bossUser);
    expect(fn () => $this->attendance->rejectRegularisation($b, $this->bossUser, ''))->toThrow(ValidationException::class);
    $this->attendance->rejectRegularisation($b, $this->bossUser, 'Camera shows you were out');
    expect($b->fresh()->state)->toBe('rejected')->and(AttendancePunch::query()->count())->toBe(0);
});

// ---- screens ----------------------------------------------------------------------------------------------------------------------------

it('lets a person see their balances, count the days live, and ask for leave from the screen', function () {
    ($this->grant)();
    ($this->as)($this->workerUser);

    Livewire::test(MyLeave::class)->assertSee('Casual leave')->assertSee('12')->call('openForm')
        ->set('form.leave_type_id', (string) $this->cl->id)->set('form.from_date', '2026-10-12')->set('form.to_date', '2026-10-19')
        ->assertSeeHtml('data-testid="leave-preview"')->assertSee('6')->assertSee('holiday / off day not counted')
        ->set('form.reason', 'Wedding')->call('submitRequest')->assertHasNoErrors();

    expect(LeaveRequest::query()->where('employee_id', $this->worker->id)->count())->toBe(1);

    $request = LeaveRequest::query()->firstOrFail();
    Livewire::test(MyLeave::class)->assertSee($request->number())->call('startCancel', $request->id)->set('cancelReason', 'No longer needed')->call('confirmCancel')->assertHasNoErrors();
    expect($request->fresh()->state)->toBe('cancelled');

    // an error from the service shows in the form
    Livewire::test(MyLeave::class)->call('openForm')->set('form.leave_type_id', (string) $this->cl->id)->set('form.from_date', '2026-10-20')->set('form.to_date', '2026-10-20')->call('submitRequest')->assertHasErrors(['reason']);
});

it('shows a manager their team’s requests and lets them decide from the screen, not their own', function () {
    ($this->grant)();
    $r = ($this->apply)($this->worker, $this->workerUser, $this->cl, '2026-10-20', '2026-10-21');
    $mine = ($this->apply)($this->boss, $this->bossUser, $this->cl, '2026-10-22', '2026-10-22');

    ($this->as)($this->bossUser);
    Livewire::test(LeaveRequestList::class)->assertSee($r->number())->assertDontSee($mine->number())->call('approveRequest', $r->id)->assertHasNoErrors();
    expect($r->fresh()->state)->toBe('approved');

    // a request outside their reach is not found
    $far = ($this->apply)($this->other, $this->otherUser, $this->cl, '2026-10-23', '2026-10-23');
    ($this->as)($this->bossUser);
    Livewire::test(LeaveRequestList::class)->assertDontSee($far->number())->call('approveRequest', $far->id)->assertNotFound();

    // someone with no team and no right cannot open the screen
    ($this->as)($this->otherUser);
    $this->get('/hr/leave/requests')->assertForbidden();
    ($this->as)($this->bossUser);
    $this->get('/hr/leave/requests')->assertOk();

    ($this->as)($this->hrUser);
    Livewire::test(LeaveRequestList::class)->assertSee($far->number())->assertSee($mine->number())->call('approveRequest', $mine->id)->assertHasNoErrors();
    expect($mine->fresh()->state)->toBe('approved');
});

it('lets HR change a balance from the screen and shows everyone the table', function () {
    ($this->grant)();
    ($this->as)($this->hrUser);

    Livewire::test(LeaveBalances::class)->assertSee('Worker')->assertSee('Casual leave')->call('openAdjust')
        ->set('form.employee_id', (string) $this->worker->id)->set('form.leave_type_id', (string) $this->cl->id)->set('form.days', '2')->set('form.reason', 'Bonus day')->call('saveAdjustment')->assertHasNoErrors();
    expect($this->leave->balance($this->worker, $this->cl, 2026))->toBe('14.0');

    ($this->as)($this->bossUser);
    Livewire::test(LeaveBalances::class)->assertSee('Worker')->assertDontSee('Other')->call('openAdjust')->assertForbidden();
});

it('punches from the screen, shows the month, and sends and decides a correction', function () {
    ($this->as)($this->workerUser);
    Livewire::test(MyAttendance::class)->assertSee('Not punched in yet')->call('punchIn')->assertHasNoErrors()->assertSee('Working since')->call('punchIn')->assertHasErrors(['direction'])
        ->call('punchOut')->assertHasNoErrors()->assertSee('Punched out');
    expect(AttendancePunch::query()->where('employee_id', $this->worker->id)->count())->toBe(2);

    $component = Livewire::test(MyAttendance::class)->call('openCorrection', '2026-10-09')->set('form.reason', 'Forgot')->call('submitCorrection')->assertHasNoErrors();
    $row = AttendanceRegularisation::query()->firstOrFail();

    ($this->as)($this->bossUser);
    Livewire::test(AttendanceCorrections::class)->assertSee('Forgot')->call('approveCorrection', $row->id)->assertHasNoErrors();
    expect($row->fresh()->state)->toBe('approved');

    ($this->as)($this->otherUser);
    $this->get('/hr/attendance/corrections')->assertForbidden();
    $this->get('/hr/attendance/team')->assertForbidden();
    $this->get('/hr/attendance')->assertOk();
});

it('shows the team’s month with the right counts', function () {
    ($this->as)($this->hrUser);
    $add = fn (Employee $e, string $day, string $in, string $out) => collect([['in', $in], ['out', $out]])->each(function ($x) use ($e, $day) {
        $p = new AttendancePunch;
        $p->forceFill(['company_id' => $e->company_id, 'employee_id' => $e->id, 'punched_at' => CarbonImmutable::parse($day.' '.$x[1]), 'direction' => $x[0], 'source' => 'web', 'created_at' => now()])->save();
    });
    $add($this->worker, '2026-10-05', '09:30', '18:30');   // present
    $add($this->worker, '2026-10-06', '09:55', '18:30');   // present, late
    $add($this->worker, '2026-10-07', '09:30', '14:00');   // half day
    foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'] as $day) {                // the 8th: nobody came => absent
        $this->attendance->closeDay($this->worker, CarbonImmutable::parse($day));
    }

    ($this->as)($this->bossUser);
    $html = Livewire::test(TeamAttendance::class)->set('month', '2026-10')->assertSee('Worker')->assertDontSee('Other')->html();
    expect($html)->toContain('title="Present"')->and($html)->toContain('title="Half day"')->and($html)->toContain('title="Absent"');
    $this->get('/hr/attendance/team?month=2026-10')->assertOk();
});

it('keeps the holiday calendar to those who may change it, and one default shift', function () {
    ($this->as)($this->hrUser);
    Livewire::test(HolidayList::class)->assertSee('Festival')->set('h.holiday_date', '2026-11-08')->set('h.name', 'New year')->call('addHoliday')->assertHasNoErrors();
    Livewire::test(HolidayList::class)->set('h.holiday_date', '2026-11-08')->set('h.name', 'Again')->call('addHoliday')->assertHasErrors(['holiday_date']);
    $id = Holiday::query()->whereDate('holiday_date', '2026-11-08')->value('id');
    Livewire::test(HolidayList::class)->call('removeHoliday', $id);
    expect(Holiday::query()->whereDate('holiday_date', '2026-11-08')->exists())->toBeFalse();

    ($this->as)($this->workerUser);
    $this->get('/hr/holidays')->assertForbidden();

    ($this->as)($this->hrUser);
    foreach (['DAY' => true, 'NIGHT' => true] as $code => $default) {
        Livewire::test(ShiftList::class)->call('create')->set('values.code', $code)->set('values.name', $code.' shift')->set('values.start_time', '09:00')->set('values.end_time', '18:00')
            ->set('values.is_default', $default)->call('save')->assertHasNoErrors();
    }
    expect(Shift::query()->where('is_default', true)->pluck('code')->all())->toBe(['NIGHT']);   // the second default replaced the first
});

it('has the leave and attendance permissions on the right roles, and the pages open for those who may', function () {
    expect($this->hrUser->can('leave.approve_all'))->toBeTrue()->and($this->hrUser->can('leave.manage_balances'))->toBeTrue()->and($this->hrUser->can('staff_attendance.approve_all'))->toBeTrue()
        ->and($this->bossUser->can('leave.approve'))->toBeTrue()->and($this->bossUser->can('leave.approve_all'))->toBeFalse()->and($this->bossUser->can('leave.manage_balances'))->toBeFalse()
        ->and($this->workerUser->can('leave.view'))->toBeFalse();

    foreach (['/hr/leave', '/hr/attendance', '/hr/leave/requests', '/hr/leave/balances', '/hr/leave/types', '/hr/attendance/team', '/hr/attendance/corrections', '/hr/shifts', '/hr/holidays'] as $url) {
        ($this->as)($this->hrUser);
        $this->get($url)->assertOk();
    }

    foreach (['/hr/leave', '/hr/attendance'] as $url) {
        ($this->as)($this->workerUser);
        $this->get($url)->assertOk();
    }

    foreach (['/hr/leave/types', '/hr/shifts', '/hr/holidays', '/hr/leave/balances'] as $url) {
        ($this->as)($this->workerUser);
        $this->get($url)->assertForbidden();
    }

    ($this->as)($this->outsider);
    expect(LeaveType::query()->count())->toBe(0);
    ($this->as)($this->bossUser);
    $this->get('/dashboard')->assertOk()->assertSee('My leave')->assertSee('Leave requests')->assertDontSee('Leave types');
});

it('tells the manager when leave or a correction is asked for, and the person when it is decided, never the one who acted', function () {
    Notification::fake();
    ($this->grant)();

    $r = ($this->apply)($this->worker, $this->workerUser, $this->cl, '2026-10-20', '2026-10-21');
    Notification::assertSentTo($this->bossUser, HrNotice::class, fn (HrNotice $n) => $n->kind === 'leave_requested' && str_contains($n->message, 'Worker asks for 2 day(s) of Casual leave'));
    Notification::assertNotSentTo($this->workerUser, HrNotice::class);

    ($this->as)($this->bossUser);
    $this->leave->approve($r, $this->bossUser, 'Fine');
    Notification::assertSentTo($this->workerUser, HrNotice::class, fn (HrNotice $n) => $n->kind === 'leave_decided' && str_contains($n->message, 'approved by'));
    Notification::assertNotSentTo($this->bossUser, HrNotice::class, fn (HrNotice $n) => $n->kind === 'leave_decided');

    // the person cancels their own approved leave: their manager is told
    ($this->as)($this->workerUser);
    $this->leave->cancel($r, $this->workerUser, 'Plans changed');
    Notification::assertSentTo($this->bossUser, HrNotice::class, fn (HrNotice $n) => $n->kind === 'leave_cancelled');

    // someone with no manager asks: nobody is told (HR finds it in the list)
    $before = Notification::sent($this->bossUser, HrNotice::class)->count();
    ($this->apply)($this->other, $this->otherUser, $this->cl, '2026-10-22', '2026-10-22');
    expect(Notification::sent($this->bossUser, HrNotice::class)->count())->toBe($before);

    // a correction
    ($this->as)($this->workerUser);
    $c = $this->attendance->requestRegularisation($this->worker, '2026-10-09', '09:30', '18:30', 'Forgot', $this->workerUser);
    Notification::assertSentTo($this->bossUser, HrNotice::class, fn (HrNotice $n) => $n->kind === 'attendance_correction');
    ($this->as)($this->bossUser);
    $this->attendance->rejectRegularisation($c, $this->bossUser, 'No evidence');
    Notification::assertSentTo($this->workerUser, HrNotice::class, fn (HrNotice $n) => $n->kind === 'attendance_decided' && str_contains($n->message, 'turned down'));
});

it('does not lose a decision when the notice cannot be sent', function () {
    ($this->grant)();
    $r = ($this->apply)($this->worker, $this->workerUser, $this->cl, '2026-10-20', '2026-10-20');

    // a real SMTP transport pointed at a closed port: the mail fails, the approval stands
    config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);
    Mail::purge('smtp');
    ($this->as)($this->bossUser);
    $this->leave->approve($r, $this->bossUser);

    expect($r->fresh()->state)->toBe('approved')->and($this->leave->balance($this->worker, $this->cl, 2026))->toBe('11.0');
});

<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\AttendancePunch;
use App\Modules\HR\Models\AttendanceRegularisation;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\Shift;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Attendance for all staff: punching in and out, the nightly day-close, and corrections. (Field sales keeps the geofenced check-in of Phase 6; this is separate.)
 *
 *   - A punch is the person's own, at the server's time, alternating in and out through the day; punches are append-only evidence.
 *   - The day-close (`closeDay`, run nightly by `hr:attendance-close`, idempotent) turns a day's punches, the approved leave, the holidays and the weekly off into one line:
 *     approved full-day leave = leave; approved half-day leave = half day (leave_days 0.5); a holiday or the weekly off = holiday / weekly off; otherwise by the time between the
 *     first punch in and the last punch out against the shift: full = present, half = half day, less = absent. Punched in and never out = absent, flagged "missing out".
 *     Late = first in after the shift start plus the grace, counted from the shift start.
 *   - A correction (regularisation) is asked for by the person, decided by their manager (or HR), and adds two punches marked "regularised"; the day is then closed again.
 */
final class AttendanceService
{
    public const MAX_PUNCHES_PER_DAY = 20;

    public const REGULARISE_DAYS = 30;

    public const CATCH_UP_DAYS = 7;

    /** Used when the company has defined no shift at all. */
    public const FALLBACK_SHIFT = ['id' => null, 'start' => '09:30', 'end' => '18:30', 'grace' => 15, 'full' => 480, 'half' => 240];

    public function __construct(
        private readonly WorkCalendar $calendar,
        private readonly StaffReach $reach,
        private readonly EmployeeAccess $access,
        private readonly TenantContext $tenant,
        private readonly HrNotifier $notifier,
    ) {}

    /** @return array{id: int|null, start: string, end: string, grace: int, full: int, half: int} */
    public function shiftFor(Employee $employee): array
    {
        $shift = ($employee->shift_id !== null ? Shift::query()->where('status', 'active')->find($employee->shift_id) : null) ?? Shift::query()->where('status', 'active')->where('is_default', true)->first();

        return $shift === null ? self::FALLBACK_SHIFT : [
            'id' => $shift->id, 'start' => substr($shift->start_time, 0, 5), 'end' => substr($shift->end_time, 0, 5), 'grace' => $shift->grace_minutes, 'full' => $shift->full_day_minutes, 'half' => $shift->half_day_minutes,
        ];
    }

    // ---- punching ----------------------------------------------------------------------------------------------------------------

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function punch(Employee $employee, string $direction, User $actor, ?string $ip = null, ?CarbonInterface $at = null): AttendancePunch
    {
        if ($employee->user_id === null || $employee->user_id !== $actor->id) {
            throw new AuthorizationException('You may punch only for yourself.');
        }

        if (! in_array($direction, ['in', 'out'], true)) {
            throw ValidationException::withMessages(['direction' => 'Punch in or out.']);
        }

        if ($employee->employment_status === 'exited') {
            throw ValidationException::withMessages(['direction' => 'You have left the company.']);
        }

        $at = CarbonImmutable::instance($at ?? now());

        return DB::transaction(function () use ($employee, $direction, $ip, $at) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();   // two taps at once cannot both pass the check below
            $today = AttendancePunch::query()->where('employee_id', $employee->id)->whereBetween('punched_at', [$at->startOfDay(), $at->endOfDay()])->orderBy('punched_at')->orderBy('id')->get();
            $last = $today->last();

            if ($today->count() >= self::MAX_PUNCHES_PER_DAY) {
                throw ValidationException::withMessages(['direction' => 'That is enough punches for one day.']);
            }

            if ($last === null && $direction === 'out') {
                throw ValidationException::withMessages(['direction' => 'Punch in first.']);
            }

            if ($last !== null && $last->direction === $direction) {
                throw ValidationException::withMessages(['direction' => $direction === 'in' ? 'You are already punched in.' : 'You are already punched out.']);
            }

            return $this->insertPunch($employee, $at, $direction, 'web', null, $ip);
        });
    }

    /**
     * Where the person stands today, from the punches (not from the closed day, which is written at night).
     *
     * @return array{state: string, first_in: CarbonInterface|null, last_punch: CarbonInterface|null, worked_minutes: int, punches: int}
     */
    public function today(Employee $employee, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $punches = AttendancePunch::query()->where('employee_id', $employee->id)->whereBetween('punched_at', [$now->startOfDay(), $now->endOfDay()])->orderBy('punched_at')->orderBy('id')->get();
        $firstIn = $punches->firstWhere('direction', 'in');
        $last = $punches->last();
        $state = $last === null ? 'none' : ($last->direction === 'in' ? 'in' : 'out');
        $lastOut = $punches->where('direction', 'out')->last();
        $end = $state === 'in' || $lastOut === null ? $now : $lastOut->punched_at;
        $worked = $firstIn === null ? 0 : max(0, (int) $firstIn->punched_at->diffInMinutes($end));

        return ['state' => $state, 'first_in' => $firstIn?->punched_at, 'last_punch' => $last?->punched_at, 'worked_minutes' => $worked, 'punches' => $punches->count()];
    }

    // ---- the day-close -----------------------------------------------------------------------------------------------------------

    /**
     * What a day comes to for a person. Pure: it reads and returns, it writes nothing.
     *
     * @return array{status: string, first_in: CarbonInterface|null, last_out: CarbonInterface|null, worked_minutes: int, late_minutes: int, leave_days: string, missing_out: bool, shift_id: int|null}
     */
    public function computeDay(Employee $employee, CarbonImmutable $date): array
    {
        $day = $date->startOfDay();
        $shift = $this->shiftFor($employee);
        $punches = AttendancePunch::query()->where('employee_id', $employee->id)->whereBetween('punched_at', [$day, $day->endOfDay()])->orderBy('punched_at')->orderBy('id')->get();
        $firstIn = $punches->firstWhere('direction', 'in')?->punched_at;
        $lastOut = $firstIn === null ? null : $punches->filter(fn (AttendancePunch $p) => $p->direction === 'out' && $p->punched_at->gt($firstIn))->last()?->punched_at;
        $worked = $firstIn !== null && $lastOut !== null ? (int) $firstIn->diffInMinutes($lastOut) : 0;
        $leave = LeaveRequest::query()->where('employee_id', $employee->id)->where('state', 'approved')->whereDate('from_date', '<=', $day->toDateString())->whereDate('to_date', '>=', $day->toDateString())->first();
        $holiday = $this->calendar->holidays($day, $day) !== [];

        $status = match (true) {
            $leave !== null && $leave->half_day === null => 'leave',
            $leave !== null => 'half_day',
            $holiday => 'holiday',
            $this->calendar->isWeeklyOff($day) => 'weekly_off',
            $worked >= $shift['full'] => 'present',
            $worked >= $shift['half'] => 'half_day',
            default => 'absent',
        };

        $starts = CarbonImmutable::parse($day->toDateString().' '.$shift['start']);
        $late = in_array($status, ['present', 'half_day'], true) && $firstIn !== null && $firstIn->gt($starts->addMinutes($shift['grace'])) ? (int) $starts->diffInMinutes($firstIn) : 0;

        return [
            'status' => $status, 'first_in' => $firstIn, 'last_out' => $lastOut, 'worked_minutes' => $worked, 'late_minutes' => $late,
            'leave_days' => $leave === null ? '0.0' : ($leave->half_day === null ? '1.0' : '0.5'), 'missing_out' => $firstIn !== null && $lastOut === null, 'shift_id' => $shift['id'],
        ];
    }

    /** Close one day for one person: write (or rewrite) the line. Idempotent. */
    public function closeDay(Employee $employee, CarbonImmutable $date): AttendanceDay
    {
        $c = $this->computeDay($employee, $date);

        $row = AttendanceDay::query()->where('employee_id', $employee->id)->whereDate('work_date', $date->toDateString())->first() ?? new AttendanceDay;
        $row->forceFill([
            'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'work_date' => $date->toDateString(), 'status' => $c['status'], 'first_in' => $c['first_in'], 'last_out' => $c['last_out'],
            'worked_minutes' => $c['worked_minutes'], 'late_minutes' => $c['late_minutes'], 'leave_days' => $c['leave_days'], 'missing_out' => $c['missing_out'], 'shift_id' => $c['shift_id'], 'closed_at' => now(),
        ])->save();

        return $row;
    }

    /**
     * The nightly close for every company: yesterday, and any day of the last week that was missed (the scheduler was down), for everyone who was employed that day.
     *
     * @return int days written
     */
    public function sweep(?CarbonInterface $today = null): int
    {
        $today = CarbonImmutable::instance($today ?? now())->startOfDay();
        $written = 0;

        foreach (Company::withoutGlobalScopes(Tenancy::SCOPES)->pluck('id') as $companyId) {
            $written += $this->tenant->withCompany((int) $companyId, fn () => $this->sweepCompany($today));
        }

        return $written;
    }

    public function sweepCompany(CarbonImmutable $today): int
    {
        $written = 0;
        $earliest = $today->subDays(self::CATCH_UP_DAYS);

        foreach (Employee::query()->get() as $employee) {
            $from = CarbonImmutable::instance($employee->joining_date)->max($earliest);
            $to = $employee->exit_date !== null && $employee->employment_status === 'exited' ? CarbonImmutable::instance($employee->exit_date)->min($today->subDay()) : $today->subDay();

            if ($from->gt($to)) {
                continue;
            }

            $have = AttendanceDay::query()->where('employee_id', $employee->id)->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->pluck('work_date')->map(fn ($d) => $d->toDateString())->flip();

            for ($d = $from; $d->lte($to); $d = $d->addDay()) {
                if (! $have->has($d->toDateString())) {
                    $this->closeDay($employee, $d);
                    $written++;
                }
            }
        }

        return $written;
    }

    /** Leave or a correction changed what a past day means: write those days again (today and later are closed when their night comes). */
    public function recloseRange(Employee $employee, CarbonInterface $from, CarbonInterface $to): void
    {
        $today = CarbonImmutable::today();
        $joined = CarbonImmutable::instance($employee->joining_date);
        $limit = CarbonImmutable::instance($to)->min($today->subDay());

        for ($d = CarbonImmutable::instance($from)->startOfDay()->max($joined); $d->lte($limit); $d = $d->addDay()) {
            if ($employee->exit_date !== null && $employee->employment_status === 'exited' && $d->gt($employee->exit_date)) {
                break;
            }

            $this->closeDay($employee, $d);
        }
    }

    // ---- corrections -------------------------------------------------------------------------------------------------------------

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function requestRegularisation(Employee $employee, string $date, string $in, string $out, string $reason, User $actor): AttendanceRegularisation
    {
        if ($employee->user_id === null || $employee->user_id !== $actor->id) {
            throw new AuthorizationException('You may ask for a correction only for yourself.');
        }

        $errors = [];
        $day = $this->date($date);
        $today = CarbonImmutable::today();
        $reason = trim($reason);

        if ($day === null || $day->gt($today) || $day->lt($today->subDays(self::REGULARISE_DAYS)) || $day->lt($employee->joining_date)) {
            $errors['work_date'] = 'Choose a day in the last '.self::REGULARISE_DAYS.' days, after you joined.';
        }

        foreach (['in_time' => $in, 'out_time' => $out] as $field => $value) {
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
                $errors[$field] = 'Enter the time as hours and minutes, like 09:30.';
            }
        }

        if (! isset($errors['in_time']) && ! isset($errors['out_time']) && $in >= $out) {
            $errors['out_time'] = 'The time out must be after the time in.';
        }

        if ($reason === '' || mb_strlen($reason) > 500) {
            $errors['reason'] = 'Say what happened (up to 500 characters).';
        }

        if ($errors !== [] || $day === null) {
            throw ValidationException::withMessages($errors ?: ['work_date' => 'Choose the day.']);
        }

        $row = DB::transaction(function () use ($employee, $day, $in, $out, $reason) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            if (AttendanceRegularisation::query()->where('employee_id', $employee->id)->whereDate('work_date', $day->toDateString())->where('state', 'pending')->exists()) {
                throw ValidationException::withMessages(['work_date' => 'A correction for that day is already waiting.']);
            }

            $row = new AttendanceRegularisation;
            $row->forceFill([
                'company_id' => $employee->company_id, 'location_id' => $employee->location_id, 'employee_id' => $employee->id, 'work_date' => $day->toDateString(),
                'in_time' => $in.':00', 'out_time' => $out.':00', 'reason' => $reason, 'state' => 'pending',
            ])->save();

            return $row;
        });

        $this->notifier->tell($this->notifier->managerOf($employee), $actor, 'attendance_correction', 'Attendance correction from '.$employee->name,
            $employee->name.' asks to correct '.$day->format('d M').' ('.$in.' – '.$out.'): '.$reason.'.', route('hr.corrections'));

        return $row;
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function approveRegularisation(AttendanceRegularisation $request, User $by, ?string $note = null): AttendanceRegularisation
    {
        $done = DB::transaction(function () use ($request, $by, $note) {
            $locked = AttendanceRegularisation::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $employee = $this->mustBeDecidable($locked, $by);
            $day = $locked->work_date->toDateString();

            $this->insertPunch($employee, CarbonImmutable::parse($day.' '.$locked->in_time), 'in', 'regularised', $locked->id, null);
            $this->insertPunch($employee, CarbonImmutable::parse($day.' '.$locked->out_time), 'out', 'regularised', $locked->id, null);
            $locked->forceFill(['state' => 'approved', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $this->clean($note)])->save();

            return $locked;
        });

        $employee = Employee::query()->find($done->employee_id);

        if ($employee !== null) {
            $this->recloseRange($employee, $done->work_date, $done->work_date);
            $this->notifier->tell($this->notifier->loginOf($employee), $by, 'attendance_decided', 'Your attendance correction was approved', 'Your correction for '.$done->work_date->format('d M').' was approved by '.$by->name.'.', route('hr.my-attendance'));
        }

        return $done;
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function rejectRegularisation(AttendanceRegularisation $request, User $by, string $note): AttendanceRegularisation
    {
        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Say why it is being turned down.']);
        }

        $done = DB::transaction(function () use ($request, $by, $note) {
            $locked = AttendanceRegularisation::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->mustBeDecidable($locked, $by);
            $locked->forceFill(['state' => 'rejected', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $this->clean($note)])->save();

            return $locked;
        });

        $employee = Employee::query()->find($done->employee_id);

        if ($employee !== null) {
            $this->notifier->tell($this->notifier->loginOf($employee), $by, 'attendance_decided', 'Your attendance correction was turned down', 'Your correction for '.$done->work_date->format('d M').' was turned down by '.$by->name.': '.trim($note).'.', route('hr.my-attendance'));
        }

        return $done;
    }

    /** The person takes their own waiting request back. */
    public function cancelRegularisation(AttendanceRegularisation $request, User $by): AttendanceRegularisation
    {
        return DB::transaction(function () use ($request, $by) {
            $locked = AttendanceRegularisation::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $employee = Employee::query()->findOrFail($locked->employee_id);

            if ($employee->user_id !== $by->id) {
                throw new AuthorizationException('You may cancel only your own request.');
            }

            if ($locked->state !== 'pending') {
                throw ValidationException::withMessages(['state' => 'That request has already been decided.']);
            }

            $locked->forceFill(['state' => 'cancelled', 'decided_by' => $by->id, 'decided_at' => now()])->save();

            return $locked;
        });
    }

    // ---- internals -------------------------------------------------------------------------------------------------------------------

    private function mustBeDecidable(AttendanceRegularisation $row, User $by): Employee
    {
        if ($row->state !== 'pending') {
            throw ValidationException::withMessages(['state' => 'That request has already been decided.']);
        }

        $employee = Employee::query()->findOrFail($row->employee_id);

        if (! $this->reach->decides($by, $employee, 'staff_attendance.approve_all', 'staff_attendance.approve')) {
            throw new AuthorizationException($this->access->isOwn($by, $employee) ? 'You cannot decide your own correction.' : 'You may not decide this request.');
        }

        return $employee;
    }

    private function insertPunch(Employee $employee, CarbonInterface $at, string $direction, string $source, ?int $regularisationId, ?string $ip): AttendancePunch
    {
        $punch = new AttendancePunch;
        $punch->forceFill([
            'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'punched_at' => $at, 'direction' => $direction, 'source' => $source,
            'regularisation_id' => $regularisationId, 'ip_address' => $ip === null ? null : mb_substr($ip, 0, 45), 'created_at' => now(),
        ])->save();

        return $punch;
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? '' : trim($text);

        return $text === '' ? null : mb_substr($text, 0, 500);
    }

    private function date(string $value): ?CarbonImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? CarbonImmutable::instance($parsed) : null;
    }
}

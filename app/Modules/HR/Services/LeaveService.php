<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveLedgerEntry;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\Shared\Support\FinancialYear;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The one door for leave: applying, deciding, cancelling and adjusting balances. The balance is the **leave ledger** (`leave_ledger`, append-only): days granted by
 * `LeaveAccrual`, taken when a request is approved, given back when an approved one is cancelled, or adjusted by HR.
 *
 * Rules kept here:
 *   - a person applies for themself (HR may apply for anyone); never for someone who has left;
 *   - a request is for working days only (holidays and the weekly off are not counted) and must contain at least one; half a day only on a single day, for a type that allows it;
 *   - not more than 90 days at a time, not more than 30 days in the past or a year ahead, and not across a financial year (apply for each year separately);
 *   - it may not overlap another waiting or approved request of the same person (a first-half and a second-half request on one day may sit together);
 *   - the days must be there: balance, less what other waiting requests of the same type already ask for — unless the type allows a negative balance (unpaid leave);
 *   - deciding is the manager's (or someone above's), or HR's; never the person's own request; approval takes the days from the ledger exactly once and checks the balance again;
 *   - a person may cancel their own waiting request, and an approved one that has not started; the person's manager line and HR may cancel an approved one later. Cancelling gives the days back.
 */
final class LeaveService
{
    public const BACKDATE_DAYS = 30;

    public const AHEAD_DAYS = 365;

    public const MAX_SPAN_DAYS = 90;

    public function __construct(
        private readonly WorkCalendar $calendar,
        private readonly StaffReach $reach,
        private readonly AttendanceService $attendance,
        private readonly HrNotifier $notifier,
    ) {}

    /** The leave year a day belongs to: the year its financial year started in. */
    public function leaveYear(CarbonInterface $day): int
    {
        return FinancialYear::startYear($day);
    }

    /** @return numeric-string days left in the leave year, from the ledger */
    public function balance(Employee $employee, LeaveType $type, int $year): string
    {
        return bcadd((string) LeaveLedgerEntry::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('leave_year', $year)->sum('days'), '0', 1);
    }

    /** @return numeric-string days that waiting requests of the person already ask for in that leave year */
    public function pending(Employee $employee, LeaveType $type, int $year, ?int $exceptRequest = null): string
    {
        [$from, $to] = FinancialYear::range(CarbonImmutable::create($year, 4, 1));

        return bcadd((string) LeaveRequest::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('state', 'pending')
            ->whereBetween('from_date', [$from->toDateString(), $to->toDateString()])->when($exceptRequest !== null, fn ($q) => $q->whereKeyNot($exceptRequest))->sum('days'), '0', 1);
    }

    /**
     * What a person has, by type, for a leave year: granted, taken, adjusted, balance and what is waiting.
     *
     * @return list<array{type: LeaveType, granted: string, taken: string, adjusted: string, balance: string, pending: string}>
     */
    public function summary(Employee $employee, int $year): array
    {
        $entries = LeaveLedgerEntry::query()->where('employee_id', $employee->id)->where('leave_year', $year)->get()->groupBy('leave_type_id');
        $rows = [];

        foreach (LeaveType::query()->where('status', 'active')->orderBy('name')->get() as $type) {
            $mine = $entries->get($type->id, collect());
            $sum = fn (array $kinds) => bcadd((string) $mine->whereIn('entry_type', $kinds)->sum(fn (LeaveLedgerEntry $e) => (float) $e->days), '0', 1);

            $rows[] = [
                'type' => $type, 'granted' => $sum(['opening', 'accrual']), 'taken' => bcadd($sum(['taken', 'reversal']), '0', 1), 'adjusted' => $sum(['adjustment', 'lapse']),
                'balance' => $this->balance($employee, $type, $year), 'pending' => $this->pending($employee, $type, $year),
            ];
        }

        return $rows;
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function apply(Employee $employee, LeaveType $type, string $from, string $to, ?string $half, string $reason, User $actor): LeaveRequest
    {
        if ($employee->user_id !== $actor->id && ! $actor->can('leave.approve_all')) {
            throw new AuthorizationException('You may apply only for yourself.');
        }

        $errors = [];
        $start = $this->date($from);
        $end = $this->date($to);
        $reason = trim($reason);
        $half = $half === '' ? null : $half;
        $today = CarbonImmutable::today();

        if ($employee->isExited()) {
            $errors['employee'] = 'This person has left the company.';
        }

        if ($type->status !== 'active') {
            $errors['leave_type_id'] = 'That kind of leave is not in use.';
        }

        if ($start === null || $end === null || $end->lt($start)) {
            $errors['from_date'] = 'Give the first and the last day of the leave (the last not before the first).';
        } elseif ($start->lt($today->subDays(self::BACKDATE_DAYS)) || $end->gt($today->addDays(self::AHEAD_DAYS)) || $start->diffInDays($end) + 1 > self::MAX_SPAN_DAYS) {
            $errors['from_date'] = 'Leave may start at most '.self::BACKDATE_DAYS.' days back, end within a year, and last at most '.self::MAX_SPAN_DAYS.' days.';
        } elseif ($this->leaveYear($start) !== $this->leaveYear($end)) {
            $errors['to_date'] = 'That runs across two financial years: apply for each year separately.';
        }

        if ($reason === '' || mb_strlen($reason) > 500) {
            $errors['reason'] = 'Say why (up to 500 characters).';
        }

        if ($half !== null && ! in_array($half, ['first', 'second'], true)) {
            $errors['half_day'] = 'Choose the first or the second half.';
        } elseif ($half !== null && ! $type->allow_half_day) {
            $errors['half_day'] = 'This kind of leave cannot be taken by the half day.';
        } elseif ($half !== null && $start !== null && $end !== null && ! $start->equalTo($end)) {
            $errors['half_day'] = 'A half day is for a single day.';
        }

        $days = '0.0';

        if ($errors === [] && $start !== null && $end !== null) {
            $working = $this->calendar->workingDays($start, $end);
            $days = $half !== null ? ($working === [] ? '0.0' : '0.5') : number_format((float) count($working), 1, '.', '');

            if (bccomp($days, '0', 1) === 0) {
                $errors['from_date'] = 'There is no working day in that range: it is all holidays and days off.';
            } elseif ($type->max_days_per_request !== null && bccomp($days, (string) $type->max_days_per_request, 1) > 0) {
                $errors['to_date'] = 'This kind of leave is limited to '.$type->max_days_per_request.' days at a time.';
            }
        }

        if ($errors !== [] || $start === null || $end === null) {
            throw ValidationException::withMessages($errors ?: ['from_date' => 'Give the first and the last day of the leave.']);
        }

        $request = DB::transaction(function () use ($employee, $type, $start, $end, $half, $reason, $days) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();   // one application at a time for a person: the overlap and balance checks below cannot race

            foreach (LeaveRequest::query()->where('employee_id', $employee->id)->whereIn('state', ['pending', 'approved'])->whereDate('from_date', '<=', $end->toDateString())->whereDate('to_date', '>=', $start->toDateString())->get() as $other) {
                $bothHalves = $half !== null && $other->half_day !== null && $other->half_day !== $half && $other->from_date->equalTo($start) && $other->to_date->equalTo($end);

                if (! $bothHalves) {
                    throw ValidationException::withMessages(['from_date' => 'That overlaps leave you already have ('.$other->number().', '.$other->from_date->format('d M').' – '.$other->to_date->format('d M').').']);
                }
            }

            $year = $this->leaveYear($start);

            if (! $type->allow_negative) {
                $available = bcsub($this->balance($employee, $type, $year), $this->pending($employee, $type, $year), 1);

                if (bccomp($days, $available, 1) > 0) {
                    throw ValidationException::withMessages(['leave_type_id' => 'You have '.rtrim(rtrim($available, '0'), '.').' day(s) of '.$type->name.' left for this year (after other requests waiting), and this asks for '.rtrim(rtrim($days, '0'), '.').'.']);
                }
            }

            $request = new LeaveRequest;
            $request->forceFill([
                'company_id' => $employee->company_id, 'location_id' => $employee->location_id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
                'from_date' => $start->toDateString(), 'to_date' => $end->toDateString(), 'half_day' => $half, 'days' => $days, 'reason' => $reason, 'state' => 'pending',
            ])->save();

            return $request;
        });

        $this->notifier->tell($this->notifier->managerOf($employee), $actor, 'leave_requested', 'Leave request from '.$employee->name,
            $employee->name.' asks for '.rtrim(rtrim($request->days, '0'), '.').' day(s) of '.$type->name.' ('.$start->format('d M').($start->equalTo($end) ? '' : ' – '.$end->format('d M')).'): '.$reason.'.', route('hr.team-leave'));

        return $request;
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function approve(LeaveRequest $request, User $by, ?string $note = null): LeaveRequest
    {
        $done = DB::transaction(function () use ($request, $by, $note) {
            $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->mustBeDecidable($locked, $by);
            $employee = Employee::query()->findOrFail($locked->employee_id);
            $type = LeaveType::query()->findOrFail($locked->leave_type_id);
            $year = $this->leaveYear($locked->from_date);

            if (! $type->allow_negative && bccomp($locked->days, $this->balance($employee, $type, $year), 1) > 0) {
                throw ValidationException::withMessages(['balance' => 'The balance is no longer enough: '.$employee->name.' has '.$this->balance($employee, $type, $year).' day(s) of '.$type->name.' left.']);
            }

            $this->post($employee, $type, $year, 'taken', bcmul($locked->days, '-1', 1), 'R'.$locked->id, $locked->from_date, $locked->id, $by, $note);
            $locked->forceFill(['state' => 'approved', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $this->clean($note)])->save();

            return $locked;
        });

        $this->reclose($done);
        $this->tellPerson($done, $by, 'approved', $note);

        return $done;
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function reject(LeaveRequest $request, User $by, string $note): LeaveRequest
    {
        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Say why it is being turned down.']);
        }

        $done = DB::transaction(function () use ($request, $by, $note) {
            $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->mustBeDecidable($locked, $by);
            $locked->forceFill(['state' => 'rejected', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $this->clean($note)])->save();

            return $locked;
        });

        $this->tellPerson($done, $by, 'turned down', $note);

        return $done;
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function cancel(LeaveRequest $request, User $by, string $reason): LeaveRequest
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['cancel_reason' => 'Say why it is being cancelled (up to 500 characters).']);
        }

        $done = DB::transaction(function () use ($request, $by, $reason) {
            $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $employee = Employee::query()->findOrFail($locked->employee_id);
            $own = $employee->user_id === $by->id;

            if (! in_array($locked->state, ['pending', 'approved'], true)) {
                throw ValidationException::withMessages(['state' => 'This request is already '.strtolower(LeaveRequest::STATES[$locked->state] ?? $locked->state).'.']);
            }

            $managed = $this->reach->decides($by, $employee, 'leave.approve_all', 'leave.approve');

            if (! $own && ! $managed) {
                throw new AuthorizationException('You may not cancel this request.');
            }

            if ($locked->state === 'approved' && ! $managed && ! $locked->from_date->isFuture()) {
                throw ValidationException::withMessages(['state' => 'The leave has started: ask your manager or HR to cancel it.']);
            }

            if ($locked->state === 'approved') {
                $this->post($employee, LeaveType::query()->findOrFail($locked->leave_type_id), $this->leaveYear($locked->from_date), 'reversal', $locked->days, 'R'.$locked->id, now(), $locked->id, $by, $reason);
            }

            $locked->forceFill(['state' => 'cancelled', 'cancelled_by' => $by->id, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();

            return $locked;
        });

        $this->reclose($done);
        $this->tellPerson($done, $by, 'cancelled', $reason, true);

        return $done;
    }

    /**
     * Give or take away days by hand (HR): an opening balance, a correction, a grant for a type with no accrual.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function adjust(Employee $employee, LeaveType $type, string $days, string $reason, User $by, ?string $on = null): LeaveLedgerEntry
    {
        if (! $by->can('leave.manage_balances')) {
            throw new AuthorizationException('You may not change leave balances.');
        }

        $errors = [];
        $date = $on === null || $on === '' ? CarbonImmutable::today() : $this->date($on);

        if (preg_match('/^-?\d{1,3}(\.[05])?$/', trim($days)) !== 1 || (float) $days === 0.0 || abs((float) $days) > 365) {
            $errors['days'] = 'Enter the days to add (or take away with a minus): a whole or half day, not zero.';
        }

        if (trim($reason) === '' || mb_strlen(trim($reason)) > 300) {
            $errors['reason'] = 'Say why (up to 300 characters).';
        }

        if ($date === null) {
            $errors['on'] = 'Enter the date.';
        }

        if ($employee->isExited()) {
            $errors['employee'] = 'This person has left the company.';
        }

        if ($errors !== [] || $date === null) {
            throw ValidationException::withMessages($errors ?: ['on' => 'Enter the date.']);
        }

        return $this->post($employee, $type, $this->leaveYear($date), 'adjustment', number_format((float) $days, 1, '.', ''), 'A'.Str::lower((string) Str::ulid()), $date, null, $by, trim($reason));
    }

    // ---- internals -------------------------------------------------------------------------------------------------------------------

    /**
     * Write one line to the ledger (only `LeaveAccrual` and this class do). The unique key makes a repeat harmless: `false` from a duplicate is not an error here
     * because every caller has already decided, under a lock, that the line is due.
     */
    public function post(Employee $employee, LeaveType $type, int $year, string $kind, string $days, string $key, CarbonInterface $on, ?int $requestId, ?User $by, ?string $remarks): LeaveLedgerEntry
    {
        $entry = new LeaveLedgerEntry;
        $entry->forceFill([
            'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'leave_year' => $year, 'entry_type' => $kind, 'days' => $days,
            'period_key' => $key, 'leave_request_id' => $requestId, 'entry_date' => $on->toDateString(), 'remarks' => $this->clean($remarks, 300), 'recorded_by' => $by?->id, 'created_at' => now(),
        ])->save();

        return $entry;
    }

    private function mustBeDecidable(LeaveRequest $request, User $by): void
    {
        if ($request->state !== 'pending') {
            throw ValidationException::withMessages(['state' => 'This request has already been '.strtolower(LeaveRequest::STATES[$request->state] ?? $request->state).'.']);
        }

        $employee = Employee::query()->findOrFail($request->employee_id);

        if (! $this->reach->decides($by, $employee, 'leave.approve_all', 'leave.approve')) {
            throw new AuthorizationException($employee->user_id === $by->id ? 'You cannot decide your own leave.' : 'You may not decide this request.');
        }
    }

    /** Tell the person (or, when the person cancelled their own, their manager) what happened to a request. */
    private function tellPerson(LeaveRequest $request, User $by, string $what, ?string $note, bool $cancel = false): void
    {
        $employee = Employee::query()->find($request->employee_id);

        if ($employee === null) {
            return;
        }

        $when = $request->from_date->format('d M').($request->from_date->equalTo($request->to_date) ? '' : ' – '.$request->to_date->format('d M'));
        $note = $note === null || trim($note) === '' ? '' : ' “'.trim($note).'”';

        if ($cancel && $employee->user_id === $by->id) {
            $this->notifier->tell($this->notifier->managerOf($employee), $by, 'leave_cancelled', $employee->name.' cancelled leave', $employee->name.' cancelled the leave for '.$when.'.'.$note, route('hr.team-leave'));

            return;
        }

        $this->notifier->tell($this->notifier->loginOf($employee), $by, 'leave_decided', 'Your leave was '.$what, 'Your leave for '.$when.' was '.$what.' by '.$by->name.'.'.$note, route('hr.my-leave'));
    }

    /** The attendance of days already closed changes with the leave: close them again. */
    private function reclose(LeaveRequest $request): void
    {
        $employee = Employee::query()->find($request->employee_id);

        if ($employee !== null) {
            $this->attendance->recloseRange($employee, $request->from_date, $request->to_date);
        }
    }

    private function clean(?string $text, int $max = 500): ?string
    {
        $text = $text === null ? '' : trim($text);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private function date(string $value): ?CarbonImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? CarbonImmutable::instance($parsed) : null;
    }
}

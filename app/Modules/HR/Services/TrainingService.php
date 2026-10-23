<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\TrainingEnrolment;
use App\Modules\HR\Models\TrainingProgram;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Training programs and who attends them — the one door.
 *
 *   - A program is planned → completed or cancelled. It has a capacity; **enrolling takes the program row lock first**, so two people cannot take the last seat.
 *   - HR (`training.edit`) enrols anyone; a manager with `training.enrol_team` enrols the people who report to them (never themself). Only people still with the company, only
 *     into a planned program that has not ended, once each.
 *   - Outcomes (completed with an optional score of 0–100, absent, dropped) are recorded from the first day of the program, by the same people who enrol.
 *   - A program can be completed only when nobody is still "enrolled" (every outcome is recorded); cancelling it drops everyone and tells them.
 */
final class TrainingService
{
    public function __construct(private readonly EmployeeAccess $access, private readonly HrNotifier $notifier, private readonly TenantContext $tenant) {}

    private function company(): int
    {
        return (int) $this->tenant->activeCompanyId();
    }

    /** May this person enrol or record for `$employee`? HR for anyone; a manager with the team right for their own line. */
    public function manages(User $user, Employee $employee): bool
    {
        if ($user->can('training.edit')) {
            return true;
        }

        $own = $this->access->own($user);

        return $own !== null && $own->id !== $employee->id && $user->can('training.enrol_team') && in_array($employee->id, $this->access->belowIds($own->id), true);
    }

    /**
     * @param  array<string, mixed>  $input  code, title, trainer, mode, venue, starts_on, ends_on, capacity
     *
     * @throws ValidationException
     */
    public function saveProgram(array $input, User $actor, ?TrainingProgram $editing = null): TrainingProgram
    {
        if (! $actor->can($editing === null ? 'training.create' : 'training.edit')) {
            throw new AuthorizationException('You may not change training programs.');
        }

        $errors = [];
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $title = trim((string) ($input['title'] ?? ''));
        $start = $this->day((string) ($input['starts_on'] ?? ''));
        $end = $this->day((string) ($input['ends_on'] ?? ''));
        $capacity = trim((string) ($input['capacity'] ?? ''));
        $mode = (string) ($input['mode'] ?? 'in_person');

        if (preg_match('/^[A-Z0-9._\/-]{1,30}$/', $code) !== 1) {
            $errors['code'] = 'Enter a code: letters, digits and . _ - / only (up to 30).';
        } elseif (TrainingProgram::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->company())->where('code', $code)->when($editing !== null, fn ($q) => $q->whereKeyNot($editing->id))->exists()) {
            $errors['code'] = 'That code is already used.';
        }

        if ($title === '' || mb_strlen($title) > 200) {
            $errors['title'] = 'Enter the title (up to 200 characters).';
        }

        if ($start === null || $end === null || $end->lt($start) || $start->diffInDays($end) > 365) {
            $errors['ends_on'] = 'Give the first and last day (the last not before the first).';
        }

        if (! array_key_exists($mode, TrainingProgram::MODES)) {
            $errors['mode'] = 'Choose in person or online.';
        }

        if (preg_match('/^\d{1,3}$/', $capacity) !== 1 || (int) $capacity < 1 || (int) $capacity > 500) {
            $errors['capacity'] = 'Enter the number of seats (1 to 500).';
        } elseif ($editing !== null && (int) $capacity < $this->seatsTaken($editing)) {
            $errors['capacity'] = 'Already '.$this->seatsTaken($editing).' people are enrolled: the seats cannot be fewer.';
        }

        if ($editing !== null && $editing->state !== 'planned') {
            $errors['state'] = 'Only a planned program can be changed.';
        }

        if ($errors !== [] || $start === null || $end === null) {
            throw ValidationException::withMessages($errors);
        }

        $program = $editing ?? new TrainingProgram;
        $program->fill(['code' => $code, 'title' => $title, 'trainer' => $this->text($input['trainer'] ?? null, 150), 'mode' => $mode, 'venue' => $this->text($input['venue'] ?? null, 200),
            'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(), 'capacity' => (int) $capacity]);

        if ($editing === null) {
            $program->forceFill(['state' => 'planned']);
        }

        $program->save();

        return $program;
    }

    public function seatsTaken(TrainingProgram $program): int
    {
        return TrainingEnrolment::query()->where('training_program_id', $program->id)->whereIn('state', ['enrolled', 'completed'])->count();
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function enrol(TrainingProgram $program, Employee $employee, User $actor): TrainingEnrolment
    {
        if (! $this->manages($actor, $employee)) {
            throw new AuthorizationException('You may enrol only the people you manage (HR: anyone).');
        }

        $row = DB::transaction(function () use ($program, $employee) {
            $locked = TrainingProgram::query()->whereKey($program->id)->lockForUpdate()->firstOrFail();

            if ($locked->state !== 'planned' || $locked->ends_on->isPast() && ! $locked->ends_on->isToday()) {
                throw ValidationException::withMessages(['employee_id' => 'That program is not open for enrolment.']);
            }

            if ($employee->employment_status === 'exited') {
                throw ValidationException::withMessages(['employee_id' => 'This person has left the company.']);
            }

            if (TrainingEnrolment::query()->where('training_program_id', $locked->id)->where('employee_id', $employee->id)->exists()) {
                throw ValidationException::withMessages(['employee_id' => 'This person is already enrolled.']);
            }

            if ($this->seatsTaken($locked) >= $locked->capacity) {
                throw ValidationException::withMessages(['employee_id' => 'The program is full ('.$locked->capacity.' seats).']);
            }

            $row = new TrainingEnrolment;
            $row->forceFill(['company_id' => $employee->company_id, 'training_program_id' => $locked->id, 'employee_id' => $employee->id, 'state' => 'enrolled'])->save();

            return $row;
        });

        $this->notifier->tell($this->notifier->loginOf($employee), $actor, 'training_enrolled', 'You are enrolled in '.$program->title, 'You are enrolled in “'.$program->title.'” from '.$program->starts_on->format('d M Y').' to '.$program->ends_on->format('d M Y').'.', route('hr.my-training'));

        return $row;
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function record(TrainingEnrolment $enrolment, string $state, ?string $score, User $actor): TrainingEnrolment
    {
        $employee = Employee::query()->findOrFail($enrolment->employee_id);

        if (! $this->manages($actor, $employee)) {
            throw new AuthorizationException('You may record outcomes only for the people you manage (HR: anyone).');
        }

        $score = $score === null || trim($score) === '' ? null : trim($score);

        if (! in_array($state, ['completed', 'absent', 'dropped'], true)) {
            throw ValidationException::withMessages(['state' => 'Choose completed, absent or dropped.']);
        }

        if ($score !== null && ($state !== 'completed' || preg_match('/^\d{1,3}(\.\d)?$/', $score) !== 1 || (float) $score > 100)) {
            throw ValidationException::withMessages(['score' => 'A score (0 to 100) goes with a completed training only.']);
        }

        return DB::transaction(function () use ($enrolment, $state, $score) {
            $program = TrainingProgram::query()->whereKey($enrolment->training_program_id)->lockForUpdate()->firstOrFail();
            $locked = TrainingEnrolment::query()->whereKey($enrolment->id)->lockForUpdate()->firstOrFail();

            if ($program->state !== 'planned') {
                throw ValidationException::withMessages(['state' => 'The program is '.strtolower(TrainingProgram::STATES[$program->state] ?? $program->state).': its outcomes are fixed.']);
            }

            if ($program->starts_on->isFuture()) {
                throw ValidationException::withMessages(['state' => 'The program starts on '.$program->starts_on->format('d M').': outcomes can be recorded from that day.']);
            }

            $locked->forceFill(['state' => $state, 'score' => $score])->save();

            return $locked;
        });
    }

    /** @throws ValidationException */
    public function completeProgram(TrainingProgram $program, User $actor): TrainingProgram
    {
        if (! $actor->can('training.edit')) {
            throw new AuthorizationException('You may not complete a program.');
        }

        return DB::transaction(function () use ($program) {
            $locked = TrainingProgram::query()->whereKey($program->id)->lockForUpdate()->firstOrFail();
            $waiting = TrainingEnrolment::query()->where('training_program_id', $locked->id)->where('state', 'enrolled')->count();

            if ($locked->state !== 'planned' || $locked->starts_on->isFuture()) {
                throw ValidationException::withMessages(['state' => 'Only a planned program that has started can be completed.']);
            }

            if ($waiting > 0) {
                throw ValidationException::withMessages(['state' => $waiting.' '.($waiting === 1 ? 'person has' : 'people have').' no outcome yet: record each one first.']);
            }

            $locked->forceFill(['state' => 'completed'])->save();

            return $locked;
        });
    }

    /** @throws ValidationException */
    public function cancelProgram(TrainingProgram $program, User $actor): TrainingProgram
    {
        if (! $actor->can('training.edit')) {
            throw new AuthorizationException('You may not cancel a program.');
        }

        $told = collect();

        $done = DB::transaction(function () use ($program, &$told) {
            $locked = TrainingProgram::query()->whereKey($program->id)->lockForUpdate()->firstOrFail();

            if ($locked->state !== 'planned') {
                throw ValidationException::withMessages(['state' => 'Only a planned program can be cancelled.']);
            }

            $told = TrainingEnrolment::query()->where('training_program_id', $locked->id)->where('state', 'enrolled')->pluck('employee_id');
            TrainingEnrolment::query()->where('training_program_id', $locked->id)->where('state', 'enrolled')->update(['state' => 'dropped']);
            $locked->forceFill(['state' => 'cancelled'])->save();

            return $locked;
        });

        foreach (Employee::query()->whereIn('id', $told)->get() as $employee) {
            $this->notifier->tell($this->notifier->loginOf($employee), $actor, 'training_cancelled', '“'.$done->title.'” is cancelled', 'The training “'.$done->title.'” has been cancelled.', route('hr.my-training'));
        }

        return $done;
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
}

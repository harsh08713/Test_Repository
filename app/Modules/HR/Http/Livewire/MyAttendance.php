<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Models\AttendanceRegularisation;
use App\Modules\HR\Services\AttendanceService;
use App\Modules\HR\Services\WorkCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** "My attendance": punch in and out, today so far, the month's days, and corrections for a missed punch. */
#[Title('My attendance')]
class MyAttendance extends Component
{
    use ActsAsStaff;

    #[Url(as: 'month')]
    public string $month = '';

    public bool $correcting = false;

    /** @var array<string, string> */
    public array $form = ['work_date' => '', 'in_time' => '09:30', 'out_time' => '18:30', 'reason' => ''];

    public function mount(): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            $this->month = now()->format('Y-m');
        }
    }

    public function punchIn(AttendanceService $attendance): void
    {
        $this->punch($attendance, 'in', 'Punched in.');
    }

    public function punchOut(AttendanceService $attendance): void
    {
        $this->punch($attendance, 'out', 'Punched out.');
    }

    private function punch(AttendanceService $attendance, string $direction, string $done): void
    {
        $employee = $this->ownEmployee();
        abort_if($employee === null, 403);

        $this->attempt(fn () => $attendance->punch($employee, $direction, $this->actor(), request()->ip()), $done);
    }

    public function openCorrection(?string $date = null): void
    {
        $this->resetErrorBag();
        $this->correcting = $date !== null || ! $this->correcting;
        $this->form = ['work_date' => $date ?? now()->subDay()->toDateString(), 'in_time' => '09:30', 'out_time' => '18:30', 'reason' => ''];
    }

    public function submitCorrection(AttendanceService $attendance): void
    {
        $employee = $this->ownEmployee();
        abort_if($employee === null, 403);

        if ($this->attempt(fn () => $attendance->requestRegularisation($employee, $this->form['work_date'], $this->form['in_time'], $this->form['out_time'], $this->form['reason'], $this->actor()), 'Your correction has gone to your manager.')) {
            $this->correcting = false;
        }
    }

    public function cancelCorrection(AttendanceService $attendance, int $id): void
    {
        $employee = $this->ownEmployee();
        abort_if($employee === null, 403);
        $row = AttendanceRegularisation::query()->where('employee_id', $employee->id)->findOrFail($id);

        $this->attempt(fn () => $attendance->cancelRegularisation($row, $this->actor()), 'Request cancelled.');
    }

    public function render(AttendanceService $attendance, WorkCalendar $calendar): View
    {
        $employee = $this->ownEmployee();
        $first = CarbonImmutable::createFromFormat('!Y-m', $this->month) ?: CarbonImmutable::today()->startOfMonth();
        $last = $first->endOfMonth()->startOfDay();
        $days = collect();
        $today = null;

        if ($employee !== null) {
            $closed = AttendanceDay::query()->where('employee_id', $employee->id)->whereBetween('work_date', [$first->toDateString(), $last->toDateString()])->get()->keyBy(fn (AttendanceDay $d) => $d->work_date->toDateString());
            $holidays = $calendar->holidays($first, $last);

            for ($d = $first; $d->lte($last); $d = $d->addDay()) {
                $key = $d->toDateString();
                $days->push(['date' => $d, 'row' => $closed->get($key), 'holiday' => $holidays[$key] ?? null, 'off' => $calendar->isWeeklyOff($d), 'future' => $d->gte(CarbonImmutable::today())]);
            }

            $today = $attendance->today($employee);
        }

        return view('hr.my-attendance', [
            'employee' => $employee, 'today' => $today, 'days' => $days, 'first' => $first, 'statuses' => AttendanceDay::STATUSES,
            'shift' => $employee === null ? null : $attendance->shiftFor($employee),
            'corrections' => $employee === null ? collect() : AttendanceRegularisation::query()->where('employee_id', $employee->id)->orderByDesc('work_date')->orderByDesc('id')->limit(15)->get(),
            'prev' => $first->subMonth()->format('Y-m'), 'next' => $first->addMonth()->format('Y-m'), 'isCurrent' => $first->format('Y-m') === now()->format('Y-m'),
        ]);
    }
}

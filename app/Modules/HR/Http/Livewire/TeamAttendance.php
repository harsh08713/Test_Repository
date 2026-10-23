<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\AttendanceDay;
use App\Modules\HR\Services\StaffReach;
use App\Modules\HR\Services\WorkCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** The month's attendance of the people the viewer manages (everyone, for HR): a count of each kind of day for each person and a day-by-day grid. */
#[Title('Team attendance')]
class TeamAttendance extends Component
{
    use ActsAsStaff;

    #[Url(as: 'month')]
    public string $month = '';

    public function mount(): void
    {
        $user = $this->actor();
        abort_unless($user->can('staff_attendance.view') || $user->can('staff_attendance.approve_all'), 403);

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            $this->month = now()->format('Y-m');
        }
    }

    public function render(StaffReach $reach, WorkCalendar $calendar): View
    {
        $user = $this->actor();
        $first = CarbonImmutable::createFromFormat('!Y-m', $this->month) ?: CarbonImmutable::today()->startOfMonth();
        $last = $first->endOfMonth()->startOfDay();
        $own = $this->ownEmployee();

        $people = $reach->employees($user, 'staff_attendance.approve_all', 'staff_attendance.view')->when($own !== null && ! $user->can('staff_attendance.approve_all'), fn ($q) => $q->where('employees.id', '!=', $own?->id))
            ->orderBy('name')->get(['id', 'code', 'name']);

        $rows = AttendanceDay::query()->whereIn('employee_id', $people->pluck('id'))->whereBetween('work_date', [$first->toDateString(), $last->toDateString()])->get()->groupBy('employee_id');
        $table = [];

        foreach ($people as $p) {
            $mine = $rows->get($p->id, collect())->keyBy(fn (AttendanceDay $d) => $d->work_date->day);
            $table[] = [
                'person' => $p, 'days' => $mine,
                'present' => $mine->where('status', 'present')->count(), 'half' => $mine->where('status', 'half_day')->count(), 'absent' => $mine->where('status', 'absent')->count(),
                'leave' => $mine->where('status', 'leave')->count(), 'late' => $mine->where('late_minutes', '>', 0)->count(), 'missing' => $mine->where('missing_out', true)->count(),
            ];
        }

        return view('hr.team-attendance', [
            'table' => $table, 'first' => $first, 'daysInMonth' => $first->daysInMonth, 'holidays' => $calendar->holidays($first, $last),
            'prev' => $first->subMonth()->format('Y-m'), 'next' => $first->addMonth()->format('Y-m'), 'current' => now()->format('Y-m'),
        ]);
    }
}

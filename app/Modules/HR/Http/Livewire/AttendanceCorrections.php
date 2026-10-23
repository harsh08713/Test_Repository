<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\AttendanceRegularisation;
use App\Modules\HR\Services\AttendanceService;
use App\Modules\HR\Services\StaffReach;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Missed-punch corrections of the people the viewer manages (everyone, for HR). Approving adds the two punches and closes the day again. */
#[Title('Attendance corrections')]
class AttendanceCorrections extends Component
{
    use ActsAsStaff, WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'state')]
    public string $state = 'pending';

    public int $rejecting = 0;

    public string $note = '';

    public function mount(): void
    {
        $user = $this->actor();
        abort_unless($user->can('staff_attendance.view') || $user->can('staff_attendance.approve_all'), 403);
    }

    public function updating(string $property): void
    {
        if ($property === 'state') {
            $this->resetPage();
        }
    }

    public function approveCorrection(AttendanceService $attendance, int $id): void
    {
        $this->attempt(fn () => $attendance->approveRegularisation($this->find($id), $this->actor()), 'Correction approved: the punches were added.');
    }

    public function startReject(int $id): void
    {
        $this->resetErrorBag();
        $this->rejecting = $this->rejecting === $id ? 0 : $id;
        $this->note = '';
    }

    public function confirmReject(AttendanceService $attendance): void
    {
        $row = $this->find($this->rejecting);

        if ($this->attempt(fn () => $attendance->rejectRegularisation($row, $this->actor(), $this->note), 'Correction turned down.')) {
            $this->rejecting = 0;
        }
    }

    private function find(int $id): AttendanceRegularisation
    {
        return app(StaffReach::class)->restrict(AttendanceRegularisation::query(), $this->actor(), 'staff_attendance.approve_all', 'staff_attendance.view')->findOrFail($id);
    }

    public function render(StaffReach $reach): View
    {
        $user = $this->actor();
        $own = $this->ownEmployee();

        $rows = $reach->restrict(AttendanceRegularisation::query(), $user, 'staff_attendance.approve_all', 'staff_attendance.view')->with(['employee:id,name,code,user_id', 'decider:id,name'])
            ->when(in_array($this->state, ['pending', 'approved', 'rejected', 'cancelled'], true), fn (Builder $q) => $q->where('state', $this->state))
            ->when($own !== null && ! $user->can('staff_attendance.approve_all'), fn (Builder $q) => $q->where('employee_id', '!=', $own->id))
            ->orderByRaw("state = 'pending' desc")->orderByDesc('work_date')->orderBy('id')->paginate(self::PER_PAGE);

        $can = $rows->getCollection()->mapWithKeys(fn (AttendanceRegularisation $r) => [$r->id => $r->employee !== null && $reach->decides($user, $r->employee, 'staff_attendance.approve_all', 'staff_attendance.approve')]);

        return view('hr.attendance-corrections', ['rows' => $rows, 'can' => $can]);
    }
}

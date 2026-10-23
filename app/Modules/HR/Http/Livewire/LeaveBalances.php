<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveLedgerEntry;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Services\LeaveService;
use App\Modules\HR\Services\StaffReach;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Everyone's leave balance for the year (the people the viewer manages; everyone for HR), and, for HR, a way to give or take away days by hand. */
#[Title('Leave balances')]
class LeaveBalances extends Component
{
    use ActsAsStaff;

    public bool $adjusting = false;

    /** @var array<string, string> */
    public array $form = ['employee_id' => '', 'leave_type_id' => '', 'days' => '', 'reason' => '', 'on' => ''];

    public function mount(): void
    {
        $user = $this->actor();
        abort_unless($user->can('leave.view') || $user->can('leave.approve_all'), 403);
    }

    public function openAdjust(): void
    {
        abort_unless($this->actor()->can('leave.manage_balances'), 403);
        $this->resetErrorBag();
        $this->adjusting = ! $this->adjusting;
        $this->form = ['employee_id' => '', 'leave_type_id' => '', 'days' => '', 'reason' => '', 'on' => now()->toDateString()];
    }

    public function saveAdjustment(LeaveService $leave, StaffReach $reach): void
    {
        $user = $this->actor();
        $employee = ctype_digit($this->form['employee_id']) ? $reach->employees($user, 'leave.approve_all', 'leave.view')->find((int) $this->form['employee_id']) : null;
        $type = ctype_digit($this->form['leave_type_id']) ? LeaveType::query()->find((int) $this->form['leave_type_id']) : null;

        if ($employee === null || $type === null) {
            $this->resetErrorBag();
            $this->addError($employee === null ? 'employee_id' : 'leave_type_id', $employee === null ? 'Choose the person.' : 'Choose the kind of leave.');

            return;
        }

        if ($this->attempt(fn () => $leave->adjust($employee, $type, trim($this->form['days']), $this->form['reason'], $user, $this->form['on']), 'Balance changed and written to the ledger.')) {
            $this->adjusting = false;
        }
    }

    public function render(LeaveService $leave, StaffReach $reach): View
    {
        $user = $this->actor();
        $year = $leave->leaveYear(now());
        $people = $reach->employees($user, 'leave.approve_all', 'leave.view')->orderBy('name')->get(['id', 'code', 'name']);
        $types = LeaveType::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']);

        $sums = LeaveLedgerEntry::query()->where('leave_year', $year)->whereIn('employee_id', $people->pluck('id'))->toBase()
            ->groupBy('employee_id', 'leave_type_id')->selectRaw('employee_id, leave_type_id, sum(days) as total')->get()
            ->mapWithKeys(fn ($r) => [$r->employee_id.'-'.$r->leave_type_id => bcadd((string) $r->total, '0', 1)]);

        return view('hr.leave-balances', [
            'year' => $year, 'people' => $people, 'types' => $types, 'sums' => $sums, 'canAdjust' => $user->can('leave.manage_balances'),
            'all' => $reach->employees($user, 'leave.approve_all', 'leave.view')->orderBy('name')->get(['id', 'code', 'name'])->mapWithKeys(fn (Employee $e) => [$e->id => $e->code.' · '.$e->name])->all(),
        ]);
    }
}

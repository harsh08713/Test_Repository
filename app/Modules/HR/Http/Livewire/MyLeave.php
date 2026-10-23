<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Services\LeaveService;
use App\Modules\HR\Services\WorkCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** "My leave": the balances, a request form with a live count of the working days, and the person's own requests (which they may cancel). */
#[Title('My leave')]
class MyLeave extends Component
{
    use ActsAsStaff;

    public bool $applying = false;

    /** @var array<string, string> */
    public array $form = ['leave_type_id' => '', 'from_date' => '', 'to_date' => '', 'half_day' => '', 'reason' => ''];

    public int $cancelling = 0;

    public string $cancelReason = '';

    public function openForm(): void
    {
        $this->resetErrorBag();
        $this->applying = ! $this->applying;
        $this->form = ['leave_type_id' => '', 'from_date' => now()->toDateString(), 'to_date' => now()->toDateString(), 'half_day' => '', 'reason' => ''];
    }

    public function submitRequest(LeaveService $leave): void
    {
        $employee = $this->ownEmployee();
        $type = ctype_digit($this->form['leave_type_id']) ? LeaveType::query()->find((int) $this->form['leave_type_id']) : null;

        if ($employee === null || $type === null) {
            $this->resetErrorBag();
            $this->addError('leave_type_id', 'Choose the kind of leave.');

            return;
        }

        if ($this->attempt(fn () => $leave->apply($employee, $type, $this->form['from_date'], $this->form['to_date'], $this->form['half_day'] === '' ? null : $this->form['half_day'], $this->form['reason'], $this->actor()), 'Your request has gone to your manager.')) {
            $this->applying = false;
        }
    }

    public function startCancel(int $id): void
    {
        $this->cancelling = $this->cancelling === $id ? 0 : $id;
        $this->cancelReason = '';
        $this->resetErrorBag();
    }

    public function confirmCancel(LeaveService $leave): void
    {
        $employee = $this->ownEmployee();
        abort_if($employee === null, 403);
        $request = LeaveRequest::query()->where('employee_id', $employee->id)->findOrFail($this->cancelling);

        if ($this->attempt(fn () => $leave->cancel($request, $this->actor(), $this->cancelReason), 'Request cancelled.')) {
            $this->cancelling = 0;
        }
    }

    public function render(LeaveService $leave, WorkCalendar $calendar): View
    {
        $employee = $this->ownEmployee();
        $year = $leave->leaveYear(now());
        $preview = null;

        if ($employee !== null && $this->applying) {
            $from = $this->parse($this->form['from_date']);
            $to = $this->parse($this->form['to_date']);

            if ($from !== null && $to !== null && $to->gte($from) && $from->diffInDays($to) < LeaveService::MAX_SPAN_DAYS) {
                $days = $calendar->workingDays($from, $to);
                $preview = ['days' => $this->form['half_day'] !== '' ? ($days === [] ? 0.0 : 0.5) : (float) count($days), 'skipped' => (int) $from->diffInDays($to) + 1 - count($days)];
            }
        }

        return view('hr.my-leave', [
            'employee' => $employee, 'year' => $year, 'summary' => $employee === null ? [] : $leave->summary($employee, $year),
            'types' => LeaveType::query()->where('status', 'active')->orderBy('name')->get(),
            'requests' => $employee === null ? collect() : LeaveRequest::query()->where('employee_id', $employee->id)->with('type:id,name')->orderByDesc('from_date')->orderByDesc('id')->limit(40)->get(),
            'preview' => $preview, 'states' => LeaveRequest::STATES,
        ]);
    }

    private function parse(string $value): ?CarbonImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? CarbonImmutable::instance($parsed) : null;
    }
}

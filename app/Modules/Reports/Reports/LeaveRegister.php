<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Services\StaffReach;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/** Leave requests of the people you manage (everyone for HR) and of yourself, by the same reach as the Leave screens. The reason is left out: it can be about someone's health. */
final class LeaveRegister extends Report
{
    public function key(): string
    {
        return 'leave-register';
    }

    public function title(): string
    {
        return 'Leave register';
    }

    public function description(): string
    {
        return 'Every leave request in reach: who, what kind, the dates, the working days taken and where it stands. The reason given is not shown.';
    }

    public function group(): string
    {
        return 'HR & workforce';
    }

    public function permission(): string
    {
        return 'leave.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Leave starts';
    }

    public function choiceLabel(): string
    {
        return 'Status';
    }

    public function choices(): array
    {
        return ['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
    }

    public function columns(): array
    {
        return [
            new C('request_no', 'Request'), new C('employee', 'Person'), new C('leave_type', 'Kind'), new C('from_date', 'From', C::DATE), new C('to_date', 'To', C::DATE),
            new C('days', 'Working days'), new C('half', 'Half day'), new C('state_label', 'Status'), new C('decider', 'Decided by'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = app(StaffReach::class)->restrict(LeaveRequest::query(), $user, 'leave.approve_all', 'leave.view')
            ->join('employees as e', 'e.id', '=', 'leave_requests.employee_id')
            ->join('leave_types as t', 't.id', '=', 'leave_requests.leave_type_id')
            ->leftJoin('users as d', 'd.id', '=', 'leave_requests.decided_by')
            ->select(['leave_requests.id', 'leave_requests.from_date', 'leave_requests.to_date', 'leave_requests.days', 'leave_requests.half_day', 'leave_requests.state', 'e.name as employee', 't.name as leave_type', 'd.name as decider']);

        $this->between($query, 'leave_requests.from_date', $input);
        $input->branch === null || $query->where('leave_requests.location_id', $input->branch);
        $input->choice === null || $query->where('leave_requests.state', $input->choice);

        return $query->orderByDesc('leave_requests.from_date')->orderByDesc('leave_requests.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['request_no'] = 'LV-'.str_pad((string) $row->getAttribute('id'), 5, '0', STR_PAD_LEFT);
        $out['days'] = rtrim(rtrim((string) $row->getRawOriginal('days'), '0'), '.') ?: '0';
        $out['half'] = $row->getRawOriginal('half_day') === null ? '' : ucfirst((string) $row->getRawOriginal('half_day')).' half';
        $out['state_label'] = LeaveRequest::STATES[(string) $row->getRawOriginal('state')] ?? (string) $row->getRawOriginal('state');

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), "count(*) as total, coalesce(sum(case when leave_requests.state = 'approved' then leave_requests.days end), 0) as taken");

        return ['Requests' => number_format((int) ($t->total ?? 0)), 'Approved days' => rtrim(rtrim(number_format((float) ($t->taken ?? 0), 1), '0'), '.') ?: '0'];
    }
}

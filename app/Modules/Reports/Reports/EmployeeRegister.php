<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\EmployeeAccess;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/**
 * The employee files you may see (everyone for HR, your own line otherwise), by the same access rule as the Employees screen. It leaves out everything the screen keeps
 * behind `employee.view_sensitive` — date of birth, personal e-mail, mobile, address — so an export can never carry more than a person could read.
 */
final class EmployeeRegister extends Report
{
    public function key(): string
    {
        return 'employee-register';
    }

    public function title(): string
    {
        return 'Employee register';
    }

    public function description(): string
    {
        return 'Code, name, designation, department, branch, manager, type, status and dates of joining and leaving. No personal details.';
    }

    public function group(): string
    {
        return 'HR & workforce';
    }

    public function permission(): string
    {
        return 'employee.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Joined';
    }

    public function choiceLabel(): string
    {
        return 'Status';
    }

    public function choices(): array
    {
        return ['current' => 'Working here (active or on notice)', 'active' => 'Active', 'notice' => 'Serving notice', 'exited' => 'Left'];
    }

    public function defaultChoice(): string
    {
        return 'current';
    }

    public function startsOnThisMonth(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            new C('code', 'Code'), new C('name', 'Name'), new C('designation', 'Designation'), new C('department', 'Department'), new C('branch', 'Branch'), new C('manager', 'Reports to'),
            new C('type', 'Type'), new C('status', 'Status'), new C('joining_date', 'Joined', C::DATE), new C('exit_date', 'Last day', C::DATE),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $query = app(EmployeeAccess::class)->restrict(Employee::query(), $user)
            ->leftJoin('departments as d', 'd.id', '=', 'employees.department_id')
            ->leftJoin('designations as g', 'g.id', '=', 'employees.designation_id')
            ->leftJoin('locations as b', 'b.id', '=', 'employees.location_id')
            ->leftJoin('employees as m', 'm.id', '=', 'employees.reports_to_id')
            ->select(['employees.id', 'employees.code', 'employees.name', 'employees.employment_type', 'employees.employment_status', 'employees.joining_date', 'employees.exit_date',
                'd.name as department', 'g.name as designation', 'b.name as branch', 'm.name as manager']);

        $this->between($query, 'employees.joining_date', $input);
        $input->branch === null || $query->where('employees.location_id', $input->branch);

        match ($input->choice) {
            'current' => $query->whereIn('employees.employment_status', ['active', 'notice']),
            'active', 'notice', 'exited' => $query->where('employees.employment_status', $input->choice),
            default => null,
        };

        return $query->orderBy('employees.name')->orderBy('employees.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['type'] = Employee::TYPES[(string) $row->getRawOriginal('employment_type')] ?? (string) $row->getRawOriginal('employment_type');
        $out['status'] = ['active' => 'Active', 'notice' => 'On notice', 'exited' => 'Left'][(string) $row->getRawOriginal('employment_status')] ?? (string) $row->getRawOriginal('employment_status');

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), "count(*) as total, coalesce(sum(employees.employment_status = 'notice'), 0) as notice");

        return ['Employees' => number_format((int) ($t->total ?? 0)), 'Serving notice' => number_format((int) ($t->notice ?? 0))];
    }
}

<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveLedgerEntry;
use App\Modules\HR\Models\LeaveType;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Support\FinancialYear;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Grants leave days into the ledger, and carries the unused ones into the new year. **Idempotent**: every line has its own `period_key` (yearly `Y2026`, monthly
 * `M2026-10`, carry-forward `C2026`, lapse `L2025`) under a unique key, so the scheduler, a retry and a person running it by hand cannot grant anything twice.
 *
 *   - Yearly: the whole quota once, at the start of the leave year (the financial year); someone who joins during it gets the months left, in halves.
 *   - Monthly: the quota's twelfth for each month from the joining month, in halves so that twelve months add up to exactly the quota.
 *   - Carry-forward: of last year's balance, up to the type's carry limit (in halves) opens the new year; the rest lapses. A negative balance is not carried.
 *   - A person who has left, or has not joined yet, is granted nothing. A type created mid-year is granted from the start of that year on the next run.
 */
final class LeaveAccrual
{
    public function __construct(private readonly LeaveService $leave, private readonly TenantContext $tenant) {}

    /** @return int lines written, for every company */
    public function run(?CarbonInterface $on = null): int
    {
        $on = CarbonImmutable::instance($on ?? now())->startOfDay();
        $written = 0;

        foreach (Company::withoutGlobalScopes(Tenancy::SCOPES)->pluck('id') as $companyId) {
            $written += $this->tenant->withCompany((int) $companyId, fn () => $this->forCompany($on));
        }

        return $written;
    }

    /** @return int lines written for the company being worked in */
    public function forCompany(CarbonImmutable $on): int
    {
        $types = LeaveType::query()->where('status', 'active')->where('annual_quota', '>', 0)->where('accrual', '!=', 'none')->get();
        $written = 0;

        if ($types->isEmpty()) {
            return 0;
        }

        foreach (Employee::query()->whereIn('employment_status', ['active', 'notice'])->whereDate('joining_date', '<=', $on->toDateString())->get() as $employee) {
            foreach ($types as $type) {
                $written += $this->forEmployee($employee, $type, $on);
            }
        }

        return $written;
    }

    public function forEmployee(Employee $employee, LeaveType $type, CarbonImmutable $on): int
    {
        $year = $this->leave->leaveYear($on);
        $yearStart = CarbonImmutable::instance(FinancialYear::range($on)[0]);
        $joined = CarbonImmutable::instance($employee->joining_date);
        $done = LeaveLedgerEntry::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->whereIn('leave_year', [$year - 1, $year])->pluck('period_key')->flip();
        $written = 0;
        $quota = (float) $type->annual_quota;

        // carry-forward from last year
        if ($joined->lt($yearStart) && ! $done->has('C'.$year)) {
            $previous = (float) $this->leave->balance($employee, $type, $year - 1);

            if ($previous > 0) {
                $carry = min($previous, floor(((float) $type->carry_forward_max) * 2) / 2);

                if ($carry > 0) {
                    $written += $this->write($employee, $type, $year, 'opening', $carry, 'C'.$year, $yearStart, 'Carried forward from '.($year - 1));
                }

                if ($previous - $carry > 0 && ! $done->has('L'.($year - 1))) {
                    $written += $this->write($employee, $type, $year - 1, 'lapse', -($previous - $carry), 'L'.($year - 1), $yearStart, 'Lapsed at the end of the year');
                }
            }
        }

        if ($type->accrual === 'yearly') {
            if (! $done->has('Y'.$year)) {
                $monthsLeft = $joined->lte($yearStart) ? 12 : 12 - (int) $yearStart->diffInMonths($joined->startOfMonth());
                $days = $this->half($quota * max(0, min(12, $monthsLeft)) / 12);

                if ($days > 0) {
                    $written += $this->write($employee, $type, $year, 'accrual', $days, 'Y'.$year, $joined->gt($yearStart) ? $joined : $yearStart, 'Yearly grant');
                }
            }

            return $written;
        }

        // monthly: each month from the joining month to this month, in halves that add up to the quota
        for ($month = $yearStart; $month->lte($on); $month = $month->addMonth()) {
            $key = 'M'.$month->format('Y-m');

            if ($done->has($key) || $month->endOfMonth()->lt($joined)) {
                continue;
            }

            $index = (int) $yearStart->diffInMonths($month);
            $days = $this->half($quota * ($index + 1) / 12) - $this->half($quota * $index / 12);

            if ($days > 0) {
                $written += $this->write($employee, $type, $year, 'accrual', $days, $key, $month->lt($joined) ? $joined : $month, 'Monthly grant for '.$month->format('M Y'));
            }
        }

        return $written;
    }

    private function half(float $days): float
    {
        return round($days * 2) / 2;
    }

    private function write(Employee $employee, LeaveType $type, int $year, string $kind, float $days, string $key, CarbonInterface $on, string $remarks): int
    {
        try {
            $this->leave->post($employee, $type, $year, $kind, number_format($days, 1, '.', ''), $key, $on, null, null, $remarks);
        } catch (UniqueConstraintViolationException) {
            return 0;   // a run at the same moment already wrote it
        }

        return 1;
    }
}

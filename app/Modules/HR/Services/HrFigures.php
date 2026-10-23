<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the HR dashboard. Read-only; every number is counted from the employee files the person is allowed to see (EmployeeAccess), so a
 * manager without `employee.view_all` sees their own line only.
 *
 * Headcount = people who are active or serving notice (not those who have left). A joiner is someone whose joining date falls in the last 30 days, a leaver
 * someone whose last working day falls in the last 30 days and who has left.
 */
final class HrFigures
{
    public const WINDOW_DAYS = 30;

    public function __construct(private readonly EmployeeAccess $access) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $from = $today->subDays(self::WINDOW_DAYS);
        $to = $today->addDays(self::WINDOW_DAYS);
        $current = fn (): Builder => $this->access->restrict(Employee::query(), $user)->whereIn('employment_status', ['active', 'notice']);

        $staff = $current()->get(['id', 'name', 'date_of_birth', 'joining_date', 'employment_type', 'probation_end_date', 'employment_status', 'reports_to_id']);

        return [
            'headcount' => $staff->count(),
            'on_notice' => $staff->where('employment_status', 'notice')->count(),
            'joiners' => $this->access->restrict(Employee::query(), $user)->whereBetween('joining_date', [$from->toDateString(), $today->toDateString()])->count(),
            'leavers' => $this->access->restrict(Employee::query(), $user)->where('employment_status', 'exited')->whereBetween('exit_date', [$from->toDateString(), $today->toDateString()])->count(),
            'by_department' => $this->grouped($current(), 'departments', 'department_id', 'No department'),
            'by_branch' => $this->grouped($current(), 'locations', 'location_id', 'No branch'),
            'by_type' => $staff->groupBy('employment_type')->map(fn ($g, $k) => ['name' => Employee::TYPES[$k] ?? (string) $k, 'count' => $g->count()])->sortBy([['count', 'desc'], ['name', 'asc']])->values()->all(),
            'birthdays' => $this->upcoming($staff->filter(fn ($e) => $e->date_of_birth !== null), 'date_of_birth', $today, $to, false),
            'anniversaries' => $this->upcoming($staff, 'joining_date', $today, $to, true),
            'probation_ending' => $staff->filter(fn ($e) => $e->employment_type === 'probation' && $e->probation_end_date !== null && $e->probation_end_date->betweenIncluded($today, $to))
                ->sortBy('probation_end_date')->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'on' => $e->probation_end_date->toDateString()])->values()->all(),
            'leaving_soon' => $staff->where('employment_status', 'notice')->map(fn ($e) => ['id' => $e->id, 'name' => $e->name])->values()->all(),
            'orphans' => $current()->whereIn('reports_to_id', Employee::query()->where('employment_status', 'exited')->select('id'))->count(),
            'documents_due' => $user->can('employee.view_sensitive') ? $this->documentsDue($user, $to) : null,
        ];
    }

    /**
     * @param  Builder<Employee>  $query
     * @return list<array{name: string, count: int}>
     */
    private function grouped(Builder $query, string $table, string $column, string $none): array
    {
        $rows = $query->toBase()->leftJoin($table, $table.'.id', '=', 'employees.'.$column)->groupBy($table.'.name')
            ->select($table.'.name as name', DB::raw('count(*) as total'))->get();

        return $rows->map(fn ($r) => ['name' => $r->name ?? $none, 'count' => (int) $r->total])->sortBy([['count', 'desc'], ['name', 'asc']])->values()->all();
    }

    /**
     * People whose day (a birthday, or the day they joined) falls in the next window; for the day they joined only those with at least one year behind them.
     *
     * @param  Collection<int, Employee>  $people
     * @return list<array{id: int, name: string, on: string, years: int|null}>
     */
    private function upcoming($people, string $attribute, CarbonImmutable $today, CarbonImmutable $to, bool $needYears): array
    {
        $out = [];

        foreach ($people as $p) {
            $origin = $p->{$attribute};

            foreach ([$today->year, $to->year] as $year) {
                $day = $origin->month === 2 && $origin->day === 29 && ! CarbonImmutable::create($year, 1, 1)->isLeapYear() ? CarbonImmutable::create($year, 2, 28) : CarbonImmutable::create($year, $origin->month, $origin->day);

                if ($day->betweenIncluded($today, $to) && (! $needYears || $year > $origin->year)) {
                    $out[$p->id.'-'.$day->toDateString()] = ['id' => $p->id, 'name' => $p->name, 'on' => $day->toDateString(), 'years' => $needYears ? $year - $origin->year : null];
                }
            }
        }

        usort($out, fn ($a, $b) => [$a['on'], $a['name']] <=> [$b['on'], $b['name']]);

        return $out;
    }

    /** @return list<array{id: int, employee_id: int, employee: string, title: string, on: string, expired: bool}> */
    private function documentsDue(User $user, CarbonImmutable $to): array
    {
        $visible = $this->access->restrict(Employee::query(), $user)->select('id');
        $today = CarbonImmutable::today();

        return EmployeeDocument::query()->whereIn('employee_id', $visible)->whereNotNull('expires_on')->where('expires_on', '<=', $to->toDateString())->orderBy('expires_on')->limit(50)
            ->with('employee:id,name')->get()->map(fn (EmployeeDocument $d) => [
                'id' => $d->id, 'employee_id' => $d->employee_id, 'employee' => (string) $d->employee?->name, 'title' => $d->title, 'on' => $d->expires_on->toDateString(), 'expired' => $d->expires_on->lt($today),
            ])->all();
    }
}

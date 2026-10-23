<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\CRM\Models\RoutePlan;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/** For each published route plan: did the salesperson check in at a planned stop, where, how far off, and how many attempts were refused. */
final class RouteAdherence extends Report
{
    public function key(): string
    {
        return 'route-adherence';
    }

    public function title(): string
    {
        return 'Attendance and route adherence';
    }

    public function description(): string
    {
        return 'One row for each published route plan day: the stops planned, whether the salesperson checked in at one of them, how far from it, and refused attempts.';
    }

    public function group(): string
    {
        return 'Field sales';
    }

    public function permission(): string
    {
        return 'attendance.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'person', 'team', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Plan date';
    }

    public function choiceLabel(): string
    {
        return 'Result';
    }

    public function choices(): array
    {
        return ['attended' => 'Checked in', 'missed' => 'Not checked in (day is over)', 'upcoming' => 'Today or later, not yet checked in'];
    }

    public function note(): string
    {
        return 'Attendance is one check-in a day against any stop of the published plan, so adherence here means “checked in on the day the plan was published for”. A day with no published plan is not a miss.';
    }

    public function columns(): array
    {
        return [
            new C('plan_date', 'Date', C::DATE), new C('person', 'Salesperson'), new C('branch', 'Branch'), new C('title', 'Plan'), new C('stops', 'Stops planned', C::NUMBER),
            new C('result', 'Result'), new C('marked_at', 'Checked in at', C::DATETIME), new C('stop_label', 'At stop'), new C('distance_m', 'Distance (m)', C::NUMBER),
            new C('decision', 'How it was accepted'), new C('refused', 'Refused attempts', C::NUMBER),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $visible = app(SalesScope::class)->visibleUserIds($user);

        $query = RoutePlan::query()
            ->where('route_plans.status', 'published')
            ->leftJoin('users', 'users.id', '=', 'route_plans.user_id')
            ->leftJoin('locations', 'locations.id', '=', 'route_plans.location_id')
            ->leftJoin('attendance_records as att', fn ($j) => $j->on('att.route_plan_id', '=', 'route_plans.id')->whereIn('att.decision', ['accepted', 'accepted_override']))
            ->leftJoin('route_plan_stops as stop', 'stop.id', '=', 'att.route_stop_id')
            ->select(['route_plans.id', 'route_plans.plan_date', 'route_plans.title', 'users.name as person', 'locations.name as branch', 'att.marked_at', 'att.distance_m', 'att.decision', 'stop.label as stop_label'])
            ->selectRaw('(select count(*) from route_plan_stops where route_plan_stops.route_plan_id = route_plans.id and route_plan_stops.deleted_at is null) as stops')
            ->selectRaw('(select count(*) from geofence_violations where geofence_violations.route_plan_id = route_plans.id) as refused')
            ->selectRaw("case when att.id is not null then 'Checked in' when route_plans.plan_date < curdate() then 'Not checked in' else 'Not yet' end as result");

        $visible === null || $query->whereIn('route_plans.user_id', $visible);
        $this->between($query, 'route_plans.plan_date', $input);
        $input->branch === null || $query->where('route_plans.location_id', $input->branch);
        $input->peopleIds === null || $query->whereIn('route_plans.user_id', $input->peopleIds);

        match ($input->choice) {
            'attended' => $query->whereNotNull('att.id'),
            'missed' => $query->whereNull('att.id')->where('route_plans.plan_date', '<', now()->toDateString()),
            'upcoming' => $query->whereNull('att.id')->where('route_plans.plan_date', '>=', now()->toDateString()),
            default => null,
        };

        return $query->orderByDesc('route_plans.plan_date')->orderBy('route_plans.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['decision'] = match ((string) $row->getRawOriginal('decision')) {
            'accepted' => 'Inside the geofence', 'accepted_override' => 'Accepted by a manager', default => null,
        };

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), 'count(*) as days, coalesce(sum(att.id is not null), 0) as attended, coalesce(sum(att.id is null and route_plans.plan_date < curdate()), 0) as missed');
        $over = (int) ($t->attended ?? 0) + (int) ($t->missed ?? 0);

        return [
            'Plan days' => number_format((int) ($t->days ?? 0)), 'Checked in' => number_format((int) ($t->attended ?? 0)), 'Missed' => number_format((int) ($t->missed ?? 0)),
            'Adherence (days over)' => $over === 0 ? '—' : number_format(100 * (int) $t->attended / $over, 1).' %',
        ];
    }
}

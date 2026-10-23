<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\CRM\Enums\ViolationReason;
use App\Modules\CRM\Models\GeofenceViolation;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use Illuminate\Database\Eloquent\Builder;

/** Every attendance or field-lead attempt that was refused, and why. Append-only evidence, shown as recorded. */
final class GeofenceLog extends Report
{
    public function key(): string
    {
        return 'geofence-log';
    }

    public function title(): string
    {
        return 'Geofence violation log';
    }

    public function description(): string
    {
        return 'Every refused check-in or field lead — who, when, how far from the stop, and the reason.';
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
        return 'Attempted';
    }

    public function choiceLabel(): string
    {
        return 'Reason';
    }

    public function choices(): array
    {
        return collect(ViolationReason::cases())->mapWithKeys(fn (ViolationReason $r) => [$r->value => $r->label()])->all();
    }

    public function columns(): array
    {
        return [
            new C('attempted_at', 'When', C::DATETIME), new C('person', 'Salesperson'), new C('branch', 'Branch'), new C('stop_label', 'Stop'), new C('what', 'Attempt'),
            new C('reason_label', 'Reason'), new C('distance_m', 'Distance (m)', C::NUMBER), new C('radius_m', 'Allowed (m)', C::NUMBER), new C('accuracy_m', 'GPS accuracy (m)', C::NUMBER),
            new C('source', 'From'),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $visible = app(SalesScope::class)->visibleUserIds($user);

        $query = GeofenceViolation::query()
            ->leftJoin('users', 'users.id', '=', 'geofence_violations.user_id')
            ->leftJoin('locations', 'locations.id', '=', 'geofence_violations.location_id')
            ->leftJoin('route_plan_stops as stop', 'stop.id', '=', 'geofence_violations.route_stop_id')
            ->select([
                'geofence_violations.id', 'geofence_violations.attempted_at', 'geofence_violations.attempt_type', 'geofence_violations.reason', 'geofence_violations.distance_m',
                'geofence_violations.radius_m', 'geofence_violations.accuracy_m', 'geofence_violations.source',
                'users.name as person', 'locations.name as branch', 'stop.label as stop_label',
            ]);

        $visible === null || $query->whereIn('geofence_violations.user_id', $visible);
        $this->between($query, 'geofence_violations.attempted_at', $input, true);
        $input->branch === null || $query->where('geofence_violations.location_id', $input->branch);
        $input->peopleIds === null || $query->whereIn('geofence_violations.user_id', $input->peopleIds);
        $input->choice === null || $query->where('geofence_violations.reason', $input->choice);

        return $query->orderByDesc('geofence_violations.attempted_at')->orderByDesc('geofence_violations.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['what'] = $row->getRawOriginal('attempt_type') === 'lead_save' ? 'Field lead' : 'Check-in';
        $out['reason_label'] = ViolationReason::tryFrom((string) $row->getRawOriginal('reason'))?->label();

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), 'count(*) as total, count(distinct geofence_violations.user_id) as people');

        return ['Refused attempts' => number_format((int) ($t->total ?? 0)), 'People' => number_format((int) ($t->people ?? 0))];
    }
}

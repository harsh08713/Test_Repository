<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\CRM\Models\AttendanceRecord;
use App\Modules\CRM\Models\RoutePlan;
use App\Modules\CRM\Models\RouteStop;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Http\Request;

/**
 * Today's plan for the person it belongs to: the stops with the radius that will actually be applied to each (the stop's own,
 * else the plan's, else the company's — the same rule the geofence uses), and whether attendance is marked or can be.
 *
 * @mixin RoutePlan
 */
class RoutePlanResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RoutePlan $plan */
        $plan = $this->resource;
        $settings = app(SettingsService::class);
        $companyId = (int) $plan->company_id;
        $default = (int) $settings->get('geofence.radius_m', $companyId);

        /** @var AttendanceRecord|null $attendance */
        $attendance = $plan->relationLoaded('attendance') ? $plan->attendance : null;

        return [
            'id' => $plan->getKey(),
            'date' => $plan->plan_date->toDateString(),
            'status' => $plan->status->value,
            'title' => $plan->title,
            'branch' => ['id' => $plan->location_id, 'code' => $plan->relationLoaded('location') ? $plan->location?->code : null, 'name' => $plan->relationLoaded('location') ? $plan->location?->name : null],
            'geofence' => [
                'plan_radius_m' => $plan->geofence_radius_m,
                'default_radius_m' => $default,
                'max_accuracy_m' => (int) $settings->get('geofence.max_accuracy_m', $companyId),
            ],
            'stops' => $plan->stops->map(fn (RouteStop $stop) => [
                'id' => $stop->getKey(),
                'sequence' => $stop->sequence,
                'type' => $stop->stop_type->value,
                'label' => $stop->label,
                'pin_code' => $stop->pin_code,
                'latitude' => $stop->latitude === null ? null : (float) $stop->latitude,
                'longitude' => $stop->longitude === null ? null : (float) $stop->longitude,
                'radius_m' => $stop->hasCoordinates() ? (int) ($stop->radius_m ?? $plan->geofence_radius_m ?? $default) : null,
                'visit_from' => $stop->visit_from === null ? null : substr((string) $stop->visit_from, 0, 5),
                'visit_to' => $stop->visit_to === null ? null : substr((string) $stop->visit_to, 0, 5),
                'visit_status' => $stop->visit_status,
            ])->values()->all(),
            'attendance' => $attendance === null ? null : (new AttendanceResource($attendance))->resolve(),
            'can_mark_attendance' => $plan->isPublished() && $attendance === null,
        ];
    }
}

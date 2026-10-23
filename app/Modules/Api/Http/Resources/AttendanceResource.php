<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\Api\Support\ApiTime;
use App\Modules\CRM\Models\AttendanceRecord;
use Illuminate\Http\Request;

/**
 * One day's attendance: when the server recorded it and the measurements that decided it (distance from the stop, the radius
 * applied, the accuracy the device reported) — all computed by the server.
 *
 * @mixin AttendanceRecord
 */
class AttendanceResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AttendanceRecord $record */
        $record = $this->resource;

        return [
            'id' => $record->getKey(),
            'date' => $record->attendance_date->toDateString(),
            'marked_at' => ApiTime::iso($record->marked_at),
            'decision' => $record->decision->value,
            'position' => ['latitude' => (float) $record->latitude, 'longitude' => (float) $record->longitude],
            'accuracy_m' => (float) $record->accuracy_m,
            'distance_m' => (float) $record->distance_m,
            'radius_m' => $record->radius_m,
            'stop' => $record->route_stop_id === null ? null : ['id' => $record->route_stop_id, 'label' => $record->relationLoaded('stop') ? $record->stop?->label : null],
            'route_plan_id' => $record->route_plan_id,
            'source' => $record->source,
        ];
    }
}

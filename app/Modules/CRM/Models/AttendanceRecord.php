<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Enums\AttendanceDecision;
use App\Modules\CRM\Policies\AttendancePolicy;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One person's attendance for one day, marked against the day's published route plan from their own position.
 * Append-only: the row *is* the record, written only by AttendanceService, with the server's clock and the coordinates,
 * accuracy, distance and radius that decided it. A mistake is not edited away.
 *
 * @property int $user_id
 * @property int $route_plan_id
 * @property int|null $route_stop_id
 * @property Carbon $attendance_date
 * @property Carbon $marked_at
 * @property string $latitude
 * @property string $longitude
 * @property string $accuracy_m
 * @property string $distance_m
 * @property int $radius_m
 * @property AttendanceDecision $decision
 * @property string $source
 * @property int|null $override_by
 * @property string|null $override_reason
 * @property int|null $violation_id
 */
#[UsePolicy(AttendancePolicy::class)]
class AttendanceRecord extends Model
{
    use BelongsToCompany, BelongsToLocation;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date:Y-m-d',
            'marked_at' => 'datetime',
            'created_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_m' => 'decimal:2',
            'distance_m' => 'decimal:1',
            'radius_m' => 'integer',
            'decision' => AttendanceDecision::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Attendance records are append-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Attendance records are append-only and cannot be deleted.'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<RoutePlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(RoutePlan::class, 'route_plan_id');
    }

    /** @return BelongsTo<RouteStop, $this> */
    public function stop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'route_stop_id');
    }

    /** @return BelongsTo<User, $this> */
    public function overrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by');
    }

    /** @return BelongsTo<GeofenceViolation, $this> */
    public function violation(): BelongsTo
    {
        return $this->belongsTo(GeofenceViolation::class, 'violation_id');
    }
}

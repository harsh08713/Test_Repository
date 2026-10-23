<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Enums\ViolationReason;
use App\Modules\CRM\Policies\GeofenceViolationPolicy;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One refused attempt: who tried, when (the server's clock), what position they reported, how far from the nearest
 * stop that was and why it was refused. Append-only. A manager who accepts the attendance anyway does not edit this
 * row — a new attendance record points back at it.
 *
 * @property int $user_id
 * @property int|null $route_plan_id
 * @property int|null $route_stop_id
 * @property string $attempt_type
 * @property ViolationReason $reason
 * @property string $message
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $raw_coordinates
 * @property string|null $accuracy_m
 * @property string|null $distance_m
 * @property int|null $radius_m
 * @property string $source
 * @property Carbon $attempted_at
 */
#[UsePolicy(GeofenceViolationPolicy::class)]
class GeofenceViolation extends Model
{
    use BelongsToCompany, BelongsToLocation;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'reason' => ViolationReason::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_m' => 'decimal:2',
            'distance_m' => 'decimal:1',
            'radius_m' => 'integer',
            'attempted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The geofence violation log is append-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('The geofence violation log is append-only and cannot be deleted.'));
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

    /**
     * The attendance a manager accepted on the strength of this attempt, if any.
     *
     * @return HasOne<AttendanceRecord, $this>
     */
    public function acceptedAs(): HasOne
    {
        return $this->hasOne(AttendanceRecord::class, 'violation_id');
    }
}

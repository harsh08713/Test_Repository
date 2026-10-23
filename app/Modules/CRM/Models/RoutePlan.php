<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Enums\RoutePlanStatus;
use App\Modules\CRM\Policies\RoutePlanPolicy;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One salesperson's plan for one day at one branch: an ordered list of stops with places on the map.
 *
 * Its `status` is the plan's own state (draft → published → cancelled), not the generic active/inactive of a master.
 * **Only a published plan lets the person mark attendance** — with no plan for the day, or one that is still a draft
 * or has been cancelled, attendance is refused and the attempt is logged.
 *
 * @property int $user_id
 * @property int $location_id
 * @property Carbon $plan_date
 * @property string|null $title
 * @property int|null $geofence_radius_m
 * @property RoutePlanStatus $status
 */
#[Fillable(['title', 'geofence_radius_m', 'remarks'])]
#[UsePolicy(RoutePlanPolicy::class)]
class RoutePlan extends TenantModel
{
    use BelongsToLocation;

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => RoutePlanStatus::class,
            'plan_date' => 'date:Y-m-d',
            'geofence_radius_m' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<RouteStop, $this> */
    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class)->orderBy('sequence');
    }

    /** @return HasOne<AttendanceRecord, $this> */
    public function attendance(): HasOne
    {
        return $this->hasOne(AttendanceRecord::class);
    }

    public function isPublished(): bool
    {
        return $this->status === RoutePlanStatus::Published;
    }

    public function isForToday(?string $today = null): bool
    {
        return $this->plan_date->toDateString() === ($today ?? now()->toDateString());
    }

    /** Is the day already over? A plan of a past day is history and no longer changes. */
    public function isPast(?string $today = null): bool
    {
        return $this->plan_date->toDateString() < ($today ?? now()->toDateString());
    }

    protected function auditLogName(): string
    {
        return 'route_plans';
    }
}

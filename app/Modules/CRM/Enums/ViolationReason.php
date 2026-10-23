<?php

namespace App\Modules\CRM\Enums;

/** Why an attendance attempt was refused. Every one of them is written to the violation log. */
enum ViolationReason: string
{
    case NoRoutePlan = 'no_route_plan';
    case PlanNotPublished = 'plan_not_published';
    case PlanCancelled = 'plan_cancelled';
    case InvalidGps = 'invalid_gps';
    case LowAccuracy = 'low_accuracy';
    case OutsideGeofence = 'outside_geofence';

    public function label(): string
    {
        return match ($this) {
            self::NoRoutePlan => 'No route plan for the day',
            self::PlanNotPublished => 'Route plan not published',
            self::PlanCancelled => 'Route plan cancelled',
            self::InvalidGps => 'Unusable GPS reading',
            self::LowAccuracy => 'GPS not accurate enough',
            self::OutsideGeofence => 'Outside the geofence',
        };
    }

    /**
     * Can a manager accept the attendance anyway? Only when there was a plan and a real reading that merely did not
     * qualify: never for a missing plan, and never for a reading that is not a position at all.
     */
    public function isOverridable(): bool
    {
        return $this === self::LowAccuracy || $this === self::OutsideGeofence;
    }

    public function badge(): string
    {
        return $this->isOverridable() ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800';
    }
}

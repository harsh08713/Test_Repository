<?php

namespace App\Modules\CRM\Enums;

/** How an attendance record came to be accepted. */
enum AttendanceDecision: string
{
    /** The reading was inside the geofence of a stop on the day's published plan. */
    case Accepted = 'accepted';

    /** The reading was refused, and a manager with the override right accepted it anyway, with a reason. */
    case AcceptedOverride = 'accepted_override';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Inside the geofence',
            self::AcceptedOverride => 'Accepted by a manager',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Accepted => 'bg-emerald-100 text-emerald-800',
            self::AcceptedOverride => 'bg-amber-100 text-amber-800',
        };
    }
}

<?php

namespace App\Modules\CRM\Enums;

/**
 * The state of a day's route plan: draft (being put together) → published (the person may mark attendance against it)
 * → cancelled (called off; can be reinstated as a draft while the day is not over).
 */
enum RoutePlanStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-700',
            self::Published => 'bg-emerald-100 text-emerald-800',
            self::Cancelled => 'bg-rose-100 text-rose-800',
        };
    }
}

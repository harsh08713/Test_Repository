<?php

namespace App\Modules\CRM\Enums;

/** A follow-up is planned (pending), was done (with an outcome), or was called off. */
enum FollowupStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Planned',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'bg-sky-100 text-sky-800',
            self::Done => 'bg-emerald-100 text-emerald-800',
            self::Cancelled => 'bg-slate-100 text-slate-600',
        };
    }
}

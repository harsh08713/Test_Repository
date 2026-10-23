<?php

namespace App\Modules\Shared\Enums;

/** State of a single approval level (one row in the `approvals` table). */
enum ApprovalLevelStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    /** A still-pending level that was dropped because an earlier level rejected the document. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'bg-amber-100 text-amber-800',
            self::Approved => 'bg-emerald-100 text-emerald-800',
            self::Rejected => 'bg-rose-100 text-rose-800',
            self::Cancelled => 'bg-slate-100 text-slate-500',
        };
    }
}

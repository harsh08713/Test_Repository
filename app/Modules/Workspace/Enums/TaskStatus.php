<?php

namespace App\Modules\Workspace\Enums;

enum TaskStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'To do',
            self::InProgress => 'In progress',
            self::Done => 'Done',
            self::Cancelled => 'Called off',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Open => 'bg-slate-100 text-slate-700',
            self::InProgress => 'bg-sky-100 text-sky-800',
            self::Done => 'bg-emerald-100 text-emerald-800',
            self::Cancelled => 'bg-rose-100 text-rose-800',
        };
    }

    /** Still to be done: open or being worked. */
    public function isLive(): bool
    {
        return $this === self::Open || $this === self::InProgress;
    }

    /** @return list<string> */
    public static function liveValues(): array
    {
        return [self::Open->value, self::InProgress->value];
    }
}

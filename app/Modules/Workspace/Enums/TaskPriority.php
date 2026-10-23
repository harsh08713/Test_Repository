<?php

namespace App\Modules\Workspace\Enums;

enum TaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Low => 'bg-slate-100 text-slate-600',
            self::Normal => 'bg-sky-100 text-sky-800',
            self::High => 'bg-amber-100 text-amber-800',
            self::Urgent => 'bg-rose-100 text-rose-800',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $p) => [$p->value => $p->label()])->all();
    }
}

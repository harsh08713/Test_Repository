<?php

namespace App\Modules\Workspace\Enums;

use Illuminate\Support\Carbon;

enum Recurrence: string
{
    case None = 'none';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Does not repeat',
            self::Daily => 'Every day',
            self::Weekly => 'Every week',
            self::Monthly => 'Every month',
        };
    }

    /** The moment after $from that the next one falls due (a month never overflows: 31 Jan → 28 Feb). */
    public function next(Carbon $from): ?Carbon
    {
        return match ($this) {
            self::None => null,
            self::Daily => $from->copy()->addDay(),
            self::Weekly => $from->copy()->addWeek(),
            self::Monthly => $from->copy()->addMonthNoOverflow(),
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $r) => [$r->value => $r->label()])->all();
    }
}

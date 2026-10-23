<?php

namespace App\Modules\Sales\Enums;

/**
 * Where a sales order stands once it exists (whether it has been approved is the separate `approval_status`): Open until it is invoiced,
 * Invoiced once its invoice has been made, Cancelled if it was withdrawn. Only Open can change; an order that is Invoiced or Cancelled is
 * what it was.
 */
enum SalesOrderState: string
{
    case Open = 'open';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Invoiced => 'Invoiced',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Open => 'bg-sky-100 text-sky-800',
            self::Invoiced => 'bg-emerald-100 text-emerald-800',
            self::Cancelled => 'bg-rose-100 text-rose-800',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}

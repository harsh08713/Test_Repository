<?php

namespace App\Modules\Reports\Support;

use Carbon\CarbonImmutable;

/**
 * "Last month", "the last 7 days": a date range worked out **when a scheduled report runs**, not when it was set up — a report scheduled with fixed dates would say the same thing every week.
 */
final class RelativeRange
{
    public const RANGES = [
        'none' => 'No date filter (the report’s own default)', 'yesterday' => 'Yesterday', 'last_7_days' => 'The last 7 days', 'last_30_days' => 'The last 30 days', 'this_month' => 'This month so far',
        'last_month' => 'Last month', 'this_year' => 'This financial year so far', 'last_year' => 'Last financial year',
    ];

    /** @return array{from: string, to: string}|null null for "none" */
    public static function resolve(string $range, ?CarbonImmutable $today = null): ?array
    {
        $today ??= CarbonImmutable::today();
        $day = fn (CarbonImmutable $d) => $d->toDateString();

        return match ($range) {
            'yesterday' => ['from' => $day($today->subDay()), 'to' => $day($today->subDay())],
            'last_7_days' => ['from' => $day($today->subDays(7)), 'to' => $day($today->subDay())],
            'last_30_days' => ['from' => $day($today->subDays(30)), 'to' => $day($today->subDay())],
            'this_month' => ['from' => $day($today->startOfMonth()), 'to' => $day($today)],
            'last_month' => ['from' => $day($today->subMonthNoOverflow()->startOfMonth()), 'to' => $day($today->subMonthNoOverflow()->endOfMonth())],
            'this_year' => ['from' => $day(self::yearStart($today)), 'to' => $day($today)],
            'last_year' => ['from' => $day(self::yearStart($today)->subYear()), 'to' => $day(self::yearStart($today)->subDay())],
            default => null,
        };
    }

    /** 1 April of the financial year that holds the date. */
    private static function yearStart(CarbonImmutable $today): CarbonImmutable
    {
        return $today->month >= 4 ? $today->setDate($today->year, 4, 1) : $today->setDate($today->year - 1, 4, 1);
    }
}

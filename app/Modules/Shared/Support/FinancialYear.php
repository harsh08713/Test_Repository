<?php

namespace App\Modules\Shared\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Indian financial year helpers (April–March by default; the start month is a company setting). */
final class FinancialYear
{
    /** The calendar year in which the financial year containing $date started. */
    public static function startYear(CarbonInterface $date, int $startMonth = 4): int
    {
        return $date->month >= $startMonth ? $date->year : $date->year - 1;
    }

    /** Short label used in document numbers, e.g. 15-Jun-2026 => "26-27". */
    public static function label(CarbonInterface $date, int $startMonth = 4): string
    {
        $start = self::startYear($date, $startMonth);

        return sprintf('%02d-%02d', $start % 100, ($start + 1) % 100);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} first and last day of the financial year containing $date */
    public static function range(CarbonInterface $date, int $startMonth = 4): array
    {
        $from = CarbonImmutable::create(self::startYear($date, $startMonth), $startMonth, 1)->startOfDay();

        return [$from, $from->addYear()->subDay()->endOfDay()];
    }
}

<?php

namespace App\Modules\CRM\Enums;

use App\Modules\Shared\Support\FinancialYear;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The length of a target: a calendar month, or a quarter of the company's financial year (three months counted from
 * the month the year starts — April by default, so Q1 is April–June).
 */
enum TargetPeriod: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
        };
    }

    /**
     * First and last day of the period that contains $date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bounds(CarbonInterface $date, int $financialYearStartMonth = 4): array
    {
        $date = CarbonImmutable::instance($date)->startOfDay();

        if ($this === self::Monthly) {
            return [$date->startOfMonth(), $date->endOfMonth()->startOfDay()];
        }

        $offset = ($date->month - $financialYearStartMonth + 12) % 12;
        $start = $date->startOfMonth()->subMonths($offset % 3);

        return [$start, $start->addMonths(3)->subDay()];
    }

    /** "October 2026", or "Q3 FY26-27 (Oct–Dec 2026)". */
    public function describe(CarbonInterface $start, int $financialYearStartMonth = 4): string
    {
        if ($this === self::Monthly) {
            return $start->format('F Y');
        }

        $quarter = intdiv(($start->month - $financialYearStartMonth + 12) % 12, 3) + 1;
        $end = CarbonImmutable::instance($start)->addMonths(2);
        $span = $start->format('M').'–'.$end->format('M Y');

        return sprintf('Q%d FY%s (%s)', $quarter, FinancialYear::label($start, $financialYearStartMonth), $span);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}

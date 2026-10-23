<?php

namespace App\Modules\Shared\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Indian display formats: DD-MM-YYYY dates and lakh/crore digit grouping for rupee amounts. */
final class Format
{
    public static function date(CarbonInterface|string|null $value): string
    {
        return self::carbon($value)?->format(config('formezy.display.date', 'd-m-Y')) ?? '';
    }

    public static function dateTime(CarbonInterface|string|null $value): string
    {
        return self::carbon($value)?->format(config('formezy.display.datetime', 'd-m-Y H:i')) ?? '';
    }

    /** 1234567.5 => "12,34,567.50" (no currency symbol). */
    public static function number(float|int|string|null $value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $negative = (float) $value < 0;
        [$integer, $fraction] = array_pad(explode('.', number_format(abs((float) $value), $decimals, '.', '')), 2, '');

        if (strlen($integer) > 3) {
            $head = substr($integer, 0, -3);
            $integer = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $head).','.substr($integer, -3);
        }

        return ($negative ? '-' : '').$integer.($decimals > 0 ? '.'.$fraction : '');
    }

    /** 1234567.5 => "₹12,34,567.50" */
    public static function inr(float|int|string|null $value, int $decimals = 2): string
    {
        $formatted = self::number($value, $decimals);

        return $formatted === '' ? '' : (str_starts_with($formatted, '-') ? '-₹'.substr($formatted, 1) : '₹'.$formatted);
    }

    private static function carbon(CarbonInterface|string|null $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof CarbonInterface ? $value : Carbon::parse($value);
    }
}

<?php

namespace App\Modules\Analytics\Support;

/** How the analytics screens print figures. Display only: nothing here is used to calculate. */
final class Fmt
{
    /** ₹ with Indian grouping and no paise: ₹12,34,567 */
    public static function inr(?string $amount): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        $negative = str_starts_with($amount, '-');
        $whole = explode('.', ltrim($amount, '-'))[0];
        $last3 = substr($whole, -3);
        $rest = substr($whole, 0, -3);
        $grouped = $rest === '' ? $last3 : preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest).','.$last3;

        return ($negative ? '-' : '').'₹'.$grouped;
    }

    /** ₹1.25 Cr, ₹12.5 L, ₹45,000 — for cards, where room is short. */
    public static function compact(?string $amount): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        $abs = ltrim($amount, '-');
        $sign = str_starts_with($amount, '-') ? '-' : '';

        if (bccomp($abs, '10000000', 2) >= 0) {
            return $sign.'₹'.rtrim(rtrim(bcdiv($abs, '10000000', 2), '0'), '.').' Cr';
        }

        if (bccomp($abs, '100000', 2) >= 0) {
            return $sign.'₹'.rtrim(rtrim(bcdiv($abs, '100000', 2), '0'), '.').' L';
        }

        return self::inr($amount);
    }

    public static function pct(?string $value, int $places = 1): string
    {
        return $value === null ? '—' : number_format((float) $value, $places).'%';
    }

    /** +12.4% / −3.1% / — (no earlier figure to compare with) */
    public static function change(?string $value): string
    {
        if ($value === null) {
            return '—';
        }

        return (bccomp($value, '0', 2) >= 0 ? '+' : '−').number_format(abs((float) $value), 1).'%';
    }
}

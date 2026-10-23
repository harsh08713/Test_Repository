<?php

namespace App\Modules\CRM\Support;

/** Distances on the earth and what counts as a real position. */
final class GeoMath
{
    /** Mean earth radius in metres (IUGG). */
    private const EARTH_RADIUS_M = 6371008.8;

    /** Great-circle distance between two positions in metres, to one decimal (haversine). */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round(2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a))), 1);
    }

    /** A finite number from a number or a numeric string; anything else (text, arrays, booleans, NaN) is null. */
    public static function number(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = trim($value);

            if ($value === '' || ! is_numeric($value)) {
                return null;
            }
        } elseif (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    /**
     * Is this a position on the earth? Latitude −90…90, longitude −180…180. Exactly 0, 0 is refused as well: it is
     * what a device reports when it has no fix at all, and a spot in the Gulf of Guinea is nobody's route.
     */
    public static function isPosition(?float $lat, ?float $lng): bool
    {
        if ($lat === null || $lng === null) {
            return false;
        }

        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && ! ($lat === 0.0 && $lng === 0.0);
    }
}

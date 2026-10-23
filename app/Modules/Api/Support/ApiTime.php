<?php

namespace App\Modules\Api\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Times on the wire. The API speaks ISO 8601 with an offset (`2026-10-20T11:00:00+05:30`); the application works in the
 * company's timezone (`app.timezone`, Asia/Kolkata). A time sent **without** an offset is read as company wall-clock time —
 * what a person typing "11:00" means — and everything returned carries its offset, so no client has to guess.
 */
final class ApiTime
{
    /** A date and time from ISO 8601 (or "YYYY-MM-DD HH:MM[:SS]", or a bare date), in the application timezone — or null when it is not one. */
    public static function parse(mixed $value): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        if ($text === '' || preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?)?(Z|[+-]\d{2}:?\d{2})?$/', $text) !== 1) {
            return null;
        }

        try {
            $parsed = Carbon::parse($text, (string) config('app.timezone'))->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        // Carbon rolls 2026-02-31 over to March: refuse it rather than read the wrong day.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $day) === 1 && ! checkdate((int) $day[2], (int) $day[3], (int) $day[1])) {
            return null;
        }

        return $parsed;
    }

    /** The wall-clock string the follow-up services take ("2026-10-20 11:00"), or null when $value is not a time. */
    public static function wall(mixed $value): ?string
    {
        return self::parse($value)?->format('Y-m-d H:i');
    }

    public static function iso(?CarbonInterface $time): ?string
    {
        return $time?->copy()->setTimezone((string) config('app.timezone'))->toIso8601String();
    }
}

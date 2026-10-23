<?php

namespace App\Modules\Integrations\Providers\Concerns;

use Carbon\CarbonImmutable;
use Throwable;

/** Small helpers for reading a provider's loosely typed records: trimmed text or nothing, and times in company time. */
trait ReadsRecords
{
    /** @param  array<string, mixed>  $record */
    private function text(array $record, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $record[$key] ?? null;

            if (is_string($value) || is_int($value) || is_float($value)) {
                $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

                if ($text !== '' && ! in_array(strtolower($text), ['null', 'none', 'na', 'n/a', '-'], true)) {
                    return $text;
                }
            }
        }

        return null;
    }

    /** A moment as the provider wrote it, read as company (IST) time; null when it is not a date at all. */
    private function moment(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, (string) config('app.timezone'))->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}

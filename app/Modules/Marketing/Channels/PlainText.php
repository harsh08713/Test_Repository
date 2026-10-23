<?php

namespace App\Modules\Marketing\Channels;

/** A line a person can read from something a provider said: no e-mail address, no phone number, no long dump. */
final class PlainText
{
    public static function safe(string $text, int $limit = 160): string
    {
        $text = (string) preg_replace('/\S+@\S+/', '[address]', $text);
        $text = (string) preg_replace('/\+?\d[\d\s-]{8,}\d/', '[number]', $text);

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $text)), 0, $limit);
    }
}

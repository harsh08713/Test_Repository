<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Marketing\Enums\Channel;

/**
 * One spelling for an address, so that "Anil@Example.COM " and "anil@example.com" are one person and "+91 98250-12345" and "9825012345" are one
 * number: e-mail addresses in lower case without spaces, mobile numbers as the last ten digits. Anything that is not an address gives null.
 */
final class ContactNormalizer
{
    public static function email(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false && strlen($value) <= 150 ? $value : null;
    }

    public static function mobile(?string $value): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $value);

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }

    public static function address(Channel $channel, ?string $value): ?string
    {
        return $channel === Channel::Email ? self::email($value) : self::mobile($value);
    }

    /** An address with most of it hidden, for a page anyone with the link can open: "an•••@example.com", "••••••3210". */
    public static function mask(Channel $channel, string $address): string
    {
        if ($channel === Channel::Email) {
            [$local, $domain] = array_pad(explode('@', $address, 2), 2, '');

            return substr($local, 0, 2).str_repeat('•', max(3, strlen($local) - 2)).'@'.$domain;
        }

        return str_repeat('•', max(0, strlen($address) - 4)).substr($address, -4);
    }
}

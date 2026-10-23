<?php

namespace App\Modules\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Indian GSTIN: 2-digit state code + 10-character PAN + entity number + "Z" + check character.
 * The check character is verified (mod-36 Luhn variant), so typos are caught, not just malformed values.
 */
final class Gstin implements ValidationRule
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const PATTERN = '/^(0[1-9]|[12]\d|3[0-8]|97|99)[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    public static function normalise(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }

    public static function hasValidFormat(string $gstin): bool
    {
        return preg_match(self::PATTERN, self::normalise($gstin)) === 1;
    }

    /** The check character the first 14 characters must be followed by. */
    public static function checkCharacter(string $first14): string
    {
        $sum = 0;

        for ($i = 0; $i < 14; $i++) {
            $product = strpos(self::ALPHABET, $first14[$i]) * (($i % 2) === 0 ? 1 : 2);
            $sum += intdiv($product, 36) + ($product % 36);
        }

        return self::ALPHABET[(36 - ($sum % 36)) % 36];
    }

    public static function isValid(string $gstin): bool
    {
        $gstin = self::normalise($gstin);

        return self::hasValidFormat($gstin) && self::checkCharacter(substr($gstin, 0, 14)) === $gstin[14];
    }

    /** The GST state code (first two digits). */
    public static function stateCode(string $gstin): string
    {
        return substr(self::normalise($gstin), 0, 2);
    }

    /** The PAN embedded in a GSTIN (characters 3–12). */
    public static function pan(string $gstin): string
    {
        return substr(self::normalise($gstin), 2, 10);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $gstin = self::normalise((string) $value);

        if (! self::hasValidFormat($gstin)) {
            $fail('The :attribute is not a valid GSTIN (15 characters, e.g. 27AAAAA0000A1Z5).');

            return;
        }

        if (self::checkCharacter(substr($gstin, 0, 14)) !== $gstin[14]) {
            $fail('The :attribute has an incorrect check character — please re-check it against the GST certificate.');
        }
    }
}

<?php

namespace App\Modules\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Permanent Account Number: 5 letters, 4 digits, 1 letter; the 4th letter is the holder type. */
final class Pan implements ValidationRule
{
    public static function isValid(string $pan): bool
    {
        return preg_match('/^[A-Z]{3}[ABCFGHLJPT][A-Z]\d{4}[A-Z]$/', strtoupper(trim($pan))) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && $value !== '' && ! self::isValid((string) $value)) {
            $fail('The :attribute is not a valid PAN (e.g. ABCDE1234F).');
        }
    }
}

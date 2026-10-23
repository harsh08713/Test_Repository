<?php

namespace App\Modules\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Corporate Identification Number (21 characters, e.g. U12345GJ2020PTC123456). */
final class Cin implements ValidationRule
{
    public static function isValid(string $cin): bool
    {
        return preg_match('/^[LU]\d{5}[A-Z]{2}\d{4}[A-Z]{3}\d{6}$/', strtoupper(trim($cin))) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && $value !== '' && ! self::isValid((string) $value)) {
            $fail('The :attribute is not a valid CIN (21 characters, e.g. U12345GJ2020PTC123456).');
        }
    }
}

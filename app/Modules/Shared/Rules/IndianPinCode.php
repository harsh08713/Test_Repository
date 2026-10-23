<?php

namespace App\Modules\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Indian postal PIN code: six digits, the first of which is never 0. */
final class IndianPinCode implements ValidationRule
{
    public static function isValid(string $pin): bool
    {
        return preg_match('/^[1-9]\d{5}$/', trim($pin)) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && $value !== '' && ! self::isValid((string) $value)) {
            $fail('The :attribute must be a valid 6-digit PIN code.');
        }
    }
}

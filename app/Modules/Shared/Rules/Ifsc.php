<?php

namespace App\Modules\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Indian Financial System Code: 4 letters, a zero, then 6 letters or digits (e.g. HDFC0001234). */
final class Ifsc implements ValidationRule
{
    public static function isValid(string $ifsc): bool
    {
        return preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', strtoupper(trim($ifsc))) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && $value !== '' && ! self::isValid((string) $value)) {
            $fail('The :attribute is not a valid IFSC (e.g. HDFC0001234).');
        }
    }
}

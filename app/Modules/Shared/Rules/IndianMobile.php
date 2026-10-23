<?php

namespace App\Modules\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** An Indian mobile number: ten digits starting 6–9, optionally written with +91 / 91 / 0 in front and spaces or dashes. */
final class IndianMobile implements ValidationRule
{
    /** The bare ten digits, or null when the value is not a valid mobile number. */
    public static function normalise(string $value): ?string
    {
        $digits = preg_replace('/[\s\-()]/', '', trim($value)) ?? '';
        $digits = preg_replace('/^(\+91|91|0)(?=\d{10}$)/', '', $digits) ?? '';

        return preg_match('/^[6-9]\d{9}$/', $digits) === 1 ? $digits : null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && $value !== '' && self::normalise((string) $value) === null) {
            $fail('The :attribute must be a valid 10-digit mobile number.');
        }
    }
}

<?php

namespace App\Modules\Api\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** An ISO 8601 date-time (or a bare date), as ApiTime reads it. */
final class IsoDateTime implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (ApiTime::parse($value) === null) {
            $fail('The :attribute must be a date and time such as 2026-10-20T11:00:00+05:30.');
        }
    }
}

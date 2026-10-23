<?php

namespace App\Modules\Api\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * "A single value, not a list or an object, and not absurdly long." Used for a device's reported position: whether the
 * number is a real position is the geofence's decision (so a nonsense reading is *logged* as one), but an array or a megabyte
 * of text is simply a malformed request.
 */
final class Scalar implements ValidationRule
{
    public function __construct(private readonly int $maxLength = 40) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            $fail('The :attribute must be a single number.');

            return;
        }

        if (strlen((string) $value) > $this->maxLength) {
            $fail("The :attribute may not be longer than {$this->maxLength} characters.");
        }
    }
}

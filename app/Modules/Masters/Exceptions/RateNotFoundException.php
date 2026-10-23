<?php

namespace App\Modules\Masters\Exceptions;

use App\Modules\Masters\Enums\RateFailure;
use RuntimeException;

/**
 * "There is no rate you may use": the domain error RateService raises instead of guessing. The message is meant to
 * be shown to the person; `reason` lets code tell the cases apart. It never contains an amount, so it cannot leak
 * the price of a rate the caller is not allowed to use.
 */
final class RateNotFoundException extends RuntimeException
{
    public function __construct(public readonly RateFailure $reason, string $message)
    {
        parent::__construct($message);
    }
}

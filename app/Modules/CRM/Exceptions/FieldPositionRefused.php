<?php

namespace App\Modules\CRM\Exceptions;

use App\Modules\CRM\Enums\ViolationReason;
use RuntimeException;

/**
 * A reported position was refused: there was no usable route plan, or the reading was no good, or it was outside the
 * geofence. The attempt has already been written to the violation log (`violationId`), under `attemptType`
 * ('attendance', 'lead_save'). The message is meant for the person.
 */
final class FieldPositionRefused extends RuntimeException
{
    public function __construct(string $message, public readonly ViolationReason $reason, public readonly int $violationId, public readonly string $attemptType)
    {
        parent::__construct($message);
    }
}

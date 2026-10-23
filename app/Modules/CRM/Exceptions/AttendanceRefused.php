<?php

namespace App\Modules\CRM\Exceptions;

use App\Modules\CRM\Enums\ViolationReason;
use RuntimeException;

/**
 * Attendance could not be marked. `reason` says why and `violationId` is the log entry the attempt left behind — both
 * are null when the day was simply already marked, which is not a violation. The message is meant for the person
 * (and is what the mobile API will return in its 422 response).
 */
final class AttendanceRefused extends RuntimeException
{
    public function __construct(string $message, public readonly ?ViolationReason $reason = null, public readonly ?int $violationId = null)
    {
        parent::__construct($message);
    }

    public static function alreadyMarked(string $at): self
    {
        return new self("Your attendance for today is already marked ({$at}).");
    }
}

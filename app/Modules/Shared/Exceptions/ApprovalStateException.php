<?php

namespace App\Modules\Shared\Exceptions;

use RuntimeException;

/**
 * An approval action that is not valid for the document's current state
 * (already approved, nothing pending, missing reject comment, not ready to submit...).
 * Authorization failures use Laravel's AuthorizationException instead.
 */
class ApprovalStateException extends RuntimeException
{
    public static function notSubmittable(string $status): self
    {
        return new self("Only draft or rejected documents can be submitted for approval (current status: {$status}).");
    }

    public static function nothingPending(): self
    {
        return new self('This document has no pending approval level.');
    }

    public static function changedSinceSubmission(): self
    {
        return new self('This document was changed after it was submitted, so it cannot be approved as it stands. Reject it so the owner can review and resubmit.');
    }

    public static function commentRequired(): self
    {
        return new self('A comment is required when rejecting a document.');
    }

    public static function notReopenable(string $status): self
    {
        return new self("Only an approved document can be re-opened for editing (current status: {$status}).");
    }

    public static function returnReasonRequired(): self
    {
        return new self('Please say why you are sending this back, so the next person knows what to fix.');
    }

    public static function reasonRequired(): self
    {
        return new self('Please say why you are re-opening this document.');
    }

    /** For models to throw from beforeSubmitForApproval() when prerequisites are missing. */
    public static function notReady(string $reason): self
    {
        return new self($reason);
    }
}

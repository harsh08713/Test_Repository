<?php

namespace App\Modules\Shared\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/** Thrown when a write would put a record into a company/branch the current user is not assigned to. */
class TenantAccessException extends AuthorizationException
{
    public static function company(?int $companyId): self
    {
        return new self('You do not have access to company #'.($companyId ?? 'null').'.');
    }

    public static function location(?int $locationId): self
    {
        return new self('You do not have access to location #'.($locationId ?? 'null').'.');
    }

    public static function mismatch(string $message): self
    {
        return new self($message);
    }
}

<?php

namespace App\Modules\Integrations\Exceptions;

use RuntimeException;

/**
 * A provider could not be used right now. `kind` says what to do about it:
 *
 *  - `auth`         the key was refused or has expired — retrying is pointless until a person sets a new one;
 *  - `rate_limited` we asked too often — wait `retryAfterMinutes`;
 *  - `invalid`      the request itself was wrong (a window the provider will not serve) — a bug to look at, not to retry;
 *  - `unavailable`  the provider, or the network, failed — try again later.
 *
 * The message is written for a person and **never** carries the key, the address that was called (the key is in it) or the
 * provider's raw reply.
 */
final class ProviderException extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message, public readonly int $retryAfterMinutes = 15)
    {
        parent::__construct($message);
    }

    public static function auth(string $message): self
    {
        return new self('auth', $message, 60);
    }

    public static function rateLimited(string $message, int $retryAfterMinutes): self
    {
        return new self('rate_limited', $message, $retryAfterMinutes);
    }

    public static function invalid(string $message): self
    {
        return new self('invalid', $message, 60);
    }

    public static function unavailable(string $message): self
    {
        return new self('unavailable', $message, 15);
    }
}

<?php

namespace App\Modules\Marketing\Channels;

use RuntimeException;

/** A provider refused or could not take a message. The words are safe to show (no key, no address, no path); `retryable` says whether trying again can help. */
final class ChannelException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}

<?php

namespace App\Modules\Integrations\Enums;

/** What became of a lead a provider handed over. `failed` is the dead-letter state: the payload is kept and can be retried by hand. */
enum InboundStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Created = 'created';     // a new lead was made
    case Linked = 'linked';       // an open lead already had that mobile number: noted on it, no second lead
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Processing => 'Processing',
            self::Created => 'Lead created',
            self::Linked => 'Added to an existing lead',
            self::Failed => 'Failed',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Received, self::Processing => 'bg-sky-100 text-sky-800',
            self::Created => 'bg-emerald-100 text-emerald-800',
            self::Linked => 'bg-indigo-100 text-indigo-800',
            self::Failed => 'bg-rose-100 text-rose-800',
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Created || $this === self::Linked;
    }
}

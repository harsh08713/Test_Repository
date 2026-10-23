<?php

namespace App\Modules\Sales\Enums;

/**
 * Where one revision of a quotation stands with the customer (whether it has been approved inside the company is a separate
 * thing: `approval_status`).
 *
 * Draft → Sent → Accepted or Declined; a Sent one that is not answered in time becomes Expired. A revision that has been
 * replaced by a newer one is Superseded; one withdrawn is Cancelled. Only a Draft can be edited — every other state is the
 * document as the customer saw it (or as it ended up), so it never changes.
 */
enum QuotationState: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Expired => 'Expired',
            self::Superseded => 'Superseded',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-700',
            self::Sent => 'bg-sky-100 text-sky-800',
            self::Accepted => 'bg-emerald-100 text-emerald-800',
            self::Declined => 'bg-rose-100 text-rose-800',
            self::Expired => 'bg-amber-100 text-amber-800',
            self::Superseded => 'bg-slate-100 text-slate-500',
            self::Cancelled => 'bg-rose-100 text-rose-700',
        };
    }

    /** Still a live offer to the customer (or about to be): not ended, not replaced. */
    public function isLive(): bool
    {
        return in_array($this, [self::Draft, self::Sent, self::Accepted], true);
    }

    /** Nothing more can happen to this revision except being replaced by a newer one (declined and expired may be revised). */
    public function isFinal(): bool
    {
        return in_array($this, [self::Superseded, self::Cancelled], true);
    }

    /** May a new revision be made from a revision in this state? */
    public function canBeRevised(): bool
    {
        return in_array($this, [self::Sent, self::Declined, self::Expired], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}

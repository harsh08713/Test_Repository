<?php

namespace App\Modules\Marketing\Enums;

/** Where a message goes. Only e-mail has a transport today; the others are recorded and wait for a provider (Phase 16). */
enum Channel: string
{
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'E-mail',
            self::WhatsApp => 'WhatsApp',
            self::Sms => 'SMS',
        };
    }

    /** The kind of address a contact needs for this channel. */
    public function addressLabel(): string
    {
        return $this === self::Email ? 'e-mail address' : 'mobile number';
    }

    /** Does this channel go through the installation's own mail transport? WhatsApp and SMS depend on the company's connection: ask ChannelRegistry::isConnected(). */
    public function isConnected(): bool
    {
        return $this === self::Email;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}

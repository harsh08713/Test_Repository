<?php

namespace App\Modules\CRM\Enums;

/** How a follow-up is (to be) done. */
enum FollowupType: string
{
    case Call = 'call';
    case Visit = 'visit';
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case Meeting = 'meeting';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::Visit => 'Visit',
            self::Email => 'E-mail',
            self::WhatsApp => 'WhatsApp',
            self::Meeting => 'Meeting',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}

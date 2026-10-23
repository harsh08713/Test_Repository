<?php

namespace App\Modules\CRM\Enums;

/** What a stop on a route plan refers to. Only the label is needed; a reference ties it to a master. */
enum StopType: string
{
    case Area = 'area';
    case Block = 'block';
    case PinCode = 'pin_code';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Area => 'Area',
            self::Block => 'Block',
            self::PinCode => 'PIN code',
            self::Customer => 'Customer',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}

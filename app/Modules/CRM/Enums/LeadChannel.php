<?php

namespace App\Modules\CRM\Enums;

/**
 * How a lead got into the system — which is not the same as its *source* (where the business came from). A reference
 * can be typed in by hand or arrive in a spreadsheet: the source is "Reference" both times, the channel differs.
 */
enum LeadChannel: string
{
    case Manual = 'manual';
    case Import = 'import';
    case Field = 'field';           // the mobile app, against a route plan (Phase 8)
    case IndiaMart = 'indiamart';   // Phase 9
    case JustDial = 'justdial';     // Phase 9
    case Website = 'website';       // Phase 16

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Typed in',
            self::Import => 'Spreadsheet import',
            self::Field => 'Field (mobile)',
            self::IndiaMart => 'IndiaMART',
            self::JustDial => 'Just Dial',
            self::Website => 'Website form',
        };
    }
}

<?php

namespace App\Modules\CRM\Enums;

/**
 * What a piece of a territory's coverage points at. The more specific kind wins when a place is looked up (`rank()`), so a district can belong to
 * one territory while a few of its PIN codes, or one customer, belong to another.
 */
enum CoverageKind: string
{
    case State = 'state';
    case District = 'district';
    case Block = 'block';
    case PinCode = 'pin_code';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::State => 'State',
            self::District => 'District',
            self::Block => 'Block',
            self::PinCode => 'PIN code',
            self::Customer => 'Customer',
        };
    }

    /** The column of `territory_areas` that holds the reference. */
    public function column(): string
    {
        return match ($this) {
            self::State => 'state_id',
            self::District => 'district_id',
            self::Block => 'block_id',
            self::PinCode => 'pin_code',
            self::Customer => 'ledger_id',
        };
    }

    /** Higher is more specific. */
    public function rank(): int
    {
        return match ($this) {
            self::State => 1,
            self::District => 2,
            self::Block => 3,
            self::PinCode => 4,
            self::Customer => 5,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $kind) => $kind->value, self::cases());
    }
}

<?php

namespace App\Modules\Masters\Services;

use App\Modules\Masters\Exceptions\UomException;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\ItemUom;
use App\Modules\Masters\Models\Unit;

/**
 * Converts quantities between an item's units: its base unit and the alternative units on its conversion table
 * ("1 Box = 12 Nos" means one Box is 12 base units).
 *
 * Deterministic by construction — the same inputs always give the same text, on every machine:
 *  - all arithmetic is exact decimal (bcmath), never floating point; quantities go in and come out as strings;
 *  - a conversion is `quantity × factor(from) ÷ factor(to)`, worked out at 12 decimals and then rounded
 *    **half up** to the 3 decimals a stored quantity has;
 *  - a unit that cannot be split (Nos, Box…) only ever yields a whole number: a result that is not exactly whole is
 *    refused, never rounded, so no stock quietly appears or vanishes;
 *  - an unknown unit, a negative or over-precise quantity is an error, not a guess.
 */
final class UomConverter
{
    /** Decimal places of a stored quantity. */
    public const QUANTITY_SCALE = 3;

    /** Working precision for the division. */
    private const WORK_SCALE = 12;

    /** How many base units are in one of $unit (6 decimals); 1 for the base unit itself. */
    public function factor(Item $item, Unit|int $unit): string
    {
        $unitId = $unit instanceof Unit ? $unit->getKey() : $unit;

        if ($unitId === $item->base_unit_id) {
            return '1.000000';
        }

        $row = $item->uoms->firstWhere('unit_id', $unitId);

        if (! $row instanceof ItemUom) {
            throw UomException::unknownUnit($item->name, $unit instanceof Unit ? $unit->name : "unit #{$unitId}");
        }

        return $row->factor;
    }

    /** A quantity of $from, expressed in the item's base unit. */
    public function toBase(Item $item, string|int $quantity, Unit $from): string
    {
        return $this->convert($item, $quantity, $from, $item->baseUnit);
    }

    /** A quantity of base units, expressed in $to. */
    public function fromBase(Item $item, string|int $baseQuantity, Unit $to): string
    {
        return $this->convert($item, $baseQuantity, $item->baseUnit, $to);
    }

    /**
     * @return string the quantity in $to, with exactly three decimals (e.g. "2.500")
     *
     * @throws UomException
     */
    public function convert(Item $item, string|int $quantity, Unit $from, Unit $to): string
    {
        $quantity = $this->normalise($quantity);
        $this->assertWhole($quantity, $from);

        $product = bcmul($quantity, $this->factor($item, $from), self::QUANTITY_SCALE + 6);
        $exact = bcdiv($product, $this->factor($item, $to), self::WORK_SCALE);

        if (! $to->decimal_allowed && ! $this->isWhole($exact)) {
            throw UomException::fractional($this->trim($exact), $to->name);
        }

        return $this->roundHalfUp($exact);
    }

    /** Is this a quantity the unit allows? (Whole numbers only for a unit that cannot be split.) */
    public function accepts(Unit $unit, string|int $quantity): bool
    {
        try {
            $this->assertWhole($this->normalise($quantity), $unit);

            return true;
        } catch (UomException) {
            return false;
        }
    }

    /** The quantity as a canonical 3-decimal string, or an error. */
    public function normalise(string|int $quantity): string
    {
        $text = trim((string) $quantity);

        if (preg_match('/^\d+(\.\d{1,3})?$/', $text) !== 1) {
            throw UomException::invalidQuantity($text);
        }

        return bcadd($text, '0', self::QUANTITY_SCALE);
    }

    private function assertWhole(string $quantity, Unit $unit): void
    {
        if (! $unit->decimal_allowed && ! $this->isWhole($quantity)) {
            throw UomException::fractional($this->trim($quantity), $unit->name);
        }
    }

    private function isWhole(string $number): bool
    {
        return bccomp(bcadd($number, '0', 0), $number, self::WORK_SCALE) === 0;
    }

    /** Round half up (away from zero; quantities are never negative) to three decimals. */
    private function roundHalfUp(string $number): string
    {
        // bcadd truncates, so adding half a unit of the last place first turns the truncation into "round half up"
        return bcadd(bcadd($number, '0.0005', self::WORK_SCALE), '0', self::QUANTITY_SCALE);
    }

    /** "2.500" → "2.5", "3.000" → "3". */
    private function trim(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}

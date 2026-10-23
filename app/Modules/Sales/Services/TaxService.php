<?php

namespace App\Modules\Sales\Services;

use InvalidArgumentException;

/**
 * GST on a sales document, in one place: whether a supply is within the state or between states, the tax of a line and the
 * totals of a document. Pure arithmetic on decimal strings (bcmath) — no database, no floats — so the quotation today and the
 * order and the invoice after it all work a figure out the same way.
 *
 *  - **Place of supply.** The tax goes to the state the goods are supplied *to*. If that is the supplier's own state (the GST
 *    state codes are equal) the supply is **intra-state** and the tax is split into half CGST and half SGST; otherwise it is
 *    **inter-state** and the whole of it is IGST.
 *  - **A line.** gross = quantity × rate, discount = gross × discount %, taxable = gross − discount, tax = taxable × GST rate.
 *    Every money figure is rounded **half up to paise** where it is worked out. For an intra-state line CGST and SGST are
 *    each worked out from half the rate and rounded on their own (so they are always equal to each other).
 *  - **A document.** The lines' figures are added up as they are; the grand total is then rounded to the **nearest rupee**
 *    (half up) and the difference is shown as the round-off, so the total is a whole-rupee amount that can be paid.
 */
final class TaxService
{
    public const INTRA = 'intra';

    public const INTER = 'inter';

    /** Per cent bounds: a line's discount and its GST rate. */
    public const MAX_PERCENT = '100';

    /**
     * Is a supply from `$supplierStateCode` to `$placeOfSupplyStateCode` between two states? Both are two-digit GST state codes;
     * a code that is missing is an error, never a guess — the place of supply decides which tax is charged.
     *
     * @throws InvalidArgumentException
     */
    public function supplyType(?string $supplierStateCode, ?string $placeOfSupplyStateCode): string
    {
        $from = $this->code($supplierStateCode, 'The supplier\'s state');
        $to = $this->code($placeOfSupplyStateCode, 'The place of supply');

        return $from === $to ? self::INTRA : self::INTER;
    }

    /**
     * The figures of one line.
     *
     * @param  string  $quantity  up to 3 decimals
     * @param  string  $rate  up to 4 decimals
     * @param  string  $discountPercent  0–100, up to 2 decimals
     * @param  string  $gstRate  0–100, up to 2 decimals
     * @param  string  $supplyType  self::INTRA or self::INTER
     * @return array{gross: string, discount: string, taxable: string, cgst: string, sgst: string, igst: string, tax: string, total: string}
     *
     * @throws InvalidArgumentException
     */
    public function line(string $quantity, string $rate, string $discountPercent, string $gstRate, string $supplyType): array
    {
        $this->assertNumber($quantity, 'quantity');
        $this->assertNumber($rate, 'rate');
        $this->assertPercent($discountPercent, 'discount');
        $this->assertPercent($gstRate, 'GST rate');

        if (! in_array($supplyType, [self::INTRA, self::INTER], true)) {
            throw new InvalidArgumentException('The supply type is not known.');
        }

        $gross = $this->paise(bcmul($quantity, $rate, 7));
        $discount = $this->paise(bcdiv(bcmul($gross, $discountPercent, 4), '100', 8));
        $taxable = bcsub($gross, $discount, 2);

        $cgst = $sgst = $igst = '0.00';

        if ($supplyType === self::INTRA) {
            $half = bcdiv($gstRate, '2', 3);
            $cgst = $sgst = $this->paise(bcdiv(bcmul($taxable, $half, 6), '100', 8));
        } else {
            $igst = $this->paise(bcdiv(bcmul($taxable, $gstRate, 6), '100', 8));
        }

        $tax = bcadd(bcadd($cgst, $sgst, 2), $igst, 2);

        return [
            'gross' => $gross, 'discount' => $discount, 'taxable' => $taxable,
            'cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst, 'tax' => $tax, 'total' => bcadd($taxable, $tax, 2),
        ];
    }

    /**
     * The totals of a document from the figures of its lines (as `line()` returns them).
     *
     * @param  list<array{gross: string, discount: string, taxable: string, cgst: string, sgst: string, igst: string, total: string}>  $lines
     * @return array{gross: string, discount: string, taxable: string, cgst: string, sgst: string, igst: string, tax: string, sum: string, round_off: string, grand_total: string}
     */
    public function document(array $lines): array
    {
        $t = ['gross' => '0.00', 'discount' => '0.00', 'taxable' => '0.00', 'cgst' => '0.00', 'sgst' => '0.00', 'igst' => '0.00', 'sum' => '0.00'];

        foreach ($lines as $line) {
            $t['gross'] = bcadd($t['gross'], $line['gross'], 2);
            $t['discount'] = bcadd($t['discount'], $line['discount'], 2);
            $t['taxable'] = bcadd($t['taxable'], $line['taxable'], 2);
            $t['cgst'] = bcadd($t['cgst'], $line['cgst'], 2);
            $t['sgst'] = bcadd($t['sgst'], $line['sgst'], 2);
            $t['igst'] = bcadd($t['igst'], $line['igst'], 2);
            $t['sum'] = bcadd($t['sum'], $line['total'], 2);
        }

        $grand = bcadd($t['sum'], '0.5', 0);   // nearest rupee, half up (the sum is never negative)

        return [
            ...$t,
            'tax' => bcadd(bcadd($t['cgst'], $t['sgst'], 2), $t['igst'], 2),
            'round_off' => bcsub($grand, $t['sum'], 2),
            'grand_total' => bcadd($grand, '0', 2),
        ];
    }

    /** Round a non-negative decimal string half up to two places. */
    public function paise(string $amount): string
    {
        return bcadd($amount, '0.005', 2);
    }

    private function code(?string $code, string $what): string
    {
        $code = trim((string) $code);

        if (preg_match('/^\d{2}$/', $code) !== 1) {
            throw new InvalidArgumentException("{$what} has no GST state code, so the tax cannot be worked out.");
        }

        return $code;
    }

    private function assertNumber(string $value, string $what): void
    {
        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("The {$what} must be a number that is not negative.");
        }
    }

    private function assertPercent(string $value, string $what): void
    {
        $this->assertNumber($value, $what);

        if (bccomp($value, self::MAX_PERCENT, 4) > 0) {
            throw new InvalidArgumentException("The {$what} cannot be more than 100 per cent.");
        }
    }
}

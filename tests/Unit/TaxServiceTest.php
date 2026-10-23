<?php

use App\Modules\Sales\Services\TaxService;

/*
 * GST on a sales document: which tax applies by place of supply, the figures of a line (rounded half up to paise at each step) and
 * the totals of a document (rounded to the rupee, with the difference shown). Pure arithmetic: no database.
 */

beforeEach(function () {
    $this->tax = new TaxService;
});

it('charges CGST + SGST inside the supplier’s state and IGST between states', function () {
    expect($this->tax->supplyType('24', '24'))->toBe('intra')
        ->and($this->tax->supplyType('24', '27'))->toBe('inter')
        ->and($this->tax->supplyType(' 27', '27'))->toBe('intra');
});

it('refuses to guess the tax when a state code is missing', function (?string $supplier, ?string $place) {
    expect(fn () => $this->tax->supplyType($supplier, $place))->toThrow(InvalidArgumentException::class);
})->with([[null, '24'], ['24', null], ['', '24'], ['GJ', '24'], ['24', '2']]);

it('works out a line within the state: half the rate each as CGST and SGST', function () {
    // 10 × 125.50 = 1,255.00; 18% = 225.90, so 112.95 each
    $line = $this->tax->line('10', '125.50', '0', '18', 'intra');

    expect($line)->toBe([
        'gross' => '1255.00', 'discount' => '0.00', 'taxable' => '1255.00', 'cgst' => '112.95', 'sgst' => '112.95', 'igst' => '0.00', 'tax' => '225.90', 'total' => '1480.90',
    ]);
});

it('works out a line between states: the whole rate as IGST', function () {
    $line = $this->tax->line('10', '125.50', '0', '18', 'inter');

    expect($line['cgst'])->toBe('0.00')->and($line['sgst'])->toBe('0.00')->and($line['igst'])->toBe('225.90')->and($line['total'])->toBe('1480.90');
});

it('takes the discount off before the tax and rounds half up to paise', function () {
    // 2.5% of 1,255.00 = 31.375 → 31.38; taxable 1,223.62; 18% = 220.2516 → 220.25
    $line = $this->tax->line('10', '125.50', '2.5', '18', 'inter');

    expect($line['discount'])->toBe('31.38')->and($line['taxable'])->toBe('1223.62')->and($line['igst'])->toBe('220.25')->and($line['total'])->toBe('1443.87');
});

it('rounds half up, never to even: 3 × 0.335 is 1.01', function () {
    expect($this->tax->line('3', '0.335', '0', '0', 'intra')['gross'])->toBe('1.01')
        ->and($this->tax->line('1', '0.004', '0', '0', 'intra')['gross'])->toBe('0.00')
        ->and($this->tax->line('1', '0.005', '0', '0', 'intra')['gross'])->toBe('0.01');
});

it('keeps CGST and SGST equal even when the rate does not halve evenly', function () {
    // 5%: 2.5% each of 100.10 = 2.5025 → 2.50
    $line = $this->tax->line('1', '100.10', '0', '5', 'intra');

    expect($line['cgst'])->toBe('2.50')->and($line['sgst'])->toBe('2.50')->and($line['tax'])->toBe('5.00')->and($line['total'])->toBe('105.10');
});

it('charges nothing on a nil-rated line and nothing on a fully discounted one', function () {
    expect($this->tax->line('5', '40', '0', '0', 'intra')['tax'])->toBe('0.00')
        ->and($this->tax->line('5', '40', '100', '18', 'inter')['total'])->toBe('0.00');
});

it('adds the lines up and rounds the total to the nearest rupee, showing the difference', function () {
    $a = $this->tax->line('10', '125.50', '0', '18', 'intra');            // 1,480.90
    $up = $this->tax->document([$a]);

    expect($up['gross'])->toBe('1255.00')->and($up['taxable'])->toBe('1255.00')->and($up['cgst'])->toBe('112.95')->and($up['tax'])->toBe('225.90')
        ->and($up['sum'])->toBe('1480.90')->and($up['grand_total'])->toBe('1481.00')->and($up['round_off'])->toBe('0.10');

    $b = $this->tax->line('1', '1000.40', '0', '0', 'intra');             // 1,000.40
    $down = $this->tax->document([$b]);

    expect($down['grand_total'])->toBe('1000.00')->and($down['round_off'])->toBe('-0.40');

    $half = $this->tax->document([$this->tax->line('1', '10.50', '0', '0', 'intra')]);   // exactly half a rupee rounds up

    expect($half['grand_total'])->toBe('11.00')->and($half['round_off'])->toBe('0.50');
});

it('sums the lines’ own rounded figures, not the tax of the sum', function () {
    $lines = array_map(fn () => $this->tax->line('1', '0.03', '0', '18', 'inter'), range(1, 3));

    // each line's IGST is 0.0054 → 0.01, so the document carries 0.03 (the lines' sum) although 18% of 0.09 is 0.0162 → 0.02
    expect($this->tax->document($lines)['igst'])->toBe('0.03');
});

it('refuses numbers it cannot work with', function (string $q, string $r, string $d, string $g) {
    expect(fn () => $this->tax->line($q, $r, $d, $g, 'intra'))->toThrow(InvalidArgumentException::class);
})->with([['abc', '1', '0', '18'], ['1', '-1', '0', '18'], ['1', '1', '101', '18'], ['1', '1', '0', '101'], ['1', '', '0', '18']]);

it('refuses a supply type it does not know', function () {
    expect(fn () => $this->tax->line('1', '1', '0', '18', 'export'))->toThrow(InvalidArgumentException::class);
});

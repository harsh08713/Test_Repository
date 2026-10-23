<?php

use App\Modules\Masters\Exceptions\UomException;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\UomConverter;
use App\Modules\Shared\Models\Scopes\Tenancy;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    seedDefaults($this->acme);
    $this->purchase = makeUser(['Purchase User'], [$this->acme]);
    $this->converter = new UomConverter;
});

function unit($company, string $code): Unit
{
    return Unit::withoutGlobalScopes(Tenancy::SCOPES)->findOrFail(unitId($company, $code));
}

/** A counted item: base Nos; 1 Box = 100 Nos, 1 Pair = 2 Nos. */
function countedItem($test): Item
{
    return makeItem($test->acme, $test->purchase, ['item' => ['name' => 'Washer'], 'uoms' => [
        ['id' => '', 'unit_id' => (string) unitId($test->acme, 'BOX'), 'factor' => '100'],
        ['id' => '', 'unit_id' => (string) unitId($test->acme, 'PAIR'), 'factor' => '2'],
    ]]);
}

/** A weighed item: base Gm; 1 Kg = 1000 Gm, 1 Ton = 1,000,000 Gm. */
function weighedItem($test): Item
{
    return makeItem($test->acme, $test->purchase, ['item' => ['name' => 'Copper wire', 'base_unit_id' => (string) unitId($test->acme, 'GM')], 'uoms' => [
        ['id' => '', 'unit_id' => (string) unitId($test->acme, 'KG'), 'factor' => '1000'],
        ['id' => '', 'unit_id' => (string) unitId($test->acme, 'TON'), 'factor' => '1000000'],
    ]]);
}

// ---- factors ---------------------------------------------------------------------------------------------

it('knows how many base units are in each unit of an item', function () {
    $item = countedItem($this);

    expect($this->converter->factor($item, unit($this->acme, 'NOS')))->toBe('1.000000')           // the base unit
        ->and($this->converter->factor($item, unit($this->acme, 'BOX')))->toBe('100.000000')
        ->and($this->converter->factor($item, unitId($this->acme, 'PAIR')))->toBe('2.000000');   // a unit id works too
});

it('refuses a unit the item has no conversion for, naming both', function () {
    $item = countedItem($this);

    expect(fn () => $this->converter->factor($item, unit($this->acme, 'KG')))->toThrow(UomException::class, 'Washer is not set up to be counted in Kilogram');
});

// ---- whole-number units ---------------------------------------------------------------------------------------

it('converts to and from the base unit exactly', function () {
    $item = countedItem($this);
    [$box, $nos, $pair] = [unit($this->acme, 'BOX'), unit($this->acme, 'NOS'), unit($this->acme, 'PAIR')];

    expect($this->converter->toBase($item, 3, $box))->toBe('300.000')
        ->and($this->converter->fromBase($item, 300, $box))->toBe('3.000')
        ->and($this->converter->convert($item, 3, $box, $pair))->toBe('150.000')          // 3 Box = 300 Nos = 150 Pair
        ->and($this->converter->convert($item, '150', $pair, $box))->toBe('3.000')
        ->and($this->converter->toBase($item, 7, $nos))->toBe('7.000');                   // base to base
});

it('refuses a result that is not a whole number of a unit that cannot be split', function () {
    $item = countedItem($this);
    [$box, $nos] = [unit($this->acme, 'BOX'), unit($this->acme, 'NOS')];

    // 250 Nos is 2.5 Box: stock must never appear or vanish by rounding
    expect(fn () => $this->converter->fromBase($item, 250, $box))->toThrow(UomException::class, '2.5 Box is not a whole number')
        ->and(fn () => $this->converter->convert($item, '1', $nos, $box))->toThrow(UomException::class, 'cannot be split')
        ->and($this->converter->fromBase($item, 200, $box))->toBe('2.000');
});

it('refuses a fractional quantity of a unit that cannot be split, on the way in as well', function () {
    $item = countedItem($this);

    expect(fn () => $this->converter->toBase($item, '1.5', unit($this->acme, 'BOX')))->toThrow(UomException::class, '1.5 Box is not a whole number')
        ->and($this->converter->accepts(unit($this->acme, 'BOX'), '2'))->toBeTrue()
        ->and($this->converter->accepts(unit($this->acme, 'BOX'), '2.000'))->toBeTrue()
        ->and($this->converter->accepts(unit($this->acme, 'BOX'), '2.5'))->toBeFalse()
        ->and($this->converter->accepts(unit($this->acme, 'KG'), '2.5'))->toBeTrue();
});

// ---- units that can be split ----------------------------------------------------------------------------------

it('converts weights exactly, in both directions', function () {
    $item = weighedItem($this);
    [$gm, $kg, $ton] = [unit($this->acme, 'GM'), unit($this->acme, 'KG'), unit($this->acme, 'TON')];

    expect($this->converter->toBase($item, '2.5', $kg))->toBe('2500.000')
        ->and($this->converter->fromBase($item, '2500', $kg))->toBe('2.500')
        ->and($this->converter->convert($item, '1', $gm, $kg))->toBe('0.001')
        ->and($this->converter->convert($item, '1.5', $ton, $kg))->toBe('1500.000')
        ->and($this->converter->convert($item, '0.001', $ton, $gm))->toBe('1000.000')
        ->and($this->converter->convert($item, '0', $kg, $gm))->toBe('0.000');
});

it('rounds half up to three decimals, and always the same way', function (string $quantity, string $expected) {
    $item = weighedItem($this);

    // grams → kilograms is ÷1000, so the 4th decimal of the kg result decides the rounding
    expect($this->converter->convert($item, $quantity, unit($this->acme, 'GM'), unit($this->acme, 'KG')))->toBe($expected);
})->with([
    'below half' => ['1.499', '0.001'],     // 0.001499 → 0.001
    'exactly half' => ['1.500', '0.002'],   // 0.0015   → 0.002 (half up)
    'above half' => ['1.501', '0.002'],
    'whole' => ['1000', '1.000'],
    'tiny' => ['0.400', '0.000'],           // 0.0004   → 0.000
    'just half' => ['0.500', '0.001'],      // 0.0005   → 0.001
]);

it('does not drift: the same inputs always give the same text', function () {
    $item = weighedItem($this);
    $results = collect(range(1, 50))->map(fn () => $this->converter->convert($item, '123456.789', unit($this->acme, 'GM'), unit($this->acme, 'TON')));

    expect($results->unique()->all())->toBe(['0.123']);
});

it('keeps exact arithmetic where floating point would not', function () {
    $item = weighedItem($this);

    // 0.1 Kg + 0.2 Kg, one at a time, must equal 0.3 Kg exactly (in floats 0.1 + 0.2 = 0.30000000000000004)
    $a = $this->converter->toBase($item, '0.1', unit($this->acme, 'KG'));
    $b = $this->converter->toBase($item, '0.2', unit($this->acme, 'KG'));

    expect(bcadd($a, $b, 3))->toBe('300.000')
        ->and($this->converter->fromBase($item, bcadd($a, $b, 3), unit($this->acme, 'KG')))->toBe('0.300');
});

it('handles factors with several decimals and a division that does not come out even', function () {
    $metres = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Cable', 'base_unit_id' => (string) unitId($this->acme, 'MTR')], 'uoms' => [
        ['id' => '', 'unit_id' => (string) unitId($this->acme, 'FT'), 'factor' => '0.3048'],
    ]]);
    [$ft, $mtr] = [unit($this->acme, 'FT'), unit($this->acme, 'MTR')];

    expect($this->converter->toBase($metres, '1', $ft))->toBe('0.305')            // 0.3048 → 0.305
        ->and($this->converter->toBase($metres, '100', $ft))->toBe('30.480')
        ->and($this->converter->fromBase($metres, '1', $ft))->toBe('3.281')       // 1 ÷ 0.3048 = 3.28083…  rounds to 3.281
        ->and($this->converter->convert($metres, '10', $mtr, $ft))->toBe('32.808');
});

it('copes with large quantities', function () {
    $item = countedItem($this);

    expect($this->converter->toBase($item, '999999999', unit($this->acme, 'BOX')))->toBe('99999999900.000');
});

// ---- bad input -----------------------------------------------------------------------------------------------

it('refuses quantities that are not plain non-negative numbers with at most three decimals', function (string $bad) {
    $item = weighedItem($this);

    expect(fn () => $this->converter->toBase($item, $bad, unit($this->acme, 'KG')))->toThrow(UomException::class, 'is not a valid quantity');
})->with(['negative' => '-1', 'four decimals' => '1.2345', 'letters' => 'abc', 'exponent' => '1e3', 'empty' => '', 'trailing dot' => '5.', 'comma' => '1,5', 'plus sign' => '+3']);

it('accepts whitespace around a quantity and plain integers', function () {
    $item = weighedItem($this);

    expect($this->converter->toBase($item, ' 2 ', unit($this->acme, 'KG')))->toBe('2000.000')
        ->and($this->converter->normalise(7))->toBe('7.000');
});

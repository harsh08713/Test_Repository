<?php

use App\Modules\Masters\Support\Hierarchy;
use App\Modules\Masters\Support\StateCatalog;
use App\Modules\Shared\Rules\Cin;
use App\Modules\Shared\Rules\Gstin;
use App\Modules\Shared\Rules\IndianPinCode;
use App\Modules\Shared\Rules\Pan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

function passes(object $rule, mixed $value): bool
{
    return Validator::make(['field' => $value], ['field' => [$rule]])->passes();
}

// ---- GSTIN -----------------------------------------------------------------------------------------

it('accepts real GSTINs, including their check character', function (string $gstin) {
    expect(Gstin::isValid($gstin))->toBeTrue()
        ->and(passes(new Gstin, $gstin))->toBeTrue();
})->with(['27AAACR5055K1Z7', '29AAGCB7383J1Z4', '07AAGFF2194N1Z1', '27AAPFU0939F1ZV', '09AAACH7409R1ZZ']);

it('accepts a GSTIN regardless of case and surrounding spaces', function () {
    expect(Gstin::isValid('  27aaacr5055k1z7 '))->toBeTrue();
});

it('rejects a GSTIN with a wrong check character', function () {
    expect(Gstin::isValid('27AAACR5055K1Z8'))->toBeFalse();

    $validator = Validator::make(['gstin' => '27AAACR5055K1Z8'], ['gstin' => [new Gstin]]);
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('gstin'))->toContain('check character');
});

it('rejects malformed GSTINs', function (string $bad) {
    expect(Gstin::isValid($bad))->toBeFalse();
})->with([
    'too short' => ['27AAACR5055K1Z'],
    'too long' => ['27AAACR5055K1Z77'],
    'bad state code' => ['00AAACR5055K1Z7'],
    'state code above 38' => ['45AAACR5055K1Z7'],
    'no Z' => ['27AAACR5055K1A7'],
    'digits where letters belong' => ['2711111 5055K1Z7'],
    'empty' => [''],
]);

it('lets an empty value through so the field stays optional', function () {
    expect(passes(new Gstin, null))->toBeTrue()->and(passes(new Gstin, ''))->toBeTrue();
});

it('reads the state code and PAN out of a GSTIN', function () {
    expect(Gstin::stateCode('27aaacr5055k1z7'))->toBe('27')
        ->and(Gstin::pan('27AAACR5055K1Z7'))->toBe('AAACR5055K');
});

it('computes the check character used to build valid test data', function () {
    expect(Gstin::checkCharacter('27AAACR5055K1Z'))->toBe('7');
});

// ---- PAN / CIN / PIN -----------------------------------------------------------------------------

it('validates PANs including the holder-type letter', function () {
    expect(Pan::isValid('AAACR5055K'))->toBeTrue()          // company
        ->and(Pan::isValid('abcpe1234f'))->toBeTrue()       // individual, lower case
        ->and(Pan::isValid('ABCZE1234F'))->toBeFalse()      // 'Z' is not a holder type
        ->and(Pan::isValid('ABCDE12345'))->toBeFalse()
        ->and(Pan::isValid('ABCDE1234'))->toBeFalse()
        ->and(passes(new Pan, 'ABCPE1234F'))->toBeTrue()
        ->and(passes(new Pan, 'nonsense'))->toBeFalse();
});

it('validates CINs', function () {
    expect(Cin::isValid('U12345GJ2020PTC123456'))->toBeTrue()
        ->and(Cin::isValid('L17110MH1973PLC019786'))->toBeTrue()
        ->and(Cin::isValid('X12345GJ2020PTC123456'))->toBeFalse()   // must start with L or U
        ->and(Cin::isValid('U12345GJ2020PTC12345'))->toBeFalse()
        ->and(passes(new Cin, 'U12345GJ2020PTC123456'))->toBeTrue();
});

it('validates Indian PIN codes', function (string $pin, bool $valid) {
    expect(IndianPinCode::isValid($pin))->toBe($valid)->and(passes(new IndianPinCode, $pin))->toBe($valid);
})->with([
    ['380001', true], ['110001', true], ['999999', true],
    ['012345', false], ['38000', false], ['3800011', false], ['38000A', false], ['38 001', false],
]);

// ---- State catalogue -------------------------------------------------------------------------------

it('lists the 36 states and union territories with unique codes', function () {
    $states = StateCatalog::all();

    expect($states)->toHaveCount(36)
        ->and(array_unique(array_column($states, 'gst_code')))->toHaveCount(36)
        ->and(array_unique(array_column($states, 'code')))->toHaveCount(36)
        ->and(array_unique(array_column($states, 'name')))->toHaveCount(36);

    foreach ($states as $state) {
        expect($state['gst_code'])->toMatch('/^\d{2}$/');
    }
});

it('finds states by GST code or name', function () {
    expect(StateCatalog::findByGstCode('24')['name'])->toBe('Gujarat')
        ->and(StateCatalog::findByGstCode('27')['code'])->toBe('MH')
        ->and(StateCatalog::findByGstCode('25'))->toBeNull()
        ->and(StateCatalog::findByName('  maharashtra ')['gst_code'])->toBe('27')
        ->and(StateCatalog::findByName('Atlantis'))->toBeNull();
});

it('only offers GST codes that the GSTIN rule also accepts', function () {
    foreach (StateCatalog::all() as $state) {
        expect(Gstin::hasValidFormat($state['gst_code'].'AAACR5055K1Z7'))->toBeTrue("{$state['name']} ({$state['gst_code']})");
    }
});

// ---- Hierarchy helpers -----------------------------------------------------------------------------

/** @return Collection<int, Model> id => parent: 1 -> (2 -> (4, 5), 3), 6 (root) */
function nodes(array $parents): Collection
{
    return collect($parents)->map(function ($parent, $id) {
        $m = new class extends Model
        {
            protected $guarded = [];

            public $timestamps = false;
        };

        return $m->forceFill(['id' => $id, 'parent_id' => $parent, 'name' => "n{$id}"]);
    })->values();
}

it('builds a nested tree preserving sibling order', function () {
    $tree = Hierarchy::tree(nodes([1 => null, 2 => 1, 3 => 1, 4 => 2, 5 => 2, 6 => null]));

    expect(array_map(fn ($n) => $n['model']->id, $tree))->toBe([1, 6])
        ->and(array_map(fn ($n) => $n['model']->id, $tree[0]['children']))->toBe([2, 3])
        ->and(array_map(fn ($n) => $n['model']->id, $tree[0]['children'][0]['children']))->toBe([4, 5])
        ->and($tree[0]['children'][0]['children'][0]['depth'])->toBe(2)
        ->and($tree[1]['children'])->toBe([]);
});

it('keeps a node whose parent is missing instead of dropping it', function () {
    $tree = Hierarchy::tree(nodes([1 => null, 9 => 77]));   // 77 does not exist (deleted / not visible)

    expect(array_map(fn ($n) => $n['model']->id, $tree))->toBe([1, 9]);
});

it('finds descendants and ancestors', function () {
    $all = nodes([1 => null, 2 => 1, 3 => 1, 4 => 2, 5 => 4, 6 => null]);

    expect(Hierarchy::descendantIds($all, 1))->toEqualCanonicalizing([2, 3, 4, 5])
        ->and(Hierarchy::descendantIds($all, 4))->toBe([5])
        ->and(Hierarchy::descendantIds($all, 6))->toBe([])
        ->and(Hierarchy::ancestorIds($all, 5))->toBe([4, 2, 1])
        ->and(Hierarchy::ancestorIds($all, 1))->toBe([]);
});

it('detects moves that would create a loop', function () {
    $all = nodes([1 => null, 2 => 1, 3 => 2, 4 => null]);

    expect(Hierarchy::wouldCreateCycle($all, 1, 1))->toBeTrue()          // under itself
        ->and(Hierarchy::wouldCreateCycle($all, 1, 3))->toBeTrue()       // under its own grandchild
        ->and(Hierarchy::wouldCreateCycle($all, 2, 3))->toBeTrue()
        ->and(Hierarchy::wouldCreateCycle($all, 3, 1))->toBeFalse()      // up the tree is fine
        ->and(Hierarchy::wouldCreateCycle($all, 1, 4))->toBeFalse()      // to another tree
        ->and(Hierarchy::wouldCreateCycle($all, 2, null))->toBeFalse();  // to the top
});

it('survives corrupted data that already contains a loop', function () {
    $all = nodes([1 => 2, 2 => 1]);   // 1 and 2 are each other's parent

    expect(Hierarchy::descendantIds($all, 1))->toBeArray()                // terminates
        ->and(Hierarchy::ancestorIds($all, 1))->toBeArray();
});

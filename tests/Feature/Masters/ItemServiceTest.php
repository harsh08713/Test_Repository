<?php

use App\Modules\Masters\Enums\ItemStage;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\ItemAlias;
use App\Modules\Masters\Models\ItemGroup;
use App\Modules\Masters\Models\ItemLocationParameter;
use App\Modules\Masters\Models\ItemStageEntry;
use App\Modules\Masters\Models\ItemUom;
use App\Modules\Masters\Models\Unit;
use App\Modules\Masters\Services\ItemService;
use App\Modules\Masters\Services\ItemWorkflow;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);

    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->purchase = makeUser(['Purchase User'], [$this->acme]);
    $this->inventory = makeUser(['Inventory User'], [$this->acme]);
    $this->ahdOnly = makeUser(['Inventory User'], [$this->acme], [$this->ahd]);   // works in Ahmedabad only
    $this->globexPurchase = makeUser(['Purchase User'], [$this->globex]);
    $this->service = app(ItemService::class);
});

function purchaseErrors($test, array $overrides = [], ?Item $editing = null, $company = null, $by = null): array
{
    $company ??= $test->acme;

    try {
        inCompany($company, fn () => app(ItemService::class)->savePurchaseStage($editing, itemInput($company, $overrides), $by ?? $test->purchase));
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

function inventoryErrors($test, Item $item, array $overrides = [], $by = null): array
{
    try {
        inCompany($test->acme, fn () => app(ItemService::class)->saveInventoryStage($item, inventoryInput($item->name, $overrides), $by ?? $test->inventory));
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

// ======================================================================================================
// Stage 1: Purchase
// ======================================================================================================

it('starts an item in the Purchase stage, as a draft, with no code yet', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => '  MS   Hex  Bolt M10 ', 'hsn_code' => '7318.15', 'po_tolerance_percent' => '5', 'gst_rate' => '18']]);

    expect($item)->name->toBe('MS Hex Bolt M10')->code->toBeNull()->hsn_code->toBe('731815')
        ->po_tolerance_percent->toBe('5.00')->gst_rate->toBe('18.00')
        ->workflow_stage->toBe(ItemStage::PurchaseDraft)->approval_status->toBe(ApprovalStatus::Draft)
        ->company_id->toBe($this->acme->id)->created_by->toBe($this->purchase->id)->status->toBe('active')
        ->and($item->uoms()->count())->toBe(1);

    $entry = ItemStageEntry::query()->where('item_id', $item->id)->sole();
    expect($entry)->event->toBe('created')->from_stage->toBeNull()->to_stage->toBe(ItemStage::PurchaseDraft)->actor_id->toBe($this->purchase->id);
});

it('lets a draft be saved with only what identifies it, ready to be completed later', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['hsn_code' => '', 'gst_rate' => ''], 'uoms' => []]);

    expect($item)->hsn_code->toBeNull()->gst_rate->toBeNull()
        ->and($item->missingForPurchaseStage())->toBe(['an HSN code', 'a GST rate']);
});

it('keeps names unique within a company, ignoring case and spacing', function () {
    makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Hex Bolt']]);

    expect(purchaseErrors($this, ['item' => ['name' => '  hex   BOLT ']]))->toHaveKey('item.name')
        ->and(purchaseErrors($this, ['item' => ['name' => 'Hex Bolt']], null, $this->globex, $this->globexPurchase))->toBe([]);
});

it('requires a name, a group and a base unit, and only accepts active ones from the company', function () {
    $other = itemGroupId($this->globex, 'RM', 'Globex RM');
    $inactive = (new ItemGroup(['code' => 'OLD', 'name' => 'Old', 'status' => 'inactive']))->forceFill(['company_id' => $this->acme->id]);
    $inactive->save();
    Unit::withoutGlobalScopes(Tenancy::SCOPES)->whereKey(unitId($this->acme, 'ROLL'))->update(['status' => 'inactive']);

    expect(purchaseErrors($this, ['item' => ['name' => '']]))->toHaveKey('item.name')
        ->and(purchaseErrors($this, ['item' => ['name' => 'A', 'item_group_id' => '']]))->toHaveKey('item.item_group_id')
        ->and(purchaseErrors($this, ['item' => ['name' => 'B', 'base_unit_id' => '']]))->toHaveKey('item.base_unit_id')
        ->and(purchaseErrors($this, ['item' => ['name' => 'C', 'item_group_id' => (string) $other]]))->toHaveKey('item.item_group_id')
        ->and(purchaseErrors($this, ['item' => ['name' => 'D', 'item_group_id' => (string) $inactive->id]]))->toHaveKey('item.item_group_id')
        ->and(purchaseErrors($this, ['item' => ['name' => 'E', 'base_unit_id' => (string) unitId($this->globex, 'KG')]]))->toHaveKey('item.base_unit_id')
        ->and(purchaseErrors($this, ['item' => ['name' => 'F', 'base_unit_id' => (string) unitId($this->acme, 'ROLL')]]))->toHaveKey('item.base_unit_id')
        ->and(purchaseErrors($this, ['item' => ['name' => 'G', 'item_group_id' => '999999']]))->toHaveKey('item.item_group_id');
});

it('lets an existing item keep a group or unit that has since been deactivated', function () {
    $item = makeItem($this->acme, $this->purchase);
    ItemGroup::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($item->item_group_id)->update(['status' => 'inactive']);
    Unit::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($item->base_unit_id)->update(['status' => 'inactive']);

    expect(purchaseErrors($this, ['item' => ['description' => 'edited'], 'uoms' => []], $item->fresh()))->toBe([]);
});

it('checks the HSN code, the GST rate and the PO tolerance', function (string $field, string $value) {
    expect(purchaseErrors($this, ['item' => ['name' => 'X', $field => $value]]))->toHaveKey("item.{$field}");
})->with([
    'short HSN' => ['hsn_code', '73'],
    'five-digit HSN' => ['hsn_code', '73181'],
    'letters in HSN' => ['hsn_code', '73A8'],
    'GST rate not a slab' => ['gst_rate', '17'],
    'absurd GST rate' => ['gst_rate', '81'],
    'text GST rate' => ['gst_rate', 'eighteen'],
    'negative tolerance' => ['po_tolerance_percent', '-1'],
    'tolerance over 100' => ['po_tolerance_percent', '101'],
    'three-decimal tolerance' => ['po_tolerance_percent', '2.555'],
]);

it('accepts every GST slab in the configured list, and follows the list when it changes', function () {
    foreach ([0, 5, 12, 18, 28, '0.25'] as $i => $rate) {
        expect(purchaseErrors($this, ['item' => ['name' => "Rate {$i}", 'gst_rate' => (string) $rate]]))->toBe([]);
    }

    config(['formezy.gst_rates' => [0, 5, 18]]);
    expect(purchaseErrors($this, ['item' => ['name' => 'Retired slab', 'gst_rate' => '12']]))->toHaveKey('item.gst_rate')
        ->and(purchaseErrors($this, ['item' => ['name' => 'Current slab', 'gst_rate' => '18']]))->toBe([]);
});

it('defaults a blank PO tolerance to zero', function () {
    expect(makeItem($this->acme, $this->purchase, ['item' => ['name' => 'No tolerance', 'po_tolerance_percent' => '']])->po_tolerance_percent)->toBe('0.00');
});

// ---- unit conversions ---------------------------------------------------------------------------------------

it('stores conversions as base units per unit', function () {
    $item = makeItem($this->acme, $this->purchase, ['uoms' => [
        ['id' => '', 'unit_id' => (string) unitId($this->acme, 'BOX'), 'factor' => '12'],
        ['id' => '', 'unit_id' => (string) unitId($this->acme, 'PAIR'), 'factor' => '2.000000'],
    ]]);

    expect($item->uoms()->pluck('factor', 'unit_id')->all())->toBe([unitId($this->acme, 'BOX') => '12.000000', unitId($this->acme, 'PAIR') => '2.000000']);
});

it('refuses conversions that do not make sense', function (array $uoms, string $key) {
    $row = fn (string $unit, string $factor) => ['id' => '', 'unit_id' => (string) unitId($this->acme, $unit), 'factor' => $factor];

    expect(purchaseErrors($this, ['uoms' => array_map(fn ($u) => $row(...$u), $uoms)]))->toHaveKey($key);
})->with([
    'the base unit again' => [[['NOS', '1']], 'uoms.0.unit_id'],
    'the same unit twice' => [[['BOX', '12'], ['BOX', '24']], 'uoms.1.unit_id'],
    'zero' => [[['BOX', '0']], 'uoms.0.factor'],
    'negative' => [[['BOX', '-3']], 'uoms.0.factor'],
    'text' => [[['BOX', 'a dozen']], 'uoms.0.factor'],
    'seven decimals' => [[['BOX', '1.1234567']], 'uoms.0.factor'],
    'half a piece (Nos cannot be split)' => [[['BOX', '2.5']], 'uoms.0.factor'],
]);

it('allows a fractional conversion when the base unit can be split', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Rope', 'base_unit_id' => (string) unitId($this->acme, 'MTR')], 'uoms' => [
        ['id' => '', 'unit_id' => (string) unitId($this->acme, 'FT'), 'factor' => '0.3048'],
    ]]);

    expect($item->uoms()->value('factor'))->toBe('0.304800');
});

it('refuses another company’s unit, an unknown one, and more than ten conversions', function () {
    expect(purchaseErrors($this, ['uoms' => [['id' => '', 'unit_id' => (string) unitId($this->globex, 'BOX'), 'factor' => '12']]]))->toHaveKey('uoms.0.unit_id')
        ->and(purchaseErrors($this, ['item' => ['name' => 'U2'], 'uoms' => [['id' => '', 'unit_id' => '999999', 'factor' => '12']]]))->toHaveKey('uoms.0.unit_id');

    $units = Unit::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('code', '!=', 'NOS')->limit(11)->pluck('id');
    $many = $units->map(fn ($id) => ['id' => '', 'unit_id' => (string) $id, 'factor' => '2'])->all();

    expect(purchaseErrors($this, ['item' => ['name' => 'Many'], 'uoms' => $many]))->toHaveKey('uoms');
});

it('adds, changes and removes conversions on a draft', function () {
    $item = makeItem($this->acme, $this->purchase);
    $box = $item->uoms()->first();

    $input = itemInput($this->acme, ['uoms' => [
        ['id' => (string) $box->id, 'unit_id' => (string) unitId($this->acme, 'BOX'), 'factor' => '50'],
        ['id' => '', 'unit_id' => (string) unitId($this->acme, 'PAIR'), 'factor' => '2'],
    ]]);
    $saved = inCompany($this->acme, fn () => $this->service->savePurchaseStage($item, $input, $this->purchase));
    expect($saved->uoms()->pluck('factor', 'unit_id')->all())->toBe([unitId($this->acme, 'BOX') => '50.000000', unitId($this->acme, 'PAIR') => '2.000000'])
        ->and($saved->uoms()->where('unit_id', unitId($this->acme, 'BOX'))->value('id'))->toBe($box->id);   // same row, updated

    $saved = inCompany($this->acme, fn () => $this->service->savePurchaseStage($saved, itemInput($this->acme, ['uoms' => []]), $this->purchase));
    expect($saved->uoms()->count())->toBe(0)
        ->and(ItemUom::withoutGlobalScopes(Tenancy::SCOPES)->withTrashed()->where('item_id', $item->id)->count())->toBe(0);   // removed outright, so the unit can be added again
});

it('lets a unit leave one row and come back on another in the same save', function () {
    $item = makeItem($this->acme, $this->purchase);
    $box = $item->uoms()->first();

    $input = itemInput($this->acme, ['uoms' => [['id' => '', 'unit_id' => (string) unitId($this->acme, 'BOX'), 'factor' => '24']]]);   // the old Box row is dropped, a new one added
    $saved = inCompany($this->acme, fn () => $this->service->savePurchaseStage($item, $input, $this->purchase));

    expect($saved->uoms()->count())->toBe(1)->and($saved->uoms()->value('factor'))->toBe('24.000000')->and($saved->uoms()->value('id'))->not->toBe($box->id);
});

it('refuses a row id that belongs to another item, and changes nothing', function () {
    $mine = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Mine']]);
    $theirs = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Theirs']]);
    $theirRow = $theirs->uoms()->first();

    $input = itemInput($this->acme, ['item' => ['name' => 'Mine renamed'], 'uoms' => [['id' => (string) $theirRow->id, 'unit_id' => (string) unitId($this->acme, 'BOX'), 'factor' => '1']]]);

    expect(fn () => inCompany($this->acme, fn () => $this->service->savePurchaseStage($mine, $input, $this->purchase)))->toThrow(ValidationException::class)
        ->and($mine->fresh()->name)->toBe('Mine')                                       // the whole save rolled back
        ->and($theirRow->fresh()->factor)->toBe('100.000000');
});

// ---- the stage guard and the lock on approved items ---------------------------------------------------------------

it('will not change the Purchase part of an item that is no longer in the Purchase stage', function (string $upTo) {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => "At {$upTo}"]], $upTo);

    expect(fn () => inCompany($this->acme, fn () => $this->service->savePurchaseStage($item, itemInput($this->acme, ['item' => ['name' => 'Renamed']]), $this->purchase)))
        ->toThrow(ApprovalStateException::class, 'not in the Purchase stage')
        ->and($item->fresh()->name)->not->toBe('Renamed');
})->with(['inventory', 'submitted', 'approved']);

it('freezes the base unit and the existing conversions once the item has been approved, but allows new ones', function () {
    $approved = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Approved item']], 'approved');
    app(ItemWorkflow::class)->reopen($approved, makeUser(['Company Admin'], [$this->acme]), 'Need to correct the HSN', ItemStage::PurchaseDraft);
    $item = $approved->fresh();
    $box = $item->uoms()->first();
    $row = fn ($id, $unit, $factor) => ['id' => $id, 'unit_id' => (string) unitId($this->acme, $unit), 'factor' => $factor];

    $save = fn (array $overrides) => purchaseErrors($this, $overrides, $item);

    expect($save(['item' => ['name' => 'Approved item', 'base_unit_id' => (string) unitId($this->acme, 'PCS')], 'uoms' => [$row((string) $box->id, 'BOX', '100')]]))->toHaveKey('item.base_unit_id')
        ->and($save(['item' => ['name' => 'Approved item'], 'uoms' => [$row((string) $box->id, 'BOX', '50')]]))->toHaveKey('uoms')              // factor changed
        ->and($save(['item' => ['name' => 'Approved item'], 'uoms' => [$row((string) $box->id, 'PAIR', '100')]]))->toHaveKey('uoms')            // unit swapped
        ->and($save(['item' => ['name' => 'Approved item'], 'uoms' => []]))->toHaveKey('uoms')                                                     // removed
        ->and($save(['item' => ['name' => 'Approved item', 'hsn_code' => '731816', 'gst_rate' => '12'], 'uoms' => [$row((string) $box->id, 'BOX', '100'), $row('', 'PAIR', '2')]]))->toBe([]);
});

// ======================================================================================================
// Stage 2: Inventory
// ======================================================================================================

it('assigns the code, aliases and stock levels without moving the item on', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Washer'], 'aliases' => [['id' => '', 'alias' => ' flat  washer '], ['id' => '', 'alias' => 'M10 washer']], 'locations' => [
        ['id' => '', 'location_id' => (string) $this->ahd->id, 'minimum_stock' => '10', 'reorder_level' => '25', 'maximum_stock' => '100'],
        ['id' => '', 'location_id' => (string) $this->mum->id, 'minimum_stock' => '0', 'reorder_level' => '5.000', 'maximum_stock' => '5'],
    ], 'inventory' => ['code' => ' wsh-001 ']], 'inventory');

    expect($item)->code->toBe('WSH-001')->workflow_stage->toBe(ItemStage::InventoryPending)
        ->and($item->aliases()->pluck('alias')->sort()->values()->all())->toBe(['M10 washer', 'flat washer'])
        ->and($item->locationParameters()->count())->toBe(2)
        ->and($item->locationParameters()->where('location_id', $this->ahd->id)->first())->minimum_stock->toBe('10.000')->reorder_level->toBe('25.000')->maximum_stock->toBe('100.000');
});

it('suggests the next free code, never reusing a deleted item’s', function () {
    expect($this->service->suggestCode())->toBe('ITM-00001');

    $item = makeItem($this->acme, $this->purchase, ['inventory' => ['code' => 'ITM-00007']], 'inventory');
    expect(inCompany($this->acme, fn () => $this->service->suggestCode()))->toBe('ITM-00008');

    $item->delete();
    expect(inCompany($this->acme, fn () => $this->service->suggestCode()))->toBe('ITM-00008');
});

it('keeps codes unique within a company and checks their shape', function () {
    makeItem($this->acme, $this->purchase, ['item' => ['name' => 'First'], 'inventory' => ['code' => 'ITM-1']], 'inventory');
    $second = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Second'], 'inventory' => ['code' => 'ITM-2']], 'inventory');

    expect(inventoryErrors($this, $second, ['inventory' => ['code' => 'itm-1']]))->toHaveKey('inventory.code')
        ->and(inventoryErrors($this, $second, ['inventory' => ['code' => 'bad code!']]))->toHaveKey('inventory.code')
        ->and(inventoryErrors($this, $second, ['inventory' => ['code' => '-lead']]))->toHaveKey('inventory.code')
        ->and(inventoryErrors($this, $second, ['inventory' => ['code' => 'ITM-2']]))->toBe([]);   // its own code is fine

    $other = makeItem($this->globex, $this->globexPurchase, ['item' => ['name' => 'Elsewhere'], 'inventory' => ['code' => 'ITM-1']], 'inventory');
    expect($other->code)->toBe('ITM-1');   // the same code in another company
});

it('allows the code to be left for later, but not blank at submission', function () {
    $item = makeItem($this->acme, $this->purchase, ['inventory' => ['code' => '']], 'inventory');

    expect($item->code)->toBeNull()->and($item->missingForApproval())->toBe(['an item code (Inventory assigns it)']);
});

it('freezes the code once the item has been approved', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Frozen']], 'approved');
    app(ItemWorkflow::class)->reopen($item, makeUser(['Company Admin'], [$this->acme]), 'Add an alias', ItemStage::InventoryPending);

    expect(inventoryErrors($this, $item->fresh(), ['inventory' => ['code' => 'NEW-CODE']]))->toHaveKey('inventory.code')
        ->and(inventoryErrors($this, $item->fresh(), ['inventory' => ['code' => $item->code], 'aliases' => [['id' => '', 'alias' => 'new alias']]]))->toBe([]);
});

it('keeps aliases unambiguous', function () {
    $other = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Spring Washer'], 'aliases' => [['id' => '', 'alias' => 'Lock washer']], 'inventory' => ['code' => 'SPR-1']], 'inventory');
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Plain Washer'], 'inventory' => ['code' => 'PLN-1']], 'inventory');
    $alias = fn (string $text) => ['aliases' => [['id' => '', 'alias' => $text]]];

    expect(inventoryErrors($this, $item, $alias('plain washer')))->toHaveKey('aliases.0.alias')            // its own name
        ->and(inventoryErrors($this, $item, $alias('pln-1')))->toHaveKey('aliases.0.alias')                // its own code
        ->and(inventoryErrors($this, $item, $alias('Spring Washer')))->toHaveKey('aliases.0.alias')        // another item's name
        ->and(inventoryErrors($this, $item, $alias('SPR-1')))->toHaveKey('aliases.0.alias')                // another item's code
        ->and(inventoryErrors($this, $item, $alias('LOCK WASHER')))->toHaveKey('aliases.0.alias')          // another item's alias
        ->and(inventoryErrors($this, $item, ['aliases' => [['id' => '', 'alias' => 'Flat washer'], ['id' => '', 'alias' => 'flat   WASHER']]]))->toHaveKey('aliases.1.alias')   // repeated
        ->and(inventoryErrors($this, $item, $alias('x')))->toHaveKey('aliases.0.alias')                    // too short
        ->and(inventoryErrors($this, $item, $alias('Flat washer')))->toBe([]);

    // the same text may be an alias in another company: aliases are per company
    expect(makeItem($this->globex, $this->globexPurchase, ['item' => ['name' => 'Foreign'], 'aliases' => [['id' => '', 'alias' => 'Lock washer']]], 'inventory')->aliases()->count())->toBe(1);
});

it('keeps stock levels in order: minimum ≤ reorder level ≤ maximum', function (array $levels, ?string $errorKey) {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Levels']], 'inventory');
    [$min, $reorder, $max] = $levels;
    $row = ['id' => '', 'location_id' => (string) $this->ahd->id, 'minimum_stock' => $min, 'reorder_level' => $reorder, 'maximum_stock' => $max];

    $errors = inventoryErrors($this, $item, ['locations' => [$row]]);

    $errorKey === null ? expect($errors)->toBe([]) : expect($errors)->toHaveKey($errorKey);
})->with([
    'in order' => [['10', '20', '30'], null],
    'all equal' => [['5', '5', '5'], null],
    'all zero' => [['0', '0', '0'], null],
    'minimum above reorder' => [['21', '20', '30'], 'locations.0.minimum_stock'],
    'reorder above maximum' => [['10', '31', '30'], 'locations.0.reorder_level'],
    'negative' => [['-1', '20', '30'], 'locations.0.minimum_stock'],
    'fraction of a Nos' => [['1.5', '20', '30'], 'locations.0.minimum_stock'],
    'text' => [['ten', '20', '30'], 'locations.0.minimum_stock'],
    'blank' => [['', '20', '30'], 'locations.0.minimum_stock'],
]);

it('allows fractional stock levels for a base unit that can be split', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Cable', 'base_unit_id' => (string) unitId($this->acme, 'MTR')], 'uoms' => []], 'inventory');
    $row = ['id' => '', 'location_id' => (string) $this->ahd->id, 'minimum_stock' => '1.5', 'reorder_level' => '20.25', 'maximum_stock' => '30.125'];

    expect(inventoryErrors($this, $item, ['locations' => [$row]]))->toBe([])
        ->and(inventoryErrors($this, $item, ['locations' => [[...$row, 'maximum_stock' => '30.1255']]]))->toHaveKey('locations.0.maximum_stock');   // four decimals
});

it('allows one set of stock levels per branch, and only for active branches of the company', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Branches']], 'inventory');
    $row = fn ($id) => ['id' => '', 'location_id' => (string) $id, 'minimum_stock' => '1', 'reorder_level' => '2', 'maximum_stock' => '3'];
    $closed = makeLocation($this->acme, 'OLD', ['status' => 'inactive']);
    $foreign = makeLocation($this->globex, 'GLB');

    expect(inventoryErrors($this, $item, ['locations' => [$row($this->ahd->id), $row($this->ahd->id)]]))->toHaveKey('locations.1.location_id')
        ->and(inventoryErrors($this, $item, ['locations' => [$row($closed->id)]]))->toHaveKey('locations.0.location_id')
        ->and(inventoryErrors($this, $item, ['locations' => [$row($foreign->id)]]))->toHaveKey('locations.0.location_id')
        ->and(inventoryErrors($this, $item, ['locations' => [$row(999999)]]))->toHaveKey('locations.0.location_id')
        ->and(inventoryErrors($this, $item, ['locations' => [$row($this->ahd->id), $row($this->mum->id)]]))->toBe([]);
});

it('backs the one-row-per-branch rule with the database too', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'DbRule']], 'inventory');
    $make = fn () => (new ItemLocationParameter(['location_id' => $this->ahd->id, 'minimum_stock' => 1, 'reorder_level' => 2, 'maximum_stock' => 3]))
        ->forceFill(['company_id' => $this->acme->id, 'item_id' => $item->id]);
    $make()->save();

    expect(fn () => $make()->save())->toThrow(UniqueConstraintViolationException::class);
});

it('lets a branch-restricted user work on their own branch without touching the others', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Scoped'], 'locations' => [
        ['id' => '', 'location_id' => (string) $this->ahd->id, 'minimum_stock' => '1', 'reorder_level' => '2', 'maximum_stock' => '3'],
        ['id' => '', 'location_id' => (string) $this->mum->id, 'minimum_stock' => '10', 'reorder_level' => '20', 'maximum_stock' => '30'],
    ]], 'inventory');
    $ahdRow = $item->locationParameters()->where('location_id', $this->ahd->id)->first();
    $mumRow = $item->locationParameters()->where('location_id', $this->mum->id)->first();

    // the Ahmedabad-only user changes Ahmedabad and says nothing of Mumbai
    $edit = inventoryInput('Scoped', ['inventory' => ['code' => $item->code], 'locations' => [
        ['id' => (string) $ahdRow->id, 'location_id' => (string) $this->ahd->id, 'minimum_stock' => '4', 'reorder_level' => '5', 'maximum_stock' => '6'],
    ]]);
    inCompany($this->acme, fn () => $this->service->saveInventoryStage($item->fresh(), $edit, $this->ahdOnly));

    expect($ahdRow->fresh())->minimum_stock->toBe('4.000')->and($mumRow->fresh())->minimum_stock->toBe('10.000')
        ->and($item->locationParameters()->count())->toBe(2);

    // …and cannot add Mumbai, nor reach Mumbai's row by id
    expect(inventoryErrors($this, $item->fresh(), ['inventory' => ['code' => $item->code], 'locations' => [
        ['id' => '', 'location_id' => (string) $this->mum->id, 'minimum_stock' => '1', 'reorder_level' => '2', 'maximum_stock' => '3'],
    ]], $this->ahdOnly))->not->toBe([]);
});

it('lets someone who works in every branch replace the whole list', function () {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'All branches'], 'locations' => [
        ['id' => '', 'location_id' => (string) $this->ahd->id, 'minimum_stock' => '1', 'reorder_level' => '2', 'maximum_stock' => '3'],
        ['id' => '', 'location_id' => (string) $this->mum->id, 'minimum_stock' => '1', 'reorder_level' => '2', 'maximum_stock' => '3'],
    ]], 'inventory');

    inCompany($this->acme, fn () => $this->service->saveInventoryStage($item->fresh(), inventoryInput('All branches', ['inventory' => ['code' => $item->code], 'locations' => []]), $this->inventory));

    expect($item->locationParameters()->count())->toBe(0);
});

it('refuses an alias or stock-level row id that belongs to another item', function () {
    $mine = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Mine'], 'inventory' => ['code' => 'MINE-1']], 'inventory');
    $theirs = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Theirs'], 'aliases' => [['id' => '', 'alias' => 'their alias']], 'locations' => [
        ['id' => '', 'location_id' => (string) $this->ahd->id, 'minimum_stock' => '1', 'reorder_level' => '2', 'maximum_stock' => '3'],
    ], 'inventory' => ['code' => 'THEIRS-1']], 'inventory');
    $alias = $theirs->aliases()->first();
    $params = $theirs->locationParameters()->first();

    foreach ([
        ['aliases' => [['id' => (string) $alias->id, 'alias' => 'hijacked']]],
        ['locations' => [['id' => (string) $params->id, 'location_id' => (string) $this->ahd->id, 'minimum_stock' => '9', 'reorder_level' => '9', 'maximum_stock' => '9']]],
    ] as $tampered) {
        expect(fn () => inCompany($this->acme, fn () => $this->service->saveInventoryStage($mine->fresh(), inventoryInput('Mine', ['inventory' => ['code' => 'MINE-CHANGED'], ...$tampered]), $this->inventory)))
            ->toThrow(ValidationException::class);
    }

    expect($mine->fresh()->code)->toBe('MINE-1')->and($alias->fresh()->alias)->toBe('their alias')->and($params->fresh()->minimum_stock)->toBe('1.000');
});

it('will not change the Inventory part of an item that is not in the Inventory stage', function (string $upTo) {
    $item = makeItem($this->acme, $this->purchase, ['item' => ['name' => "At {$upTo}"]], $upTo);

    expect(fn () => inCompany($this->acme, fn () => $this->service->saveInventoryStage($item, inventoryInput($item->name, ['inventory' => ['code' => 'NOPE-1']]), $this->inventory)))
        ->toThrow(ApprovalStateException::class, 'not in the Inventory stage');
})->with(['draft', 'submitted', 'approved']);

it('writes only into the company it runs for', function () {
    makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Acme item'], 'aliases' => [['id' => '', 'alias' => 'a1']]], 'inventory');
    makeItem($this->globex, $this->globexPurchase, ['item' => ['name' => 'Globex item'], 'aliases' => [['id' => '', 'alias' => 'g1']]], 'inventory');

    $count = fn (string $model, $company) => $model::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->count();

    expect($count(Item::class, $this->acme))->toBe(1)->and($count(Item::class, $this->globex))->toBe(1)
        ->and($count(ItemAlias::class, $this->acme))->toBe(1)->and($count(ItemAlias::class, $this->globex))->toBe(1)
        ->and($count(ItemUom::class, $this->acme))->toBe(1)->and($count(ItemUom::class, $this->globex))->toBe(1);
});

<?php

use App\Modules\Masters\Http\Livewire\Geography\BlockList;
use App\Modules\Masters\Http\Livewire\Geography\DistrictList;
use App\Modules\Masters\Http\Livewire\Geography\PinCodeList;
use App\Modules\Masters\Http\Livewire\Geography\StateList;
use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\PinCode;
use App\Modules\Masters\Models\State;
use App\Modules\Masters\Services\PinCodeLookup;
use App\Modules\Shared\Exceptions\TenantAccessException;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->geo = seedGeography($this->acme);
    $this->globexGeo = seedGeography($this->globex);

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex]);

    Role::create(['name' => 'Geography viewer'])->givePermissionTo('geography.view');
});

// ======================================================================================================
// PIN code lookup service
// ======================================================================================================

function lookup(string $pin)
{
    return app(PinCodeLookup::class)->resolve($pin);
}

it('resolves a PIN that maps to exactly one place', function () {
    $this->actingAs($this->admin);
    $result = lookup('380001');

    expect($result->found)->toBeTrue()
        ->and($result->state->name)->toBe('Gujarat')
        ->and($result->district->name)->toBe('Ahmedabad')
        ->and($result->block->name)->toBe('Daskroi')
        ->and($result->isAmbiguous())->toBeFalse()
        ->and($result->localities)->toHaveCount(1);
});

it('fills only what is certain when a PIN spans several blocks', function () {
    $this->actingAs($this->admin);
    $result = lookup('382110');

    expect($result->found)->toBeTrue()
        ->and($result->state->name)->toBe('Gujarat')
        ->and($result->district->name)->toBe('Ahmedabad')
        ->and($result->block)->toBeNull()                       // Daskroi or Sanand: do not guess
        ->and($result->isAmbiguous())->toBeTrue()
        ->and($result->localities->pluck('area')->all())->toBe(['Bavla', 'Sanand Road']);
});

it('leaves the district empty when a PIN spans districts', function () {
    $surat = $this->geo['surat'];
    PinCode::withoutGlobalScopes(Tenancy::SCOPES)->forceCreate([
        'company_id' => $this->acme->id, 'pin_code' => '380001', 'state_id' => $surat->state_id, 'district_id' => $surat->id, 'block_id' => null, 'area' => 'Border', 'status' => 'active',
    ]);
    $this->actingAs($this->admin);

    $result = lookup('380001');

    expect($result->state->name)->toBe('Gujarat')              // both are in Gujarat
        ->and($result->district)->toBeNull()
        ->and($result->block)->toBeNull()
        ->and($result->isAmbiguous())->toBeTrue();
});

it('does not name a block when some localities of the PIN have none', function () {
    PinCode::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('pin_code', '395003')->update(['block_id' => null]);
    $this->actingAs($this->admin);

    expect(lookup('395003')->block)->toBeNull()->and(lookup('395003')->district->name)->toBe('Surat');
});

it('reports unknown and invalid PIN codes without guessing', function (string $pin) {
    $this->actingAs($this->admin);
    $result = lookup($pin);

    expect($result->found)->toBeFalse()->and($result->state)->toBeNull()->and($result->district)->toBeNull()->and($result->localities)->toBeEmpty();
})->with(['unknown' => '560001', 'too short' => '3800', 'letters' => '38A001', 'leading zero' => '012345', 'empty' => '']);

it('ignores inactive PIN rows', function () {
    PinCode::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('pin_code', '411001')->update(['status' => 'inactive']);
    $this->actingAs($this->admin);

    expect(lookup('411001')->found)->toBeFalse();
});

it('only looks in the signed-in user’s company', function () {
    $this->actingAs($this->globexAdmin);
    expect(lookup('380001')->found)->toBeTrue();           // Globex has its own copy

    PinCode::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->globex->id)->forceDelete();
    expect(lookup('380001')->found)->toBeFalse();          // and Acme's rows are not visible to it
});

// ======================================================================================================
// Parent guard on the models
// ======================================================================================================

it('refuses a district under another company’s state', function () {
    $district = new District(['state_id' => $this->globexGeo['gj']->id, 'name' => 'Rogue district']);
    $district->forceFill(['company_id' => $this->acme->id]);

    expect(fn () => $district->save())->toThrow(TenantAccessException::class, 'state_id must refer to a record of the same company');
});

it('refuses a PIN row that points at another company’s block', function () {
    $pin = new PinCode(['pin_code' => '380009', 'state_id' => $this->geo['gj']->id, 'district_id' => $this->geo['ahmedabad']->id, 'block_id' => $this->globexGeo['daskroi']->id]);
    $pin->forceFill(['company_id' => $this->acme->id]);

    expect(fn () => $pin->save())->toThrow(TenantAccessException::class, 'block_id');
});

// ======================================================================================================
// States
// ======================================================================================================

it('lists the seeded states with the geography tabs', function () {
    // 36 states over 15-row pages: Gujarat is on the first, Maharashtra is found by search
    Livewire::actingAs($this->admin)->test(StateList::class)
        ->assertSee('Gujarat')->assertSee('GST code')
        ->assertSee('States')->assertSee('Districts')->assertSee('Blocks')->assertSee('PIN codes')
        ->set('search', 'Maharashtra')->assertSee('Maharashtra')->assertDontSee('Gujarat');
});

it('keeps GST codes unique and two digits long', function () {
    $component = Livewire::actingAs($this->admin)->test(StateList::class)->call('create')->set('values.code', 'XX')->set('values.name', 'Test state');

    $component->set('values.gst_code', '24')->call('save')->assertHasErrors(['values.gst_code']);     // Gujarat's
    $component->set('values.gst_code', '9')->call('save')->assertHasErrors(['values.gst_code']);
    $component->set('values.gst_code', '98')->call('save')->assertHasNoErrors();

    expect(State::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->acme->id)->where('gst_code', '98')->exists())->toBeTrue();
});

it('will not delete a state that has districts', function () {
    Livewire::actingAs($this->admin)->test(StateList::class)->call('delete', $this->geo['gj']->id)->assertHasErrors(['list']);

    expect(State::query()->whereKey($this->geo['gj']->id)->exists())->toBeTrue();

    $empty = State::query()->where('gst_code', '36')->firstOrFail();
    Livewire::actingAs($this->admin)->test(StateList::class)->call('delete', $empty->id)->assertHasNoErrors();
    expect(State::query()->where('gst_code', '36')->exists())->toBeFalse();
});

// ======================================================================================================
// Districts
// ======================================================================================================

it('adds a district to one of the company’s states', function () {
    Livewire::actingAs($this->admin)->test(DistrictList::class)->call('create')
        ->set('values.state_id', (string) $this->geo['gj']->id)->set('values.name', '  Rajkot ')->call('save')->assertHasNoErrors();

    expect(District::query()->where('name', 'Rajkot')->first())->state_id->toBe($this->geo['gj']->id)->company_id->toBe($this->acme->id);
});

it('rejects a state that belongs to another company, even with a tampered id', function () {
    Livewire::actingAs($this->admin)->test(DistrictList::class)->call('create')
        ->set('values.state_id', (string) $this->globexGeo['gj']->id)->set('values.name', 'Rogue')->call('save')->assertHasErrors(['values.state_id']);

    expect(District::withoutGlobalScopes(Tenancy::SCOPES)->where('name', 'Rogue')->exists())->toBeFalse();
});

it('keeps district names unique within a state, but not across states', function () {
    $component = fn () => Livewire::actingAs($this->admin)->test(DistrictList::class)->call('create');

    $component()->set('values.state_id', (string) $this->geo['gj']->id)->set('values.name', 'ahmedabad')->call('save')->assertHasErrors(['values.name']);

    // Aurangabad exists in more than one state: allowed
    $component()->set('values.state_id', (string) $this->geo['gj']->id)->set('values.name', 'Aurangabad')->call('save')->assertHasNoErrors();
    $component()->set('values.state_id', (string) $this->geo['mh']->id)->set('values.name', 'Aurangabad')->call('save')->assertHasNoErrors();

    expect(District::query()->where('name', 'Aurangabad')->count())->toBe(2);
});

it('filters districts by state and shows the state', function () {
    Livewire::actingAs($this->admin)->test(DistrictList::class)
        ->assertSee('Ahmedabad')->assertSee('Pune')
        ->set('filter', (string) $this->geo['mh']->id)->assertSee('Pune')->assertDontSee('Ahmedabad');
});

it('will not delete a district that still has blocks or PIN codes', function () {
    Livewire::actingAs($this->admin)->test(DistrictList::class)->call('delete', $this->geo['ahmedabad']->id)->assertHasErrors(['list']);
    expect(District::query()->whereKey($this->geo['ahmedabad']->id)->exists())->toBeTrue();
});

// ======================================================================================================
// Blocks
// ======================================================================================================

it('adds a block and keeps names unique within the district', function () {
    $component = fn () => Livewire::actingAs($this->admin)->test(BlockList::class)->call('create');

    $component()->set('values.district_id', (string) $this->geo['surat']->id)->set('values.name', 'Olpad')->call('save')->assertHasNoErrors();
    $component()->set('values.district_id', (string) $this->geo['surat']->id)->set('values.name', 'olpad')->call('save')->assertHasErrors(['values.name']);
    $component()->set('values.district_id', (string) $this->geo['ahmedabad']->id)->set('values.name', 'Olpad')->call('save')->assertHasNoErrors();   // other district

    expect(Block::query()->where('name', 'Olpad')->count())->toBe(2);
});

it('rejects a district from another company', function () {
    Livewire::actingAs($this->admin)->test(BlockList::class)->call('create')
        ->set('values.district_id', (string) $this->globexGeo['ahmedabad']->id)->set('values.name', 'Rogue')->call('save')->assertHasErrors(['values.district_id']);
});

it('will not delete a block that PIN codes refer to', function () {
    Livewire::actingAs($this->admin)->test(BlockList::class)->call('delete', $this->geo['daskroi']->id)->assertHasErrors(['list']);
    expect(Block::query()->whereKey($this->geo['daskroi']->id)->exists())->toBeTrue();
});

it('shows each block with its district and state', function () {
    Livewire::actingAs($this->admin)->test(BlockList::class)->assertSeeInOrder(['Choryasi', 'Surat', 'Gujarat']);
});

// ======================================================================================================
// PIN codes
// ======================================================================================================

function addPin($component, string $pin, $district, $block = null, string $area = '')
{
    return $component->call('create')->set('values.pin_code', $pin)->set('values.district_id', (string) $district->id)
        ->set('values.block_id', $block ? (string) $block->id : '')->set('values.area', $area)->call('save');
}

it('adds a PIN code whose state comes from its district', function () {
    addPin(Livewire::actingAs($this->admin)->test(PinCodeList::class), '395007', $this->geo['surat'], $this->geo['choryasi'], 'Adajan')->assertHasNoErrors();

    $pin = PinCode::query()->where('pin_code', '395007')->firstOrFail();
    expect($pin->state_id)->toBe($this->geo['gj']->id)
        ->and($pin->district_id)->toBe($this->geo['surat']->id)
        ->and($pin->block_id)->toBe($this->geo['choryasi']->id)
        ->and($pin->area)->toBe('Adajan');
});

it('allows a PIN with no block and no locality', function () {
    addPin(Livewire::actingAs($this->admin)->test(PinCodeList::class), '395008', $this->geo['surat'])->assertHasNoErrors();

    expect(PinCode::query()->where('pin_code', '395008')->first())->block_id->toBeNull()->area->toBe('');
});

it('requires a valid six-digit PIN', function (string $pin) {
    addPin(Livewire::actingAs($this->admin)->test(PinCodeList::class), $pin, $this->geo['surat'])->assertHasErrors(['values.pin_code']);
})->with(['short' => '3950', 'letters' => '39500X', 'leading zero' => '095007', 'long' => '3950071']);

it('rejects a block that does not belong to the chosen district', function () {
    addPin(Livewire::actingAs($this->admin)->test(PinCodeList::class), '395009', $this->geo['surat'], $this->geo['daskroi'])->assertHasErrors(['values.block_id']);

    expect(PinCode::query()->where('pin_code', '395009')->exists())->toBeFalse();
});

it('rejects a district or block from another company', function () {
    addPin(Livewire::actingAs($this->admin)->test(PinCodeList::class), '395010', $this->globexGeo['surat'])->assertHasErrors(['values.district_id']);
    addPin(Livewire::actingAs($this->admin)->test(PinCodeList::class), '395010', $this->geo['surat'], $this->globexGeo['choryasi'])->assertHasErrors(['values.block_id']);
});

it('keeps PIN + district + locality unique, but allows a PIN to repeat across localities and districts', function () {
    $component = fn () => Livewire::actingAs($this->admin)->test(PinCodeList::class);

    addPin($component(), '380001', $this->geo['ahmedabad'], null, 'Khanpur')->assertHasErrors(['values.pin_code']);          // exact duplicate
    addPin($component(), '380001', $this->geo['ahmedabad'], null, 'Another locality')->assertHasNoErrors();                  // same PIN, new locality
    addPin($component(), '380001', $this->geo['surat'], null, 'Khanpur')->assertHasNoErrors();                               // same PIN, other district

    expect(PinCode::query()->where('pin_code', '380001')->count())->toBe(3);
});

it('clears the block when the district changes, and offers only that district’s blocks', function () {
    // "Choryasi" also shows in the table behind the form, so look at the <option> markup itself
    $option = fn (string $key) => '<option value="'.$this->geo[$key]->id.'">'.$this->geo[$key]->name.'</option>';

    $component = Livewire::actingAs($this->admin)->test(PinCodeList::class)->call('create')
        ->set('values.district_id', (string) $this->geo['ahmedabad']->id)
        ->set('values.block_id', (string) $this->geo['daskroi']->id)
        ->assertSeeHtml($option('daskroi'))->assertDontSeeHtml($option('choryasi'));

    $component->set('values.district_id', (string) $this->geo['surat']->id)
        ->assertSet('values.block_id', '')
        ->assertSeeHtml($option('choryasi'))->assertDontSeeHtml($option('daskroi'));
});

it('edits a PIN row without tripping over its own key', function () {
    $row = PinCode::query()->where('pin_code', '395003')->firstOrFail();

    Livewire::actingAs($this->admin)->test(PinCodeList::class)->call('edit', $row->id)
        ->assertSet('values.pin_code', '395003')->assertSet('values.area', 'Surat City')
        ->set('values.area', 'Surat Main')->call('save')->assertHasNoErrors();

    expect($row->fresh()->area)->toBe('Surat Main');
});

it('lists PIN codes with block, district and state, filterable by state and searchable', function () {
    Livewire::actingAs($this->admin)->test(PinCodeList::class)
        ->assertSeeInOrder(['380001', 'Khanpur', 'Daskroi', 'Ahmedabad', 'Gujarat'])
        ->set('filter', (string) $this->geo['mh']->id)->assertSee('411001')->assertDontSee('380001')
        ->set('filter', '')->set('search', 'Sanand')->assertSee('382110')->assertDontSee('411001');
});

it('lets a view-only user browse but not change geography', function () {
    $viewer = makeUser(['Geography viewer'], [$this->acme]);

    foreach ([StateList::class, DistrictList::class, BlockList::class, PinCodeList::class] as $list) {
        Livewire::actingAs($viewer)->test($list)->assertOk()->assertDontSee('Add')->call('create')->assertForbidden();
    }
});

it('shows no geography to a user without the permission', function () {
    $nobody = makeUser(['Tele-caller'], [$this->acme]);

    foreach ([StateList::class, DistrictList::class, BlockList::class, PinCodeList::class] as $list) {
        Livewire::actingAs($nobody)->test($list)->assertForbidden();
    }
});

it('keeps each company’s geography private', function () {
    $theirs = PinCode::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->globex->id)->where('pin_code', '411001')->firstOrFail();

    Livewire::actingAs($this->admin)->test(PinCodeList::class)->call('edit', $theirs->id)->assertNotFound();
    Livewire::actingAs($this->admin)->test(PinCodeList::class)->call('delete', $theirs->id)->assertNotFound();

    expect(PinCode::query()->count())->toBe(5)    // Acme's five rows only
        ->and(PinCode::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(10);
});

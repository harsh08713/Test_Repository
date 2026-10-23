<?php

use App\Modules\Masters\Http\Livewire\Locations\LocationForm;
use App\Modules\Masters\Http\Livewire\Locations\LocationList;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\NumberSeriesService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->geo = seedGeography($this->acme);

    $this->ahd = makeLocation($this->acme, 'AHD', ['name' => 'Ahmedabad HO', 'city' => 'Ahmedabad']);
    $this->mum = makeLocation($this->acme, 'MUM', ['name' => 'Mumbai Branch']);
    $this->pun = makeLocation($this->globex, 'PUN', ['name' => 'Pune Plant']);

    $this->admin = makeUser(['Company Admin'], [$this->acme]);

    Role::create(['name' => 'Branch manager'])->givePermissionTo(['location.view', 'location.create', 'location.edit', 'location.delete']);
    $this->restricted = makeUser(['Branch manager'], [$this->acme], locations: [$this->ahd]);
});

function branch(array $overrides = []): array
{
    return [
        'code' => 'SRT', 'name' => 'Surat Branch', 'address_line1' => '12 Ring Road', 'city' => 'Surat',
        'state_gst_code' => '24', 'pin_code' => '395003', 'phone' => '0261-2345678', 'email' => 'surat@acme.test',
        'latitude' => '21.1702000', 'longitude' => '72.8311000', 'status' => 'active',
        ...$overrides,
    ];
}

function fillBranch($component, array $values)
{
    foreach ($values as $k => $v) {
        $component->set($k, $v);
    }

    return $component;
}

// ---- create ------------------------------------------------------------------------------------------

it('adds a branch with its state taken from the company’s state master', function () {
    fillBranch(Livewire::actingAs($this->admin)->test(LocationForm::class), branch(['code' => 'srt']))
        ->call('save')->assertHasNoErrors()->assertRedirect(route('masters.locations.index'));

    $branch = Location::query()->where('code', 'SRT')->firstOrFail();

    expect($branch->company_id)->toBe($this->acme->id)
        ->and($branch->name)->toBe('Surat Branch')
        ->and($branch->state)->toBe('Gujarat')->and($branch->state_code)->toBe('24')
        ->and($branch->latitude)->toBe('21.1702000')->and($branch->longitude)->toBe('72.8311000')
        ->and($branch->created_by)->toBe($this->admin->id);
});

it('records the creation in the audit log for that company', function () {
    fillBranch(Livewire::actingAs($this->admin)->test(LocationForm::class), branch())->call('save');

    $entry = Activity::query()->withoutGlobalScopes()->where('subject_type', 'location')->where('event', 'created')->latest('id')->first();
    expect($entry->causer_id)->toBe($this->admin->id)->and($entry->company_id)->toBe($this->acme->id);
});

it('forbids people without the create permission', function () {
    Livewire::actingAs(makeUser(['Purchase User'], [$this->acme]))->test(LocationForm::class)->assertForbidden();
    Livewire::actingAs(makeUser(['BDE / Field Salesperson'], [$this->acme]))->test(LocationList::class)->assertForbidden();
});

// ---- codes -------------------------------------------------------------------------------------------

it('only accepts short upper-case alphanumeric branch codes', function (string $code) {
    fillBranch(Livewire::actingAs($this->admin)->test(LocationForm::class), branch(['code' => $code]))
        ->call('save')->assertHasErrors(['code']);
})->with(['one character' => 'A', 'eleven characters' => 'ABCDEFGHIJK', 'dash' => 'AH-D', 'space' => 'AH D', 'symbol' => 'AH/D', 'empty' => '']);

it('keeps branch codes unique within a company, including deleted branches', function () {
    fillBranch(Livewire::actingAs($this->admin)->test(LocationForm::class), branch(['code' => 'AHD']))
        ->call('save')->assertHasErrors(['code']);

    $this->mum->delete();

    fillBranch(Livewire::actingAs($this->admin)->test(LocationForm::class), branch(['code' => 'MUM']))
        ->call('save')->assertHasErrors(['code']);   // the database key still holds the deleted branch's code
});

it('allows the same branch code in another company', function () {
    $globexAdmin = makeUser(['Company Admin'], [$this->globex]);

    fillBranch(Livewire::actingAs($globexAdmin)->test(LocationForm::class), branch(['code' => 'AHD', 'state_gst_code' => '27', 'pin_code' => '']))
        ->call('save')->assertHasNoErrors();

    expect(Location::withoutGlobalScopes(Tenancy::SCOPES)->where('code', 'AHD')->count())->toBe(2);
});

// ---- coordinates / fields ----------------------------------------------------------------------------

it('needs both coordinates or neither, within range', function (string $lat, string $lng) {
    fillBranch(Livewire::actingAs($this->admin)->test(LocationForm::class), branch(['latitude' => $lat, 'longitude' => $lng]))
        ->call('save')->assertHasErrors();

    expect(Location::query()->where('code', 'SRT')->exists())->toBeFalse();
})->with([
    'latitude only' => ['21.17', ''],
    'longitude only' => ['', '72.83'],
    'latitude too high' => ['91', '72.83'],
    'latitude too low' => ['-90.5', '72.83'],
    'longitude too high' => ['21.17', '181'],
    'not a number' => ['north', '72.83'],
]);

it('accepts a branch without coordinates', function () {
    fillBranch(Livewire::actingAs($this->admin)->test(LocationForm::class), branch(['latitude' => '', 'longitude' => '']))
        ->call('save')->assertHasNoErrors();

    expect(Location::query()->where('code', 'SRT')->first())->latitude->toBeNull()->longitude->toBeNull();
});

it('validates PIN code, phone, email and status', function () {
    fillBranch(Livewire::actingAs($this->admin)->test(LocationForm::class), branch(['pin_code' => '12', 'phone' => 'xx', 'email' => 'nope', 'status' => 'archived']))
        ->call('save')->assertHasErrors(['pin_code', 'phone', 'email', 'status']);
});

it('refuses a state that is not in the company’s master', function () {
    fillBranch(Livewire::actingAs($this->admin)->test(LocationForm::class), branch(['state_gst_code' => '25', 'pin_code' => '']))   // no PIN, or the autofill would correct the state
        ->call('save')->assertHasErrors(['state_gst_code']);
});

// ---- PIN code lookup ---------------------------------------------------------------------------------

it('fills the state and city from a PIN code that maps to one place', function () {
    Livewire::actingAs($this->admin)->test(LocationForm::class)
        ->set('pin_code', '395003')
        ->assertSet('state_gst_code', '24')->assertSet('city', 'Surat')
        ->assertSet('pinHint', 'State and city filled from the PIN code master.');
});

it('fills only what is certain when a PIN code spans several blocks', function () {
    Livewire::actingAs($this->admin)->test(LocationForm::class)
        ->set('pin_code', '382110')
        ->assertSet('state_gst_code', '24')->assertSet('city', 'Ahmedabad')       // one district
        ->assertSee('more than one district or block');
});

it('says so when a PIN code is not in the master', function () {
    Livewire::actingAs($this->admin)->test(LocationForm::class)
        ->set('pin_code', '560001')
        ->assertSet('state_gst_code', '')->assertSet('city', '')
        ->assertSee('not in the geography master');
});

it('does not overwrite a city the user already typed', function () {
    Livewire::actingAs($this->admin)->test(LocationForm::class)
        ->set('city', 'Ahmedabad (West)')->set('pin_code', '380001')
        ->assertSet('city', 'Ahmedabad (West)')->assertSet('state_gst_code', '24');
});

it('ignores an invalid PIN code when looking up', function () {
    Livewire::actingAs($this->admin)->test(LocationForm::class)->set('pin_code', '12')->assertSet('pinHint', '')->assertSet('state_gst_code', '');
});

it('looks the PIN up in this company’s master, not another company’s', function () {
    $globexAdmin = makeUser(['Company Admin'], [$this->globex]);

    Livewire::actingAs($globexAdmin)->test(LocationForm::class)
        ->set('pin_code', '380001')->assertSet('state_gst_code', '')->assertSee('not in the geography master');
});

// ---- edit --------------------------------------------------------------------------------------------

it('edits a branch and keeps its own code valid', function () {
    Livewire::actingAs($this->admin)->test(LocationForm::class, ['location' => $this->ahd])
        ->assertSet('code', 'AHD')->assertSet('name', 'Ahmedabad HO')
        ->set('name', 'Ahmedabad Head Office')->call('save')->assertHasNoErrors();

    expect($this->ahd->fresh()->name)->toBe('Ahmedabad Head Office');
});

it('fixes the branch code once documents have been numbered for it', function () {
    app(NumberSeriesService::class)->next(DocumentType::Quotation, $this->ahd);

    Livewire::actingAs($this->admin)->test(LocationForm::class, ['location' => $this->ahd])
        ->assertSee('Fixed, because document numbers have already been issued')
        ->set('code', 'HQ1')->call('save')->assertHasNoErrors();

    expect($this->ahd->fresh()->code)->toBe('AHD');
});

it('lets the code change while no documents are numbered', function () {
    Livewire::actingAs($this->admin)->test(LocationForm::class, ['location' => $this->mum])
        ->set('code', 'BOM')->call('save')->assertHasNoErrors();

    expect($this->mum->fresh()->code)->toBe('BOM');
});

it('cannot reach another company’s branch', function () {
    $this->actingAs($this->admin)->get(route('masters.locations.edit', $this->pun))->assertNotFound();
    Livewire::actingAs($this->admin)->test(LocationForm::class, ['location' => $this->pun])->assertForbidden();
});

// ---- list --------------------------------------------------------------------------------------------

it('lists the company’s branches, searchable and filterable by status', function () {
    $this->mum->forceFill(['status' => 'inactive'])->save();

    Livewire::actingAs($this->admin)->test(LocationList::class)
        ->assertSee('Ahmedabad HO')->assertSee('Mumbai Branch')->assertDontSee('Pune Plant')
        ->set('search', 'mumbai')->assertSee('Mumbai Branch')->assertDontSee('Ahmedabad HO')
        ->set('search', '')->set('status', 'inactive')->assertSee('Mumbai Branch')->assertDontSee('Ahmedabad HO');
});

it('toggles a branch’s status', function () {
    Livewire::actingAs($this->admin)->test(LocationList::class)->call('toggleStatus', $this->ahd->id);

    expect($this->ahd->fresh()->status)->toBe('inactive');
});

// ---- delete guards -----------------------------------------------------------------------------------

it('will not delete a branch that has numbered documents', function () {
    app(NumberSeriesService::class)->next(DocumentType::Lead, $this->mum);

    Livewire::actingAs($this->admin)->test(LocationList::class)->call('delete', $this->mum->id)->assertHasErrors(['list']);

    expect(Location::query()->whereKey($this->mum->id)->exists())->toBeTrue();
});

it('will not delete a branch that users are assigned to', function () {
    // $this->restricted is assigned to AHD
    Livewire::actingAs($this->admin)->test(LocationList::class)->call('delete', $this->ahd->id)->assertHasErrors(['list']);

    expect(Location::query()->whereKey($this->ahd->id)->exists())->toBeTrue();
});

it('deletes an unused branch (soft) and records it', function () {
    Livewire::actingAs($this->admin)->test(LocationList::class)->call('delete', $this->mum->id)->assertHasNoErrors();

    expect(Location::query()->whereKey($this->mum->id)->exists())->toBeFalse()
        ->and(Location::withoutGlobalScopes()->whereKey($this->mum->id)->first()->trashed())->toBeTrue();

    expect(Activity::query()->withoutGlobalScopes()->where('subject_type', 'location')->where('event', 'deleted')->exists())->toBeTrue();
});

// ---- branch-restricted users -------------------------------------------------------------------------

it('shows a branch-restricted user only their branches', function () {
    Livewire::actingAs($this->restricted)->test(LocationList::class)
        ->assertSee('Ahmedabad HO')->assertDontSee('Mumbai Branch');
});

it('stops a branch-restricted user editing a branch outside their access', function () {
    $this->actingAs($this->restricted)->get(route('masters.locations.edit', $this->mum))->assertNotFound();
    Livewire::actingAs($this->restricted)->test(LocationForm::class, ['location' => $this->mum])->assertForbidden();
});

it('gives a branch-restricted user access to the branch they create', function () {
    fillBranch(Livewire::actingAs($this->restricted)->test(LocationForm::class), branch())->call('save')->assertHasNoErrors();

    $created = Location::withoutGlobalScopes(Tenancy::SCOPES)->where('code', 'SRT')->firstOrFail();

    expect(DB::table('location_user')->where('location_id', $created->id)->where('user_id', $this->restricted->id)->exists())->toBeTrue();

    // ...so they can actually see it afterwards
    Livewire::actingAs($this->restricted)->test(LocationList::class)->assertSee('Surat Branch');
});

it('does not grant extra access to someone who can already see every branch', function () {
    fillBranch(Livewire::actingAs($this->admin)->test(LocationForm::class), branch())->call('save');

    $created = Location::withoutGlobalScopes(Tenancy::SCOPES)->where('code', 'SRT')->firstOrFail();

    expect(DB::table('location_user')->where('location_id', $created->id)->count())->toBe(0);
});

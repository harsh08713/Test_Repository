<?php

use App\Modules\CRM\Enums\CoverageKind;
use App\Modules\CRM\Http\Livewire\Leads\LeadShow;
use App\Modules\CRM\Http\Livewire\RoutePlans\RoutePlanForm;
use App\Modules\CRM\Http\Livewire\Territories\TerritoryForm;
use App\Modules\CRM\Http\Livewire\Territories\TerritoryList;
use App\Modules\CRM\Http\Livewire\Territories\TerritoryShow;
use App\Modules\CRM\Models\Territory;
use App\Modules\CRM\Models\TerritoryArea;
use App\Modules\CRM\Services\TerritoryResolver;
use App\Modules\CRM\Services\TerritoryService;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->geo = seedGeography($this->acme);
    $this->theirGeo = seedGeography($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');
    $this->nyc = makeLocation($this->globex, 'NYC');

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->mumBde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->mum]);
    $this->accounts = makeUser(['Accounts User'], [$this->acme]);
    $this->theirManager = makeUser(['Sales Manager'], [$this->globex]);

    // a territory through the real service, as the manager unless said otherwise
    $this->territory = fn (array $over = [], $as = null, $company = null) => inCompany($company ?? $this->acme, fn () => app(TerritoryService::class)
        ->save(null, ['code' => 'NORTH', 'name' => 'North Gujarat', 'status' => 'active', ...$over], $as ?? $this->manager));
    $this->cover = fn (Territory $territory, CoverageKind $kind, string|int $reference, $as = null, $company = null) => inCompany($company ?? $this->acme, fn () => app(TerritoryService::class)
        ->addCoverage($territory, $kind, $reference, $as ?? $this->manager));
});

// ---- the master -------------------------------------------------------------------------------------------

it('starts a territory with an owner, upper-casing its code', function () {
    $territory = ($this->territory)(['code' => 'gj-north', 'owner_user_id' => (string) $this->bde->id, 'location_id' => (string) $this->ahd->id, 'remarks' => 'Ahmedabad side']);

    expect($territory->code)->toBe('GJ-NORTH')->and($territory->owner_user_id)->toBe($this->bde->id)->and($territory->location_id)->toBe($this->ahd->id)
        ->and($territory->status)->toBe('active')->and($territory->company_id)->toBe($this->acme->id)->and($territory->created_by)->toBe($this->manager->id);
});

it('keeps codes and names the company’s own, once each', function () {
    ($this->territory)();

    expect(fn () => ($this->territory)(['name' => 'Another']))->toThrow(ValidationException::class)              // same code
        ->and(fn () => ($this->territory)(['code' => 'SOUTH']))->toThrow(ValidationException::class)               // same name
        ->and(fn () => ($this->territory)(['code' => 'bad code!', 'name' => 'Bad']))->toThrow(ValidationException::class);

    // another company may use the same code and name
    expect(($this->territory)(['owner_user_id' => (string) $this->theirManager->id], $this->theirManager, $this->globex)->company_id)->toBe($this->globex->id);
});

it('accepts an owner only from the company — and from the branch, when the territory names one', function () {
    expect(fn () => ($this->territory)(['owner_user_id' => (string) $this->theirManager->id]))->toThrow(ValidationException::class);                                   // another company's person
    expect(fn () => ($this->territory)(['owner_user_id' => (string) $this->mumBde->id, 'location_id' => (string) $this->ahd->id]))->toThrow(ValidationException::class);   // works in Mumbai only
    expect(($this->territory)(['owner_user_id' => (string) $this->mumBde->id, 'location_id' => (string) $this->mum->id])->owner_user_id)->toBe($this->mumBde->id);

    $inactive = makeUser(['BDE / Field Salesperson'], [$this->acme], null, ['is_active' => false]);
    expect(fn () => ($this->territory)(['code' => 'X1', 'name' => 'X1', 'owner_user_id' => (string) $inactive->id]))->toThrow(ValidationException::class);
});

it('refuses a branch the person cannot reach, an unknown branch and another company’s branch', function () {
    $mumOnly = makeUser(['Sales Manager'], [$this->acme], [$this->mum]);

    expect(fn () => ($this->territory)(['location_id' => (string) $this->ahd->id], $mumOnly))->toThrow(ValidationException::class)
        ->and(fn () => ($this->territory)(['location_id' => (string) $this->nyc->id]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->territory)(['location_id' => '99999']))->toThrow(ValidationException::class);
});

it('changes a territory — its owner and whether it is switched on — and the change is audited', function () {
    $territory = ($this->territory)();

    $changed = inCompany($this->acme, fn () => app(TerritoryService::class)->save($territory, [
        'code' => 'NORTH', 'name' => 'North Gujarat', 'owner_user_id' => (string) $this->bde->id, 'status' => 'inactive',
    ], $this->manager));

    expect($changed->owner_user_id)->toBe($this->bde->id)->and($changed->status)->toBe('inactive')
        ->and(ActivityLog::query()->where('subject_type', 'territory')->where('subject_id', $territory->id)->exists())->toBeTrue();
});

// ---- coverage ---------------------------------------------------------------------------------------------

it('covers states, districts, blocks, PIN codes and customers', function () {
    $territory = ($this->territory)();
    $customer = makeCustomer($this->acme);

    ($this->cover)($territory, CoverageKind::State, $this->geo['mh']->id);
    ($this->cover)($territory, CoverageKind::District, $this->geo['ahmedabad']->id);
    ($this->cover)($territory, CoverageKind::Block, $this->geo['choryasi']->id);
    ($this->cover)($territory, CoverageKind::PinCode, '380001');
    ($this->cover)($territory, CoverageKind::Customer, $customer->id);

    $areas = TerritoryArea::query()->where('territory_id', $territory->id)->orderBy('id')->get();

    expect($areas->map(fn ($a) => $a->kind->value)->all())->toBe(['state', 'district', 'block', 'pin_code', 'customer'])
        ->and($areas->map(fn ($a) => $a->ref)->all())->toBe([(string) $this->geo['mh']->id, (string) $this->geo['ahmedabad']->id, (string) $this->geo['choryasi']->id, '380001', (string) $customer->id])
        ->and($areas[3]->label())->toBe('380001')->and($areas[4]->label())->toContain('Shree Ganesh Industries');
});

it('refuses what the company does not have: an unknown PIN, another company’s district, a supplier, an unapproved customer, nonsense', function () {
    $territory = ($this->territory)();
    $supplier = makeLedger($this->acme, makeUser(['Accounts User'], [$this->acme]), 'supplier', [], true);
    $draft = makeLedger($this->acme, makeUser(['Purchase User'], [$this->acme]), 'customer', [], false);

    foreach ([
        [CoverageKind::PinCode, '999999'], [CoverageKind::PinCode, 'abc'], [CoverageKind::District, $this->theirGeo['pune']->id], [CoverageKind::State, 999999],
        [CoverageKind::Customer, $supplier->id], [CoverageKind::Customer, $draft->id], [CoverageKind::Block, 'x'], [CoverageKind::Block, 0],
    ] as [$kind, $reference]) {
        expect(fn () => ($this->cover)($territory, $kind, $reference))->toThrow(ValidationException::class);
    }

    expect(TerritoryArea::query()->count())->toBe(0);
});

it('lets a place belong to one territory only, and says which one holds it', function () {
    $north = ($this->territory)();
    $south = ($this->territory)(['code' => 'SOUTH', 'name' => 'South Gujarat']);

    ($this->cover)($north, CoverageKind::District, $this->geo['ahmedabad']->id);

    expect(fn () => ($this->cover)($south, CoverageKind::District, $this->geo['ahmedabad']->id))->toThrow(ValidationException::class, 'already covered by North Gujarat (NORTH)')
        ->and(fn () => ($this->cover)($north, CoverageKind::District, $this->geo['ahmedabad']->id))->toThrow(ValidationException::class);   // not twice on the same one either

    // a PIN code inside that district is another unit: it may be carved out for the other territory
    ($this->cover)($south, CoverageKind::PinCode, '380001');

    expect(TerritoryArea::query()->count())->toBe(2);
});

it('lets go of coverage for real, so that another territory can take it, and writes both to the audit trail', function () {
    $north = ($this->territory)();
    $south = ($this->territory)(['code' => 'SOUTH', 'name' => 'South Gujarat']);

    $area = ($this->cover)($north, CoverageKind::State, $this->geo['gj']->id);
    inCompany($this->acme, fn () => app(TerritoryService::class)->removeCoverage($area, $this->manager));

    expect(TerritoryArea::withoutGlobalScopes()->withTrashed()->count())->toBe(0);   // gone, not soft-deleted: a soft-deleted row would hold the key

    ($this->cover)($south, CoverageKind::State, $this->geo['gj']->id);

    $events = ActivityLog::query()->where('subject_type', 'territory')->whereIn('event', ['coverage_added', 'coverage_removed'])->orderBy('id')->pluck('event')->all();

    expect($events)->toBe(['coverage_added', 'coverage_removed', 'coverage_added']);

    // taking off what is already off is not an error
    inCompany($this->acme, fn () => app(TerritoryService::class)->removeCoverage($area, $this->manager));
});

it('gives coverage only to a territory that is switched on', function () {
    $territory = ($this->territory)(['status' => 'inactive']);

    expect(fn () => ($this->cover)($territory, CoverageKind::State, $this->geo['gj']->id))->toThrow(ValidationException::class, 'Switch the territory on');
});

it('is backed by the database: one territory per unit, and one target per row', function () {
    $north = ($this->territory)();
    $south = ($this->territory)(['code' => 'SOUTH', 'name' => 'South Gujarat']);

    $row = fn (int $territoryId, array $fields) => (new TerritoryArea)->forceFill(['company_id' => $this->acme->id, 'territory_id' => $territoryId, ...$fields]);

    $row($north->id, ['kind' => 'state', 'state_id' => $this->geo['gj']->id])->save();

    expect(fn () => $row($south->id, ['kind' => 'state', 'state_id' => $this->geo['gj']->id])->save())->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => $row($south->id, ['kind' => 'state', 'state_id' => $this->geo['mh']->id, 'district_id' => $this->geo['pune']->id])->save())->toThrow(QueryException::class)   // two targets
        ->and(fn () => $row($south->id, ['kind' => 'district', 'state_id' => $this->geo['mh']->id])->save())->toThrow(QueryException::class);                                  // the wrong column for the kind

    // a kind the model does not know is refused by the model's cast before it gets there; the table refuses it too
    expect(fn () => DB::table('territory_areas')->insert(['company_id' => $this->acme->id, 'territory_id' => $south->id, 'kind' => 'region', 'state_id' => $this->geo['mh']->id, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
});

it('deletes a territory that covers nothing, and refuses one that covers something', function () {
    $territory = ($this->territory)();
    ($this->cover)($territory, CoverageKind::State, $this->geo['gj']->id);

    expect(fn () => inCompany($this->acme, fn () => app(TerritoryService::class)->delete($territory, $this->admin)))->toThrow(ValidationException::class, 'Take its coverage off first');

    $area = TerritoryArea::query()->sole();
    inCompany($this->acme, fn () => app(TerritoryService::class)->removeCoverage($area, $this->admin));
    inCompany($this->acme, fn () => app(TerritoryService::class)->delete($territory, $this->admin));

    expect(Territory::query()->count())->toBe(0)->and(Territory::withTrashed()->count())->toBe(1);
});

// ---- which territory covers a place ---------------------------------------------------------------------------

it('finds the most specific cover: customer, then PIN code, block, district, state', function () {
    $customer = makeCustomer($this->acme);
    $byState = ($this->territory)(['code' => 'GJ', 'name' => 'Gujarat']);
    $byDistrict = ($this->territory)(['code' => 'AHD', 'name' => 'Ahmedabad']);
    $byBlock = ($this->territory)(['code' => 'DASK', 'name' => 'Daskroi']);
    $byPin = ($this->territory)(['code' => 'PIN', 'name' => 'Khanpur']);
    $byCustomer = ($this->territory)(['code' => 'KEY', 'name' => 'Key accounts']);

    ($this->cover)($byState, CoverageKind::State, $this->geo['gj']->id);
    ($this->cover)($byDistrict, CoverageKind::District, $this->geo['ahmedabad']->id);
    ($this->cover)($byBlock, CoverageKind::Block, $this->geo['daskroi']->id);
    ($this->cover)($byPin, CoverageKind::PinCode, '380001');
    ($this->cover)($byCustomer, CoverageKind::Customer, $customer->id);

    $resolve = fn (?int $ledger, ?string $pin, ?int $block, ?int $district, ?int $state) => inCompany($this->acme, fn () => app(TerritoryResolver::class)->forPlace($ledger, $pin, $block, $district, $state));
    $g = $this->geo;

    $everything = $resolve($customer->id, '380001', $g['daskroi']->id, $g['ahmedabad']->id, $g['gj']->id);
    expect($everything['territory']->code)->toBe('KEY')->and($everything['kind'])->toBe(CoverageKind::Customer)
        ->and($resolve(null, '380001', $g['daskroi']->id, $g['ahmedabad']->id, $g['gj']->id)['territory']->code)->toBe('PIN')
        ->and($resolve(null, '382110', $g['daskroi']->id, $g['ahmedabad']->id, $g['gj']->id)['territory']->code)->toBe('DASK')
        ->and($resolve(null, '382110', $g['sanand']->id, $g['ahmedabad']->id, $g['gj']->id)['territory']->code)->toBe('AHD')
        ->and($resolve(null, '395003', $g['choryasi']->id, $g['surat']->id, $g['gj']->id)['territory']->code)->toBe('GJ')
        ->and($resolve(null, '411001', $g['haveli']->id, $g['pune']->id, $g['mh']->id))->toBeNull()
        ->and($resolve(null, null, null, null, null))->toBeNull();
});

it('looks only at switched-on territories, and only at the company’s own', function () {
    $north = ($this->territory)();
    ($this->cover)($north, CoverageKind::PinCode, '380001');

    $theirs = ($this->territory)(['owner_user_id' => (string) $this->theirManager->id], $this->theirManager, $this->globex);
    ($this->cover)($theirs, CoverageKind::PinCode, '380001', $this->theirManager, $this->globex);   // the same PIN, another company

    $resolve = fn ($company) => inCompany($company, fn () => app(TerritoryResolver::class)->forPlace(null, '380001', null, null, null));

    expect($resolve($this->acme)['territory']->id)->toBe($north->id)->and($resolve($this->globex)['territory']->id)->toBe($theirs->id);

    inCompany($this->acme, fn () => app(TerritoryService::class)->save($north, ['code' => 'NORTH', 'name' => 'North Gujarat', 'status' => 'inactive'], $this->manager));

    expect($resolve($this->acme))->toBeNull();   // switched off: not looked up (its coverage stays, and stays taken)
});

it('places a lead by the PIN code it carries, and a PIN on several localities by the PIN itself', function () {
    $g = $this->geo;
    $north = ($this->territory)(['owner_user_id' => (string) $this->bde->id]);
    ($this->cover)($north, CoverageKind::PinCode, '382110');   // Bavla (Daskroi) and Sanand Road (Sanand) share it

    foreach ([$g['daskroi'], $g['sanand']] as $block) {
        $lead = makeLead($this->acme, $this->admin, $this->ahd, [
            'state_id' => (string) $g['gj']->id, 'district_id' => (string) $g['ahmedabad']->id, 'block_id' => (string) $block->id, 'pin_code' => '382110',
        ]);

        $match = inCompany($this->acme, fn () => app(TerritoryResolver::class)->forLead($lead));

        expect($match['territory']->id)->toBe($north->id)->and($match['kind'])->toBe(CoverageKind::PinCode)->and($match['territory']->owner->name)->toBe($this->bde->name);
    }
});

// ---- screens and who may -------------------------------------------------------------------------------------

it('gives the permissions to the people who plan the field and shows the screens only to those who may', function () {
    foreach (['view', 'create', 'edit', 'assign'] as $action) {
        expect($this->manager->can("territory.{$action}"))->toBeTrue();
    }

    expect($this->manager->can('territory.delete'))->toBeFalse()->and($this->bde->can('territory.view'))->toBeTrue()->and($this->bde->can('territory.create'))->toBeFalse()
        ->and($this->bde->can('territory.assign'))->toBeFalse()->and($this->accounts->can('territory.view'))->toBeFalse()->and($this->admin->can('territory.delete'))->toBeTrue();

    $territory = ($this->territory)();

    $this->actingAs($this->bde)->get(route('field.territories.index'))->assertOk()->assertSee('North Gujarat');
    $this->actingAs($this->bde)->get(route('field.territories.show', $territory))->assertOk();
    $this->actingAs($this->bde)->get(route('field.territories.create'))->assertForbidden();
    $this->actingAs($this->bde)->get(route('field.territories.edit', $territory))->assertForbidden();

    foreach ([route('field.territories.index'), route('field.territories.show', $territory), route('field.territories.create')] as $url) {
        $this->actingAs($this->accounts)->get($url)->assertForbidden();
    }

    $this->actingAs($this->manager)->get(route('field.territories.create'))->assertOk();
});

it('lists the territories of the branches a person works in, and the company-wide ones — never another company’s', function () {
    ($this->territory)(['code' => 'AHD1', 'name' => 'Ahmedabad side', 'location_id' => (string) $this->ahd->id]);
    ($this->territory)(['code' => 'MUM1', 'name' => 'Mumbai side', 'location_id' => (string) $this->mum->id]);
    ($this->territory)(['code' => 'ALL1', 'name' => 'Company wide']);
    ($this->territory)(['code' => 'THEIRS', 'name' => 'Globex ground'], $this->theirManager, $this->globex);

    Livewire::actingAs($this->bde)->test(TerritoryList::class)->assertSee('Ahmedabad side')->assertSee('Company wide')->assertDontSee('Mumbai side')->assertDontSee('Globex ground');
    Livewire::actingAs($this->manager)->test(TerritoryList::class)->assertSee('Ahmedabad side')->assertSee('Mumbai side')->assertSee('Company wide')->assertDontSee('Globex ground')
        ->set('search', 'mumbai')->assertSee('Mumbai side')->assertDontSee('Ahmedabad side');
});

it('does not show a territory of another company or of a branch the person does not work in', function () {
    $mumbai = ($this->territory)(['code' => 'MUM1', 'name' => 'Mumbai side', 'location_id' => (string) $this->mum->id]);
    $theirs = ($this->territory)(['code' => 'THEIRS', 'name' => 'Globex ground'], $this->theirManager, $this->globex);

    $this->actingAs($this->bde)->get(route('field.territories.show', $mumbai))->assertForbidden();
    $this->actingAs($this->manager)->get(route('field.territories.show', $theirs))->assertNotFound();
});

it('adds the territory through the form and sends the person to its page', function () {
    $form = Livewire::actingAs($this->manager)->test(TerritoryForm::class)
        ->set('territory.code', 'gj-south')->set('territory.name', 'South Gujarat')->set('territory.owner_user_id', (string) $this->bde->id)
        ->call('save')->assertHasNoErrors();

    $territory = Territory::query()->where('code', 'GJ-SOUTH')->firstOrFail();

    $form->assertRedirect(route('field.territories.show', $territory));
    expect($territory->owner_user_id)->toBe($this->bde->id);

    Livewire::actingAs($this->manager)->test(TerritoryForm::class)->set('territory.code', 'GJ-SOUTH')->set('territory.name', 'Another')->call('save')->assertHasErrors(['territory.code']);
    Livewire::actingAs($this->manager)->test(TerritoryForm::class)->call('save')->assertHasErrors(['territory.code', 'territory.name'])->assertSee('Some details need attention');
});

it('gives coverage from the territory’s page and takes it off again', function () {
    $territory = ($this->territory)();

    $page = Livewire::actingAs($this->manager)->test(TerritoryShow::class, ['territory' => $territory])
        ->assertSee('It covers nothing yet')
        ->set('kind', 'district')->set('term', 'Ahmed')->assertSee('Ahmedabad')
        ->call('cover', (string) $this->geo['ahmedabad']->id)->assertHasNoErrors()->assertSee('Added to the territory');

    expect(TerritoryArea::query()->sole()->kind)->toBe(CoverageKind::District);

    $page->set('kind', 'pin_code')->set('term', '380001')->assertSee('Add PIN code 380001')->call('cover', '380001')->assertHasNoErrors();
    $page->set('kind', 'pin_code')->set('term', '123456')->call('cover', '123456')->assertHasErrors('coverage')->assertSee('not in your geography master');

    $area = TerritoryArea::query()->where('kind', 'district')->sole();
    $page->call('uncover', $area->id)->assertSee('Taken off the territory');

    expect(TerritoryArea::query()->count())->toBe(1);
});

it('shows the customers that match and says when another territory already holds the one chosen', function () {
    $customer = makeCustomer($this->acme, 'Shree Ganesh Industries');
    $north = ($this->territory)();
    $south = ($this->territory)(['code' => 'SOUTH', 'name' => 'South Gujarat']);
    ($this->cover)($north, CoverageKind::Customer, $customer->id);

    Livewire::actingAs($this->manager)->test(TerritoryShow::class, ['territory' => $south])
        ->set('kind', 'customer')->set('term', 'a')->assertDontSee('Shree Ganesh')                          // one letter offers nothing
        ->set('term', 'Ganesh')->assertSee('Shree Ganesh Industries')
        ->call('cover', (string) $customer->id)->assertHasErrors('coverage')->assertSee('already covered by North Gujarat');
});

it('lets someone who may only look do nothing to the coverage', function () {
    $territory = ($this->territory)();
    ($this->cover)($territory, CoverageKind::State, $this->geo['gj']->id);
    $area = TerritoryArea::query()->sole();

    Livewire::actingAs($this->bde)->test(TerritoryShow::class, ['territory' => $territory])->assertSee('Gujarat')->assertDontSee('Add coverage')
        ->set('kind', 'state')->call('cover', (string) $this->geo['mh']->id)->assertForbidden();
    Livewire::actingAs($this->bde)->test(TerritoryShow::class, ['territory' => $territory])->call('uncover', $area->id)->assertForbidden();

    expect(TerritoryArea::query()->count())->toBe(1);
});

it('deletes only an empty territory, and only for someone who holds the delete permission', function () {
    $territory = ($this->territory)();
    ($this->cover)($territory, CoverageKind::State, $this->geo['gj']->id);

    Livewire::actingAs($this->admin)->test(TerritoryShow::class, ['territory' => $territory])->call('deleteTerritory')->assertHasErrors('territory');
    expect(Territory::query()->count())->toBe(1);

    TerritoryArea::query()->sole()->forceDelete();

    Livewire::actingAs($this->manager)->test(TerritoryShow::class, ['territory' => $territory->fresh()])->call('deleteTerritory')->assertForbidden();
    Livewire::actingAs($this->admin)->test(TerritoryShow::class, ['territory' => $territory->fresh()])->call('deleteTerritory')->assertRedirect(route('field.territories.index'));

    expect(Territory::query()->count())->toBe(0);
});

it('shows a lead the territory that covers it — to someone who may see territories only', function () {
    $g = $this->geo;
    $north = ($this->territory)(['owner_user_id' => (string) $this->bde->id]);
    ($this->cover)($north, CoverageKind::District, $g['ahmedabad']->id);

    $lead = makeLead($this->acme, $this->admin, $this->ahd, ['state_id' => (string) $g['gj']->id, 'district_id' => (string) $g['ahmedabad']->id, 'pin_code' => '380001']);

    Livewire::actingAs($this->admin)->test(LeadShow::class, ['lead' => $lead->fresh()])->assertSee('Territory')->assertSee('North Gujarat — '.$this->bde->name.' (by District)');

    Role::create(['name' => 'Lead only'])->givePermissionTo(['lead.view', 'lead.create']);
    $plain = makeUser(['Lead only'], [$this->acme], [$this->ahd]);
    $own = makeLead($this->acme, $plain, $this->ahd, ['state_id' => (string) $g['gj']->id, 'district_id' => (string) $g['ahmedabad']->id]);

    Livewire::actingAs($plain)->test(LeadShow::class, ['lead' => $own->fresh()])->assertSee($own->name)->assertDontSee('North Gujarat');

    // a lead nobody covers shows no line at all
    $pune = makeLead($this->acme, $this->admin, $this->ahd, ['state_id' => (string) $g['mh']->id, 'district_id' => (string) $g['pune']->id]);
    Livewire::actingAs($this->admin)->test(LeadShow::class, ['lead' => $pune->fresh()])->assertDontSee('North Gujarat');
});

// ---- route suggestions on the plan form -----------------------------------------------------------------------------

/** A plan form with stops at points on a line north of the branch, in the order given (kilometres). */
function suggestionForm(object $test, array $kilometres, array $extra = [])
{
    $form = Livewire::actingAs($test->manager)->test(RoutePlanForm::class)
        ->set('plan.user_id', (string) $test->bde->id)->set('plan.location_id', (string) $test->ahd->id);

    foreach ($kilometres as $i => $km) {
        $i === 0 || $form->call('addStop');
        $form->set("stops.{$i}.label", "Stop at {$km} km")->set("stops.{$i}.latitude", (string) round(23.0 + $km / 111.19, 6))->set("stops.{$i}.longitude", '72.5');

        foreach ($extra[$i] ?? [] as $field => $value) {
            $form->set("stops.{$i}.{$field}", $value);
        }
    }

    return $form;
}

it('suggests a shorter order, shows what it saves, and changes nothing until it is accepted', function () {
    $this->ahd->forceFill(['latitude' => '23.0000000', 'longitude' => '72.5000000'])->saveQuietly();
    makeTeam($this->acme, $this->manager, [$this->bde]);   // a manager plans the days of the people on their teams

    $form = suggestionForm($this, [30, 10, 40, 20])->call('suggestOrder');

    $form->assertSee('A shorter order is possible')->assertSee('from the branch')->assertSee('Use this order');

    // nothing moved yet
    expect(collect($form->get('stops'))->pluck('label')->all())->toBe(['Stop at 30 km', 'Stop at 10 km', 'Stop at 40 km', 'Stop at 20 km']);

    $form->call('applySuggestion')->assertDontSee('A shorter order is possible');

    expect(collect($form->get('stops'))->pluck('label')->all())->toBe(['Stop at 10 km', 'Stop at 20 km', 'Stop at 30 km', 'Stop at 40 km']);

    // and the plan is still saved by the ordinary Save, through the service
    $form->call('save')->assertHasNoErrors();
});

it('says so when the order is already the shortest', function () {
    $this->ahd->forceFill(['latitude' => '23.0000000', 'longitude' => '72.5000000'])->saveQuietly();

    suggestionForm($this, [10, 20, 30])->call('suggestOrder')->assertSee('already the shortest')->assertDontSee('Use this order');
});

it('starts from the first stop when the branch has no coordinates, and keeps it first', function () {
    $form = suggestionForm($this, [30, 10, 40, 20])->call('suggestOrder')->assertSee('from your first stop');
    $form->call('applySuggestion');

    expect(collect($form->get('stops'))->pluck('label')->first())->toBe('Stop at 30 km');
});

it('leaves a stop with no place on the map where it was, and says so', function () {
    $this->ahd->forceFill(['latitude' => '23.0000000', 'longitude' => '72.5000000'])->saveQuietly();

    $form = suggestionForm($this, [30, 10, 20])->call('addStop')->set('stops.3.label', 'Reminder only')->call('suggestOrder');

    $form->assertSee('left where you put it: Reminder only');
    $form->call('applySuggestion');

    expect(collect($form->get('stops'))->pluck('label')->all())->toBe(['Stop at 10 km', 'Stop at 20 km', 'Stop at 30 km', 'Reminder only']);
});

it('warns when the suggested order would visit a stop before a window that starts earlier', function () {
    $this->ahd->forceFill(['latitude' => '23.0000000', 'longitude' => '72.5000000'])->saveQuietly();

    suggestionForm($this, [20, 10], [0 => ['visit_from' => '09:00', 'visit_to' => '10:00'], 1 => ['visit_from' => '15:00', 'visit_to' => '16:00']])
        ->call('suggestOrder')->assertSee('A shorter order is possible')->assertSee('check the visit times');
});

it('forgets a suggestion once the stops have changed, and will not apply it', function () {
    $this->ahd->forceFill(['latitude' => '23.0000000', 'longitude' => '72.5000000'])->saveQuietly();

    $form = suggestionForm($this, [30, 10, 40, 20])->call('suggestOrder')->assertSee('A shorter order is possible');

    $form->set('stops.1.latitude', '23.5')->assertDontSee('A shorter order is possible');
    $form->call('applySuggestion')->assertHasErrors('suggestion')->assertSee('Ask again');

    expect(collect($form->get('stops'))->pluck('label')->all())->toBe(['Stop at 30 km', 'Stop at 10 km', 'Stop at 40 km', 'Stop at 20 km']);
});

it('cannot be handed an order by the browser', function () {
    suggestionForm($this, [30, 10])->set('suggestion', ['order' => ['x'], 'hash' => 'y']);
})->throws(CannotUpdateLockedPropertyException::class);

it('keeps the suggestion apart from the geofence: nothing is saved by asking', function () {
    suggestionForm($this, [30, 10])->call('suggestOrder');

    expect(DB::table('route_plans')->count())->toBe(0)->and(DB::table('route_plan_stops')->count())->toBe(0);
});

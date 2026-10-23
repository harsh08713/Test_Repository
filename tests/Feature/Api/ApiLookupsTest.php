<?php

use App\Modules\Masters\Models\Unit;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->geo = seedGeography($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);
    $this->purchase = makeUser(['Purchase User'], [$this->acme]);
    $this->names = fn ($response) => collect($response->json('data'))->pluck('name')->all();
});

afterEach(fn () => Carbon::setTestNow());

// ---- items: only what a transaction may use -----------------------------------------------------------

it('offers approved, active items only — never one still being set up, awaiting approval or switched off', function () {
    $approved = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Approved Bolt']], 'approved');
    makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Draft Bolt']], 'draft');
    makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Pending Bolt']], 'submitted');
    $off = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Switched-off Bolt']], 'approved');
    DB::table('items')->where('id', $off->id)->update(['status' => 'inactive']);
    makeItem($this->globex, makeUser(['Purchase User'], [$this->globex]), ['item' => ['name' => 'Globex Bolt']], 'approved');

    $response = apiCall('GET', '/api/v1/lookups/items', as: $this->bde)->assertOk();

    expect(($this->names)($response))->toBe(['Approved Bolt'])
        ->and($response->json('data.0'))->toMatchArray(['id' => $approved->id, 'hsn_code' => '7318', 'active' => true])
        ->and($response->json('data.0.base_unit.code'))->toBe('NOS')->and($response->json('data.0.units.0'))->toMatchArray(['code' => 'BOX', 'factor' => '100.000000'])
        ->and($response->getContent())->not->toContain('Draft Bolt')->not->toContain('Pending Bolt')->not->toContain('Switched-off Bolt')->not->toContain('Globex Bolt');
});

it('searches items by name, code and alias, and pages them within bounds', function () {
    foreach (['Alpha Washer', 'Beta Washer', 'Gamma Nut'] as $name) {
        makeItem($this->acme, $this->purchase, ['item' => ['name' => $name]], 'approved');
    }

    expect(($this->names)(apiCall('GET', '/api/v1/lookups/items', ['q' => 'washer'], $this->bde)))->toBe(['Alpha Washer', 'Beta Washer']);

    $page = apiCall('GET', '/api/v1/lookups/items', ['per_page' => 2, 'page' => 2], $this->bde)->assertOk();
    expect($page->json('data'))->toHaveCount(1)->and($page->json('meta.pagination.total'))->toBe(3);

    apiCall('GET', '/api/v1/lookups/items', ['per_page' => 500], $this->bde)->assertStatus(422);
});

it('syncs items by what changed, and names — by id only — the ones that must be dropped', function () {
    $stays = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Stays Bolt']], 'approved');
    $goes = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Goes Bolt']], 'approved');
    $draft = makeItem($this->acme, $this->purchase, ['item' => ['name' => 'Secret Draft Bolt']], 'draft');

    Carbon::setTestNow('2026-10-12 12:00:00');
    DB::table('items')->where('id', $goes->id)->update(['status' => 'inactive', 'updated_at' => now()]);
    DB::table('items')->where('id', $draft->id)->update(['updated_at' => now()]);

    $sync = apiCall('GET', '/api/v1/lookups/items', ['updated_since' => '2026-10-12T11:00:00+05:30'], $this->bde)->assertOk();

    expect($sync->json('data'))->toBe([])->and($sync->json('meta.removed_ids'))->toBe([$goes->id, $draft->id])
        ->and($sync->getContent())->not->toContain('Secret Draft Bolt')->not->toContain('Goes Bolt')
        ->and($sync->json('meta.server_time'))->toBe('2026-10-12T12:00:00+05:30');

    $full = apiCall('GET', '/api/v1/lookups/items', ['updated_since' => '2026-10-12T08:00:00+05:30'], $this->bde)->json('data');
    expect(collect($full)->pluck('id')->all())->toBe([$stays->id]);
});

it('keeps item look-ups to the people who may look at items and use the API', function () {
    makeItem($this->acme, $this->purchase, [], 'approved');

    apiCall('GET', '/api/v1/lookups/items', as: $this->telecaller)->assertOk();
    apiCall('GET', '/api/v1/lookups/items', as: $this->purchase)->assertForbidden()->assertJsonPath('error.code', 'api_access_denied');
});

// ---- the plain masters ----------------------------------------------------------------------------------

it('lists units, lead sources, follow-up outcomes and lost reasons — what is in use, and with a sync also what was switched off', function () {
    $units = apiCall('GET', '/api/v1/lookups/units', as: $this->bde)->assertOk();
    expect(collect($units->json('data'))->firstWhere('code', 'NOS'))->toMatchArray(['name' => 'Numbers', 'decimal_allowed' => false, 'active' => true]);

    $sources = apiCall('GET', '/api/v1/lookups/lead-sources', as: $this->bde)->assertOk();
    expect(($this->names)($sources))->toContain('Field', 'Reference', 'Website');

    $outcomes = apiCall('GET', '/api/v1/lookups/followup-outcomes', as: $this->bde)->assertOk();
    expect(collect($outcomes->json('data'))->firstWhere('code', 'CALL_BACK_LATER'))->toMatchArray(['counts_as_contact' => true, 'needs_next_followup' => true])
        ->and(collect($outcomes->json('data'))->firstWhere('code', 'NOT_AVAILABLE'))->toMatchArray(['counts_as_contact' => false, 'needs_next_followup' => false]);

    expect(($this->names)(apiCall('GET', '/api/v1/lookups/lost-reasons', as: $this->bde)))->toContain('Price too high');

    // switch one off: gone from the list, but flagged for a syncing app
    Carbon::setTestNow('2026-10-12 12:00:00');
    DB::table('lost_reasons')->where('company_id', $this->acme->id)->where('code', 'PRICE')->update(['status' => 'inactive', 'updated_at' => now()]);

    expect(($this->names)(apiCall('GET', '/api/v1/lookups/lost-reasons', as: $this->bde)))->not->toContain('Price too high');
    $sync = apiCall('GET', '/api/v1/lookups/lost-reasons', ['updated_since' => '2026-10-12T11:00:00+05:30'], $this->bde)->json('data');
    expect($sync)->toHaveCount(1)->and($sync[0])->toMatchArray(['name' => 'Price too high', 'active' => false]);
});

it('shows only this company’s reference data', function () {
    Unit::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->globex->id)->where('code', 'NOS')->update(['name' => 'Globex-only name']);

    expect(apiCall('GET', '/api/v1/lookups/units', as: $this->bde)->getContent())->not->toContain('Globex-only name');
});

// ---- PIN codes, branches, people -------------------------------------------------------------------------

it('resolves a PIN code to its state, district and block where there is exactly one, and says when it is ambiguous or unknown', function () {
    $one = apiCall('GET', '/api/v1/lookups/pin-codes/380001', as: $this->bde)->assertOk()->json('data');
    expect($one)->toMatchArray(['pin_code' => '380001', 'found' => true, 'ambiguous' => false])
        ->and($one['state']['name'])->toBe('Gujarat')->and($one['district']['name'])->toBe('Ahmedabad')->and($one['block']['name'])->toBe('Daskroi')
        ->and($one['localities'][0]['area'])->toBe('Khanpur');

    $many = apiCall('GET', '/api/v1/lookups/pin-codes/382110', as: $this->bde)->json('data');
    expect($many['found'])->toBeTrue()->and($many['ambiguous'])->toBeTrue()->and($many['block'])->toBeNull()->and($many['district']['name'])->toBe('Ahmedabad')
        ->and($many['localities'])->toHaveCount(2);

    expect(apiCall('GET', '/api/v1/lookups/pin-codes/999999', as: $this->bde)->json('data'))->toMatchArray(['found' => false, 'state' => null, 'localities' => []]);

    apiCall('GET', '/api/v1/lookups/pin-codes/38000', as: $this->bde)->assertStatus(422)->assertJsonPath('error.fields.pin.0', 'A PIN code is six digits and does not start with 0.');
    apiCall('GET', '/api/v1/lookups/pin-codes/abc', as: $this->bde)->assertStatus(422);
});

it('offers a person the branches they work in, and the people they may hand leads to', function () {
    expect(collect(apiCall('GET', '/api/v1/lookups/branches', as: $this->bde)->json('data'))->pluck('code')->all())->toBe(['AHD'])
        ->and(collect(apiCall('GET', '/api/v1/lookups/branches', as: $this->manager)->json('data'))->pluck('code')->all())->toBe(['AHD', 'MUM']);

    $staff = fn ($as) => collect(apiCall('GET', '/api/v1/lookups/staff', as: $as)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($staff($this->bde))->toBe([$this->bde->id])
        ->and($staff($this->manager))->toBe(collect([$this->manager->id, $this->bde->id])->sort()->values()->all())
        ->and($staff($this->admin))->toContain($this->bde2->id, $this->telecaller->id, $this->admin->id);
});

it('shows the lookups only to people who work with leads', function () {
    $ops = makeUser(['Operations User'], [$this->acme]);

    foreach (['units', 'lead-sources', 'followup-outcomes', 'lost-reasons', 'branches', 'staff', 'pin-codes/380001', 'items'] as $path) {
        apiCall('GET', "/api/v1/lookups/{$path}", as: $ops)->assertForbidden();
        apiCall('GET', "/api/v1/lookups/{$path}")->assertUnauthorized();
    }
});

<?php

use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Exceptions\TenantAccessException;
use App\Modules\Shared\Http\Livewire\Settings\ApprovalLevels;
use App\Modules\Shared\Http\Livewire\Settings\General;
use App\Modules\Shared\Http\Livewire\Settings\NumberSeriesIndex;
use App\Modules\Shared\Models\ApprovalLevel;
use App\Modules\Shared\Models\NumberSeries;
use App\Modules\Shared\Models\Setting;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Services\NumberSeriesService;
use App\Modules\Shared\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->settings = app(SettingsService::class);
});

// ---- SettingsService -----------------------------------------------------------------------------

it('falls back to the documented defaults', function () {
    expect($this->settings->get('approvals.prevent_self_approval', $this->acme->id))->toBeTrue()
        ->and($this->settings->get('approvals.item_approver', $this->acme->id))->toBe('accounts')
        ->and($this->settings->get('geofence.radius_m', $this->acme->id))->toBe(500)
        ->and($this->settings->get('geofence.manager_override', $this->acme->id))->toBeFalse()
        ->and($this->settings->get('credit_limit.behaviour', $this->acme->id))->toBe('warn')
        ->and($this->settings->get('integrations.indiamart_api_key', $this->acme->id))->toBeNull();
});

it('stores typed values per company', function () {
    $this->settings->set('geofence.radius_m', '750', $this->acme->id);
    $this->settings->set('geofence.manager_override', true, $this->acme->id);
    $this->settings->set('credit_limit.behaviour', 'block', $this->acme->id);

    expect($this->settings->get('geofence.radius_m', $this->acme->id))->toBe(750)
        ->and($this->settings->get('geofence.manager_override', $this->acme->id))->toBeTrue()
        ->and($this->settings->get('credit_limit.behaviour', $this->acme->id))->toBe('block')
        ->and($this->settings->get('geofence.radius_m', $this->globex->id))->toBe(500);   // other company unaffected
});

it('encrypts secrets at rest and decrypts them for server-side use', function () {
    $this->settings->set('integrations.indiamart_api_key', 'sk-live-12345', $this->acme->id);

    $raw = Setting::query()->where('key', 'integrations.indiamart_api_key')->sole();

    expect($raw->value)->not->toContain('sk-live-12345')
        ->and($raw->is_encrypted)->toBeTrue()
        ->and(Crypt::decryptString($raw->value))->toBe('sk-live-12345')
        ->and($this->settings->get('integrations.indiamart_api_key', $this->acme->id))->toBe('sk-live-12345')
        ->and($this->settings->isSet('integrations.indiamart_api_key', $this->acme->id))->toBeTrue()
        ->and($this->settings->isSet('integrations.indiamart_api_key', $this->globex->id))->toBeFalse();
});

it('clears a secret when given null', function () {
    $this->settings->set('integrations.justdial_api_key', 'abc', $this->acme->id);
    $this->settings->set('integrations.justdial_api_key', null, $this->acme->id);

    expect($this->settings->get('integrations.justdial_api_key', $this->acme->id))->toBeNull()
        ->and($this->settings->isSet('integrations.justdial_api_key', $this->acme->id))->toBeFalse();
});

it('rejects unknown keys and invalid options', function () {
    expect(fn () => $this->settings->get('nope.nothing', $this->acme->id))->toThrow(InvalidArgumentException::class, 'Unknown setting');
    expect(fn () => $this->settings->set('credit_limit.behaviour', 'explode', $this->acme->id))->toThrow(InvalidArgumentException::class);
});

it('refuses to write another company’s settings', function () {
    $this->actingAs($this->admin);

    $this->settings->set('geofence.radius_m', 900, $this->globex->id);
})->throws(TenantAccessException::class);

// ---- General settings screen ---------------------------------------------------------------------

it('saves settings from the screen and audits the change without the secret', function () {
    Livewire::actingAs($this->admin)->test(General::class)
        ->set('values.geofence__radius_m', 800)
        ->set('values.approvals__prevent_self_approval', false)
        ->set('values.credit_limit__behaviour', 'block')
        ->set('secrets.integrations__indiamart_api_key', 'super-secret-key-999')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->settings->get('geofence.radius_m', $this->acme->id))->toBe(800)
        ->and($this->settings->get('approvals.prevent_self_approval', $this->acme->id))->toBeFalse()
        ->and($this->settings->get('credit_limit.behaviour', $this->acme->id))->toBe('block')
        ->and($this->settings->get('integrations.indiamart_api_key', $this->acme->id))->toBe('super-secret-key-999');

    $logged = Activity::query()->withoutGlobalScopes()->where('log_name', 'settings')->latest('id')->first();
    expect($logged->company_id)->toBe($this->acme->id)
        ->and($logged->properties['attributes']['geofence.radius_m'])->toBe(800)
        ->and(json_encode($logged->properties))->not->toContain('super-secret-key-999')
        ->and($logged->properties['secrets'])->toBe(['integrations.indiamart_api_key (updated)']);
});

it('never sends a saved secret back to the browser', function () {
    $this->settings->set('integrations.indiamart_api_key', 'hidden-value-123', $this->acme->id);

    Livewire::actingAs($this->admin)->test(General::class)
        ->assertSee('Saved')
        ->assertDontSee('hidden-value-123')
        ->assertDontSeeHtml('hidden-value-123');
});

it('keeps a saved secret when the field is left blank and removes it on request', function () {
    $this->settings->set('integrations.indiamart_api_key', 'keep-me', $this->acme->id);

    Livewire::actingAs($this->admin)->test(General::class)->call('save');
    expect($this->settings->get('integrations.indiamart_api_key', $this->acme->id))->toBe('keep-me');

    Livewire::actingAs($this->admin)->test(General::class)->set('clear.integrations__indiamart_api_key', true)->call('save');
    expect($this->settings->get('integrations.indiamart_api_key', $this->acme->id))->toBeNull();
});

it('validates setting values', function () {
    Livewire::actingAs($this->admin)->test(General::class)
        ->set('values.geofence__radius_m', 10)->call('save')->assertHasErrors(['values.geofence__radius_m'])
        ->set('values.geofence__radius_m', 500)
        ->set('values.credit_limit__behaviour', 'explode')->call('save')->assertHasErrors(['values.credit_limit__behaviour']);
});

it('lets a view-only user read the settings but not save them', function () {
    Role::create(['name' => 'Settings reader'])->givePermissionTo('setting.view');
    $reader = makeUser(['Settings reader'], [$this->acme]);

    Livewire::actingAs($reader)->test(General::class)->assertDontSee('Save settings')->call('save')->assertForbidden();
});

it('shows no settings page to a user without permission', function () {
    Livewire::actingAs(makeUser(['Tele-caller'], [$this->acme]))->test(General::class)->assertForbidden();
});

// ---- Approval levels screen ----------------------------------------------------------------------

it('saves an ordered approval chain that the engine then uses', function () {
    Livewire::actingAs($this->admin)->test(ApprovalLevels::class)
        ->set('documentType', 'item')
        ->call('addLevel')->call('addLevel')
        ->set('levels.0.name', 'Finance')->set('levels.0.approver_role', 'Accounts User')
        ->set('levels.1.approver_role', 'Approver')
        ->call('save')->assertHasNoErrors();

    $rows = ApprovalLevel::query()->where('document_type', 'item')->orderBy('level')->get();
    expect($rows->pluck('level')->all())->toBe([1, 2])
        ->and($rows->pluck('approver_role')->all())->toBe(['Accounts User', 'Approver'])
        ->and($rows->first()->name)->toBe('Finance');

    // The engine picks the chain up for the next submission.
    $doc = makeDocument($this->acme, $this->ahd);
    $maker = makeUser(['Purchase User'], [$this->acme]);
    $levels = app(ApprovalService::class)->submit($doc, $maker);

    expect($levels->pluck('approver_role')->all())->toBe(['Accounts User', 'Approver']);
});

it('replaces the chain on save and can go back to the single default level', function () {
    $component = Livewire::actingAs($this->admin)->test(ApprovalLevels::class)
        ->set('documentType', 'rate')->call('addLevel')->set('levels.0.approver_role', 'Approver')->call('save');
    expect(ApprovalLevel::query()->where('document_type', 'rate')->count())->toBe(1);

    $component->call('removeLevel', 0)->call('save');
    expect(ApprovalLevel::query()->where('document_type', 'rate')->count())->toBe(0);
});

it('loads the saved chain when the document type is changed', function () {
    $row = new ApprovalLevel(['document_type' => 'quotation', 'level' => 1, 'approver_role' => 'Sales Manager']);
    $row->forceFill(['company_id' => $this->acme->id])->save();

    Livewire::actingAs($this->admin)->test(ApprovalLevels::class)
        ->set('documentType', 'quotation')->assertSet('levels.0.approver_role', 'Sales Manager')
        ->set('documentType', 'deal')->assertSet('levels', []);
});

it('requires exactly one of role or user on every level', function () {
    $user = makeUser(['Approver'], [$this->acme]);

    $c = Livewire::actingAs($this->admin)->test(ApprovalLevels::class)->set('documentType', 'item')->call('addLevel');
    $c->call('save')->assertHasErrors(['levels.0.approver_role']);

    $c->set('levels.0.approver_role', 'Approver')->set('levels.0.approver_user_id', (string) $user->id)
        ->call('save')->assertHasErrors(['levels.0.approver_role']);
});

it('rejects an unknown role and a user from another company', function () {
    $foreign = makeUser(['Approver'], [$this->globex]);

    $c = Livewire::actingAs($this->admin)->test(ApprovalLevels::class)->set('documentType', 'item')->call('addLevel');

    $c->set('levels.0.approver_role', 'No Such Role')->call('save')->assertHasErrors(['levels.0.approver_role']);

    $c->set('levels.0.approver_role', '')->set('levels.0.approver_user_id', (string) $foreign->id)
        ->call('save')->assertHasErrors(['levels.0.approver_user_id']);
});

it('keeps each company’s chain separate', function () {
    $row = new ApprovalLevel(['document_type' => 'item', 'level' => 1, 'approver_role' => 'Approver']);
    $row->forceFill(['company_id' => $this->globex->id])->save();

    Livewire::actingAs($this->admin)->test(ApprovalLevels::class)->set('documentType', 'item')->assertSet('levels', []);
});

it('lets only users with the edit permission change levels', function () {
    Role::create(['name' => 'Settings reader'])->givePermissionTo('setting.view');
    $reader = makeUser(['Settings reader'], [$this->acme]);

    Livewire::actingAs($reader)->test(ApprovalLevels::class)->call('addLevel')->assertForbidden();
});

// ---- Number series screen ------------------------------------------------------------------------

it('lists counters and shows the next number', function () {
    $date = CarbonImmutable::create(2026, 10, 12);
    app(NumberSeriesService::class)->next(DocumentType::Quotation, $this->ahd, $date);

    Livewire::actingAs($this->admin)->test(NumberSeriesIndex::class)
        ->assertSee('Quotation')->assertSee('26-27')->assertSee('QT/AHD/26-27/00002');
});

it('changes the prefix and padding of a counter', function () {
    app(NumberSeriesService::class)->next(DocumentType::Quotation, $this->ahd, CarbonImmutable::create(2026, 10, 12));
    $series = NumberSeries::query()->sole();

    Livewire::actingAs($this->admin)->test(NumberSeriesIndex::class)
        ->call('edit', $series->id)->set('prefix', 'qtn')->set('padding', 6)->call('save')->assertHasNoErrors();

    expect(app(NumberSeriesService::class)->next(DocumentType::Quotation, $this->ahd, CarbonImmutable::create(2026, 10, 12)))
        ->toBe('QTN/AHD/26-27/000002');
});

it('moves a counter forward but never back', function () {
    app(NumberSeriesService::class)->next(DocumentType::SalesOrder, $this->ahd, CarbonImmutable::create(2026, 10, 12));
    app(NumberSeriesService::class)->next(DocumentType::SalesOrder, $this->ahd, CarbonImmutable::create(2026, 10, 12));
    $series = NumberSeries::query()->sole();
    expect($series->last_number)->toBe(2);

    $component = Livewire::actingAs($this->admin)->test(NumberSeriesIndex::class)->call('edit', $series->id);

    $component->set('nextNumber', 2)->call('save')->assertHasErrors(['nextNumber']);   // would reuse number 2
    expect($series->fresh()->last_number)->toBe(2);

    $component->set('nextNumber', 500)->call('save')->assertHasNoErrors();
    expect($series->fresh()->last_number)->toBe(499)
        ->and(app(NumberSeriesService::class)->next(DocumentType::SalesOrder, $this->ahd, CarbonImmutable::create(2026, 10, 12)))->toBe('SO/AHD/26-27/00500');

    $entry = Activity::query()->withoutGlobalScopes()->where('event', 'counter_moved')->latest('id')->first();
    expect($entry->properties['old']['last_number'])->toBe(2)->and($entry->properties['attributes']['last_number'])->toBe(499);
});

it('adds a counter that starts at a chosen number and refuses a duplicate', function () {
    $component = Livewire::actingAs($this->admin)->test(NumberSeriesIndex::class)
        ->set('newType', 'sales_order')->set('newLocation', (string) $this->mum->id)
        ->set('newFinancialYear', '26-27')->set('newStart', 150)->call('add')->assertHasNoErrors();

    expect(app(NumberSeriesService::class)->next(DocumentType::SalesOrder, $this->mum, CarbonImmutable::create(2026, 10, 12)))
        ->toBe('SO/MUM/26-27/00150');

    $component->set('newType', 'sales_order')->set('newLocation', (string) $this->mum->id)
        ->set('newFinancialYear', '26-27')->call('add')->assertHasErrors(['newType']);
});

it('validates new counters', function () {
    Livewire::actingAs($this->admin)->test(NumberSeriesIndex::class)
        ->set('newType', 'ledger')->set('newFinancialYear', '2026')->call('add')
        ->assertHasErrors(['newType', 'newFinancialYear']);
});

it('cannot touch a counter of another company', function () {
    $glxLocation = makeLocation($this->globex, 'PUN');
    app(NumberSeriesService::class)->next(DocumentType::Lead, $glxLocation, CarbonImmutable::create(2026, 10, 12));
    $foreign = NumberSeries::withoutGlobalScopes()->sole();

    Livewire::actingAs($this->admin)->test(NumberSeriesIndex::class)->call('edit', $foreign->id)->assertNotFound();
});

it('does not let a branch user add a counter for a branch they cannot see', function () {
    $branchUser = makeUser(['Company Admin'], [$this->acme], locations: [$this->ahd]);

    Livewire::actingAs($branchUser)->test(NumberSeriesIndex::class)
        ->set('newType', 'lead')->set('newLocation', (string) $this->mum->id)->set('newFinancialYear', '26-27')
        ->call('add')->assertNotFound();

    expect(DB::table('number_series')->count())->toBe(0);
});

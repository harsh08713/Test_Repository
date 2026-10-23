<?php

use App\Modules\Masters\Http\Livewire\Companies\CompanyForm;
use App\Modules\Masters\Http\Livewire\Companies\CompanyList;
use App\Modules\Masters\Models\AccountGroup;
use App\Modules\Masters\Models\State;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Rules\Gstin;
use App\Modules\Shared\Services\NumberSeriesService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake('local');

    $this->acme = makeCompany('ACME', ['name' => 'Acme Industries']);
    $this->globex = makeCompany('GLOBEX', ['name' => 'Globex Ltd']);
    $this->ahd = makeLocation($this->acme, 'AHD');

    $this->super = makeUser(['Super Admin'], [$this->acme, $this->globex]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
});

// gstinFor() (a GSTIN with a correct check character) is a shared helper in tests/Pest.php.

function validCompany(array $overrides = []): array
{
    return [
        'code' => 'NEWCO', 'name' => 'New Company Pvt Ltd', 'legal_name' => 'New Company Private Limited',
        'state_gst_code' => '24', 'city' => 'Ahmedabad', 'pin_code' => '380001',
        'email' => 'accounts@newco.test', 'phone' => '079-40001234',
        'financial_year_start_month' => 4, 'base_currency' => 'INR', 'status' => 'active',
        ...$overrides,
    ];
}

function fillCompany($component, array $values)
{
    foreach ($values as $property => $value) {
        $component->set($property, $value);
    }

    return $component;
}

// ---- creating ------------------------------------------------------------------------------------

it('creates a company, gives the creator access and seeds its reference data', function () {
    fillCompany(Livewire::actingAs($this->super)->test(CompanyForm::class), validCompany(['gstin' => gstinFor('24', 'AAACN1234B'), 'pan' => 'AAACN1234B']))
        ->call('save')->assertHasNoErrors()->assertRedirect(route('masters.companies.index'));

    $company = Company::query()->where('code', 'NEWCO')->firstOrFail();

    expect($company->name)->toBe('New Company Pvt Ltd')
        ->and($company->state)->toBe('Gujarat')->and($company->state_code)->toBe('24')
        ->and($company->financial_year_start_month)->toBe(4)
        ->and($company->created_by)->toBe($this->super->id)
        ->and(DB::table('company_user')->where('company_id', $company->id)->where('user_id', $this->super->id)->value('all_locations'))->toBe(1)
        ->and(State::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->count())->toBe(36)
        ->and(AccountGroup::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company->id)->count())->toBeGreaterThan(20);
});

it('does not let a Company Admin create companies', function () {
    Livewire::actingAs($this->admin)->test(CompanyForm::class)->assertForbidden();
    Livewire::actingAs($this->admin)->test(CompanyList::class)->assertDontSee('Add company');
});

it('forbids users with no company permissions', function () {
    Livewire::actingAs(makeUser(['Tele-caller'], [$this->acme]))->test(CompanyList::class)->assertForbidden();
});

// ---- identifiers ---------------------------------------------------------------------------------

it('rejects a malformed or mistyped GSTIN', function (string $gstin) {
    fillCompany(Livewire::actingAs($this->super)->test(CompanyForm::class), validCompany(['gstin' => $gstin]))
        ->call('save')->assertHasErrors(['gstin']);

    expect(Company::query()->where('code', 'NEWCO')->exists())->toBeFalse();
})->with([
    'wrong length' => ['27AAACN1234B1Z'],
    'bad check character' => ['24AAACN1234B1Z0'],
    'garbage' => ['not-a-gstin'],
]);

it('requires a GSTIN to belong to the selected state', function () {
    fillCompany(Livewire::actingAs($this->super)->test(CompanyForm::class), validCompany(['state_gst_code' => '24', 'gstin' => gstinFor('27', 'AAACN1234B')]))
        ->call('save')->assertHasErrors(['gstin']);
});

it('requires the PAN to match the PAN inside the GSTIN', function () {
    fillCompany(Livewire::actingAs($this->super)->test(CompanyForm::class), validCompany(['gstin' => gstinFor('24', 'AAACN1234B'), 'pan' => 'AAACX9999Z']))
        ->call('save')->assertHasErrors(['pan']);
});

it('fills the PAN and state from a GSTIN that is typed first', function () {
    Livewire::actingAs($this->super)->test(CompanyForm::class)
        ->set('gstin', strtolower(gstinFor('27', 'AAACN1234B')))
        ->assertSet('gstin', gstinFor('27', 'AAACN1234B'))
        ->assertSet('pan', 'AAACN1234B')
        ->assertSet('state_gst_code', '27');
});

it('does not overwrite a PAN or state the user already chose', function () {
    Livewire::actingAs($this->super)->test(CompanyForm::class)
        ->set('pan', 'AAACX9999Z')->set('state_gst_code', '24')
        ->set('gstin', gstinFor('27', 'AAACN1234B'))
        ->assertSet('pan', 'AAACX9999Z')->assertSet('state_gst_code', '24');
});

it('validates PAN, CIN, PIN code, phone and email formats', function () {
    fillCompany(Livewire::actingAs($this->super)->test(CompanyForm::class), validCompany([
        'pan' => 'BAD', 'cin' => 'BAD', 'pin_code' => '12', 'phone' => 'call me', 'email' => 'not-an-email',
    ]))->call('save')->assertHasErrors(['pan', 'cin', 'pin_code', 'phone', 'email']);
});

it('keeps company codes and GSTINs unique', function () {
    $existing = gstinFor('24', 'AAACN1234B');
    $this->acme->forceFill(['gstin' => $existing])->save();

    fillCompany(Livewire::actingAs($this->super)->test(CompanyForm::class), validCompany(['code' => 'ACME']))
        ->call('save')->assertHasErrors(['code']);

    fillCompany(Livewire::actingAs($this->super)->test(CompanyForm::class), validCompany(['gstin' => $existing]))
        ->call('save')->assertHasErrors(['gstin']);
});

it('keeps a deleted company’s code reserved, matching the database key', function () {
    $this->globex->delete();

    fillCompany(Livewire::actingAs($this->super)->test(CompanyForm::class), validCompany(['code' => 'GLOBEX']))
        ->call('save')->assertHasErrors(['code']);
});

it('validates the code format, financial-year month, currency, state and status', function () {
    fillCompany(Livewire::actingAs($this->super)->test(CompanyForm::class), validCompany([
        'code' => 'bad code!', 'financial_year_start_month' => 13, 'base_currency' => 'XXX', 'state_gst_code' => '99', 'status' => 'archived',
    ]))->call('save')->assertHasErrors(['code', 'financial_year_start_month', 'base_currency', 'state_gst_code', 'status']);
});

// ---- editing -------------------------------------------------------------------------------------

it('lets a Company Admin edit their own company and records the change', function () {
    Livewire::actingAs($this->admin)->test(CompanyForm::class, ['company' => $this->acme])
        ->assertSet('name', 'Acme Industries')
        ->set('phone', '079-5551234')->set('city', 'Gandhinagar')
        ->call('save')->assertHasNoErrors();

    expect($this->acme->fresh())->phone->toBe('079-5551234')->city->toBe('Gandhinagar');

    $entry = Activity::query()->withoutGlobalScopes()->where('subject_type', 'company')->where('event', 'updated')->latest('id')->first();
    expect($entry->causer_id)->toBe($this->admin->id)->and($entry->company_id)->toBe($this->acme->id)
        ->and($entry->properties['attributes']['city'])->toBe('Gandhinagar');
});

it('does not open a company the user does not belong to', function () {
    // Through the URL, route binding is tenant-scoped, so the company simply does not exist for them...
    $this->actingAs($this->admin)->get(route('masters.companies.edit', $this->globex))->assertNotFound();

    // ...and even if a record reaches the component some other way, the policy refuses it.
    Livewire::actingAs($this->admin)->test(CompanyForm::class, ['company' => $this->globex])->assertForbidden();
});

it('forbids editing for someone who may only view', function () {
    Role::create(['name' => 'Company viewer'])->givePermissionTo('company.view');
    $viewer = makeUser(['Company viewer'], [$this->acme]);

    Livewire::actingAs($viewer)->test(CompanyList::class)->assertSee('Acme Industries')->assertDontSeeHtml('/edit');
    Livewire::actingAs($viewer)->test(CompanyForm::class, ['company' => $this->acme])->assertForbidden();
});

it('freezes the financial-year start once document numbers exist', function () {
    app(NumberSeriesService::class)->next(DocumentType::Lead, $this->ahd);

    Livewire::actingAs($this->admin)->test(CompanyForm::class, ['company' => $this->acme])
        ->assertSee('Fixed, because document numbers have already been issued')
        ->set('financial_year_start_month', 1)
        ->call('save')->assertHasNoErrors();

    expect($this->acme->fresh()->financial_year_start_month)->toBe(4);   // the posted value was ignored
});

it('lets the financial-year start change while nothing is numbered', function () {
    Livewire::actingAs($this->admin)->test(CompanyForm::class, ['company' => $this->acme])
        ->set('financial_year_start_month', 1)->call('save')->assertHasNoErrors();

    expect($this->acme->fresh()->financial_year_start_month)->toBe(1);
});

// ---- logo ------------------------------------------------------------------------------------------

it('stores a logo privately under a random name', function () {
    Livewire::actingAs($this->admin)->test(CompanyForm::class, ['company' => $this->acme])
        ->set('logo', UploadedFile::fake()->image('../../evil name.png', 300, 120))
        ->call('save')->assertHasNoErrors();

    $path = $this->acme->fresh()->logo_path;

    expect($path)->toStartWith("company-logos/{$this->acme->id}/")->toEndWith('.png')
        ->and($path)->not->toContain('evil')->not->toContain('..')
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('rejects logos that are not safe images', function (string $name, int $kilobytes, string $mime) {
    Livewire::actingAs($this->admin)->test(CompanyForm::class, ['company' => $this->acme])
        ->set('logo', UploadedFile::fake()->create($name, $kilobytes, $mime))
        ->call('save')->assertHasErrors(['logo']);

    expect($this->acme->fresh()->logo_path)->toBeNull();
})->with([
    'svg (can carry scripts)' => ['logo.svg', 10, 'image/svg+xml'],
    'php disguised' => ['logo.php', 10, 'application/x-php'],
    'html' => ['logo.html', 10, 'text/html'],
    'pdf' => ['logo.pdf', 10, 'application/pdf'],
]);

it('rejects an oversized or over-large logo', function () {
    Livewire::actingAs($this->admin)->test(CompanyForm::class, ['company' => $this->acme])
        ->set('logo', UploadedFile::fake()->image('big.png', 300, 100)->size(2048))
        ->call('save')->assertHasErrors(['logo']);

    Livewire::actingAs($this->admin)->test(CompanyForm::class, ['company' => $this->acme])
        ->set('logo', UploadedFile::fake()->image('wide.png', 2500, 100))
        ->call('save')->assertHasErrors(['logo']);
});

it('deletes the old logo when it is replaced or removed', function () {
    $component = Livewire::actingAs($this->admin)->test(CompanyForm::class, ['company' => $this->acme]);
    $component->set('logo', UploadedFile::fake()->image('one.png', 100, 100))->call('save');
    $first = $this->acme->fresh()->logo_path;

    Livewire::actingAs($this->admin)->test(CompanyForm::class, ['company' => $this->acme->fresh()])
        ->set('logo', UploadedFile::fake()->image('two.jpg', 100, 100))->call('save');
    $second = $this->acme->fresh()->logo_path;

    expect($second)->not->toBe($first)
        ->and(Storage::disk('local')->exists($first))->toBeFalse()
        ->and(Storage::disk('local')->exists($second))->toBeTrue();

    Livewire::actingAs($this->admin)->test(CompanyForm::class, ['company' => $this->acme->fresh()])
        ->set('removeLogo', true)->call('save');

    expect($this->acme->fresh()->logo_path)->toBeNull()->and(Storage::disk('local')->exists($second))->toBeFalse();
});

it('serves the logo only to people who can view that company', function () {
    Livewire::actingAs($this->admin)->test(CompanyForm::class, ['company' => $this->acme])
        ->set('logo', UploadedFile::fake()->image('logo.png', 100, 100))->call('save');

    $this->actingAs($this->admin)->get(route('masters.companies.logo', $this->acme))
        ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    // a user of another company cannot even resolve it
    $outsider = makeUser(['Company Admin'], [$this->globex]);
    $this->actingAs($outsider)->get(route('masters.companies.logo', $this->acme))->assertNotFound();

    // a member without the view permission is refused
    $noView = makeUser([], [$this->acme]);
    $this->actingAs($noView)->get(route('masters.companies.logo', $this->acme))->assertForbidden();

    auth()->logout();
    $this->get(route('masters.companies.logo', $this->acme))->assertRedirect('/login');
});

it('answers 404 for a company that has no logo', function () {
    $this->actingAs($this->admin)->get(route('masters.companies.logo', $this->acme))->assertNotFound();
});

// ---- list ------------------------------------------------------------------------------------------

it('lists only the companies the user can access, with correct branch counts', function () {
    makeLocation($this->acme, 'MUM');
    makeLocation($this->globex, 'PUN');
    makeLocation($this->globex, 'DEL');

    Livewire::actingAs($this->admin)->test(CompanyList::class)->assertSee('Acme Industries')->assertDontSee('Globex Ltd');

    // A Super Admin sees both, and each company's own count (not the active company's) is shown.
    $rows = Livewire::actingAs($this->super)->test(CompanyList::class)->assertSee('Acme Industries')->assertSee('Globex Ltd')
        ->instance()->render()->getData()['companies']->keyBy('code');

    expect($rows['ACME']->locations_count)->toBe(2)->and($rows['GLOBEX']->locations_count)->toBe(2);
});

it('searches by name, code and GSTIN', function () {
    $this->globex->forceFill(['gstin' => gstinFor('27', 'AAACG1234H')])->save();

    Livewire::actingAs($this->super)->test(CompanyList::class)
        ->set('search', 'globex')->assertSee('Globex Ltd')->assertDontSee('Acme Industries')
        ->set('search', gstinFor('27', 'AAACG1234H'))->assertSee('Globex Ltd')
        ->set('search', 'ACME')->assertSee('Acme Industries')->assertDontSee('Globex Ltd');
});

it('deactivates and reactivates a company', function () {
    Livewire::actingAs($this->super)->test(CompanyList::class)->call('toggleStatus', $this->globex->id);
    expect($this->globex->fresh()->status)->toBe('inactive');

    Livewire::actingAs($this->super)->test(CompanyList::class)->call('toggleStatus', $this->globex->id);
    expect($this->globex->fresh()->status)->toBe('active');
});

it('refuses to delete a company that has branches or numbered documents', function () {
    // Fixtures first: once someone is signed in, every write is tenant-checked.
    $empty = makeCompany('EMPTY');
    $emptyBranch = makeLocation($empty, 'EMP');
    app(NumberSeriesService::class)->next(DocumentType::Lead, $emptyBranch);
    $emptyBranch->delete();   // the branch is soft-deleted, but the numbered series remains
    $emptySuper = makeUser(['Super Admin'], [$empty]);

    // has a live branch
    Livewire::actingAs($this->super)->test(CompanyList::class)
        ->call('delete', $this->acme->id)->assertHasErrors(['list']);
    expect(Company::query()->whereKey($this->acme->id)->exists())->toBeTrue();

    // no live branch, but documents were numbered
    Livewire::actingAs($emptySuper)->test(CompanyList::class)->call('delete', $empty->id)->assertHasErrors(['list']);
    expect(Company::query()->whereKey($empty->id)->exists())->toBeTrue();
});

it('deletes an empty company and its logo, but only for someone with the delete permission', function () {
    $empty = makeCompany('EMPTY');
    $super = makeUser(['Super Admin'], [$empty]);
    $admin = makeUser(['Company Admin'], [$empty]);

    Livewire::actingAs($super)->test(CompanyForm::class, ['company' => $empty])
        ->set('logo', UploadedFile::fake()->image('logo.png', 80, 80))->call('save');
    $logo = $empty->fresh()->logo_path;

    Livewire::actingAs($admin)->test(CompanyList::class)->call('delete', $empty->id)->assertForbidden();

    Livewire::actingAs($super)->test(CompanyList::class)->call('delete', $empty->id)->assertHasNoErrors();

    expect(Company::withoutGlobalScopes()->whereKey($empty->id)->first()->trashed())->toBeTrue()
        ->and(Storage::disk('local')->exists($logo))->toBeFalse();
});

<?php

use App\Modules\Shared\Exceptions\TenantAccessException;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\Support\TestDocument;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');

    $this->acmeAhd = makeLocation($this->acme, 'AHD');
    $this->acmeMum = makeLocation($this->acme, 'MUM');
    $this->globexPun = makeLocation($this->globex, 'PUN');

    $this->acmeDoc = makeDocument($this->acme, $this->acmeAhd, ['name' => 'Acme AHD doc']);
    $this->acmeMumDoc = makeDocument($this->acme, $this->acmeMum, ['name' => 'Acme MUM doc']);
    $this->acmeWideDoc = makeDocument($this->acme, null, ['name' => 'Acme company-wide doc']);
    $this->globexDoc = makeDocument($this->globex, $this->globexPun, ['name' => 'Globex doc']);
});

it('shows a user only the documents of their own company', function () {
    $user = makeUser(['Purchase User'], [$this->acme]);

    $this->actingAs($user);

    expect(TestDocument::pluck('name')->all())
        ->toContain('Acme AHD doc', 'Acme MUM doc', 'Acme company-wide doc')
        ->not->toContain('Globex doc');
});

it('hides other companies even when the id is guessed', function () {
    $this->actingAs(makeUser(['Purchase User'], [$this->acme]));

    expect(TestDocument::find($this->globexDoc->id))->toBeNull();
    expect(fn () => TestDocument::findOrFail($this->globexDoc->id))->toThrow(ModelNotFoundException::class);
});

it('restricts a branch-limited user to their branches plus company-wide records', function () {
    $user = makeUser(['Sales Manager'], [$this->acme], locations: [$this->acmeAhd]);

    $this->actingAs($user);

    expect(TestDocument::pluck('name')->all())
        ->toContain('Acme AHD doc', 'Acme company-wide doc')
        ->not->toContain('Acme MUM doc', 'Globex doc');

    expect(Location::pluck('code')->all())->toBe(['AHD']);
});

it('lets an all-branches member see every branch of the company', function () {
    $this->actingAs(makeUser(['Company Admin'], [$this->acme]));

    expect(Location::pluck('code')->sort()->values()->all())->toBe(['AHD', 'MUM']);
});

it('lets a Super Admin reach every company', function () {
    $admin = makeUser(['Super Admin'], [$this->acme]);

    $this->actingAs($admin);
    $tenant = app(TenantContext::class);

    expect($tenant->accessibleCompanyIds())->toContain($this->acme->id, $this->globex->id);
    expect(Company::count())->toBe(2);

    $tenant->switchCompany($this->globex->id);
    expect(TestDocument::pluck('name')->all())->toBe(['Globex doc']);
});

it('fails closed for a guest on an HTTP request', function () {
    // Pretend this is a web request rather than console work: nobody signed in => nothing visible.
    $flag = new ReflectionProperty(Application::class, 'isRunningInConsole');
    $flag->setValue(app(), false);

    $tenant = app(TenantContext::class);

    expect($tenant->isUnrestricted())->toBeFalse();
    expect($tenant->activeCompanyId())->toBeNull();
    expect($tenant->accessibleCompanyIds())->toBe([]);
    expect(TestDocument::count())->toBe(0);
    expect(Company::count())->toBe(0);
});

it('is unrestricted for console work with no user (seeders and queued jobs)', function () {
    expect(app(TenantContext::class)->isUnrestricted())->toBeTrue();
    expect(TestDocument::count())->toBe(4);
});

it('restricts a job to a single company with withCompany()', function () {
    $tenant = app(TenantContext::class);

    $names = $tenant->withCompany($this->globex->id, fn () => TestDocument::pluck('name')->all());

    expect($names)->toBe(['Globex doc']);
    expect(TestDocument::count())->toBe(4); // override ends with the callback
});

it('resolves the active company from the user default, then session choice', function () {
    $user = makeUser(['Company Admin'], [$this->acme, $this->globex], attributes: []);
    $this->actingAs($user);

    $tenant = app(TenantContext::class);
    expect($tenant->activeCompanyId())->toBe($this->acme->id);

    $tenant->switchCompany($this->globex->id);
    expect($tenant->activeCompanyId())->toBe($this->globex->id);
});

it('refuses to switch to a company the user does not belong to', function () {
    $this->actingAs(makeUser(['Company Admin'], [$this->acme]));

    app(TenantContext::class)->switchCompany($this->globex->id);
})->throws(TenantAccessException::class);

it('refuses to switch the default branch to one the user cannot access', function () {
    $this->actingAs(makeUser(['Sales Manager'], [$this->acme], locations: [$this->acmeAhd]));

    app(TenantContext::class)->switchLocation($this->acmeMum->id);
})->throws(TenantAccessException::class);

it('ignores a forged company id header for a company the user is not in', function () {
    $this->actingAs(makeUser(['Company Admin'], [$this->acme]));
    app()->instance('request', Request::create('/api', 'GET', server: ['HTTP_X_COMPANY_ID' => (string) $this->globex->id]));

    expect(app(TenantContext::class)->activeCompanyId())->toBe($this->acme->id);
    expect(TestDocument::pluck('name')->all())->not->toContain('Globex doc');
});

it('honours the company id header for a company the user belongs to', function () {
    $this->actingAs(makeUser(['Company Admin'], [$this->acme, $this->globex]));
    app()->instance('request', Request::create('/api', 'GET', server: ['HTTP_X_COMPANY_ID' => (string) $this->globex->id]));

    expect(app(TenantContext::class)->activeCompanyId())->toBe($this->globex->id);
});

it('stamps the active company on new records', function () {
    $user = makeUser(['Purchase User'], [$this->acme]);
    $this->actingAs($user);

    $doc = TestDocument::create(['code' => 'NEW1', 'name' => 'Created by user']);

    expect($doc->company_id)->toBe($this->acme->id);
    expect($doc->created_by)->toBe($user->id);
});

it('blocks saving a record into a company the user cannot access', function () {
    $this->actingAs(makeUser(['Purchase User'], [$this->acme]));

    $doc = new TestDocument(['code' => 'X1', 'name' => 'Sneaky']);
    $doc->forceFill(['company_id' => $this->globex->id]);
    $doc->save();
})->throws(TenantAccessException::class);

it('blocks moving an existing record to another company', function () {
    $user = makeUser(['Company Admin'], [$this->acme, $this->globex]);
    $this->actingAs($user);

    $doc = TestDocument::findOrFail($this->acmeDoc->id);
    $doc->company_id = $this->globex->id;
    $doc->save();
})->throws(TenantAccessException::class, 'moved to another company');

it('blocks a branch that belongs to a different company', function () {
    $this->actingAs(makeUser(['Company Admin'], [$this->acme]));

    $doc = new TestDocument(['code' => 'X2', 'name' => 'Wrong branch']);
    $doc->forceFill(['location_id' => $this->globexPun->id]);
    $doc->save();
})->throws(TenantAccessException::class, 'same company');

it('blocks a branch the user is not assigned to', function () {
    $this->actingAs(makeUser(['Sales Manager'], [$this->acme], locations: [$this->acmeAhd]));

    $doc = new TestDocument(['code' => 'X3', 'name' => 'Not my branch']);
    $doc->forceFill(['location_id' => $this->acmeMum->id]);
    $doc->save();
})->throws(TenantAccessException::class);

it('defaults the branch to the only one a user has', function () {
    $this->actingAs(makeUser(['Sales Manager'], [$this->acme], locations: [$this->acmeAhd]));

    $doc = TestDocument::create(['code' => 'X4', 'name' => 'Auto branch']);

    expect($doc->location_id)->toBe($this->acmeAhd->id);
});

it('does not leak tenant state between users in the same process', function () {
    $acmeUser = makeUser(['Company Admin'], [$this->acme]);
    $globexUser = makeUser(['Company Admin'], [$this->globex]);

    $this->actingAs($acmeUser);
    expect(TestDocument::pluck('name')->all())->not->toContain('Globex doc');

    Auth::forgetGuards();
    $this->actingAs($globexUser);
    expect(TestDocument::pluck('name')->all())->toBe(['Globex doc']);
});

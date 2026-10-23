<?php

use App\Models\User;
use App\Modules\Shared\Http\Livewire\Audit\AuditLogList;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Services\ApprovalService;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\TestDocument;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->pun = makeLocation($this->globex, 'PUN');

    $this->admin = makeUser(['Company Admin'], [$this->acme], attributes: ['name' => 'Carl Admin']);
    $this->globexAdmin = makeUser(['Company Admin'], [$this->globex], attributes: ['name' => 'Gina Admin']);
});

it('logs create, update and delete with the acting user', function () {
    $this->actingAs($this->admin);

    $doc = TestDocument::create(['code' => 'D1', 'name' => 'First name']);
    $doc->update(['name' => 'Second name']);
    $doc->delete();

    $entries = Activity::query()->withoutGlobalScopes()->where('subject_type', 'test_document')->orderBy('id')->get();

    expect($entries->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($entries->pluck('causer_id')->unique()->all())->toBe([$this->admin->id])
        ->and($entries->pluck('causer_type')->unique()->all())->toBe(['user'])
        ->and($entries->pluck('subject_id')->unique()->all())->toBe([$doc->id]);
});

it('records old and new values for an update, and only what changed', function () {
    $this->actingAs($this->admin);
    $doc = TestDocument::create(['code' => 'D1', 'name' => 'Before']);

    $doc->update(['name' => 'After']);

    $update = Activity::query()->withoutGlobalScopes()->where('event', 'updated')->sole();

    expect($update->properties['old'])->toBe(['name' => 'Before'])
        ->and($update->properties['attributes'])->toBe(['name' => 'After']);
});

it('does not log an update that changed nothing', function () {
    $this->actingAs($this->admin);
    $doc = TestDocument::create(['code' => 'D1', 'name' => 'Same']);

    $before = Activity::query()->withoutGlobalScopes()->count();
    $doc->update(['name' => 'Same']);
    $doc->touch();

    expect(Activity::query()->withoutGlobalScopes()->count())->toBe($before);
});

it('stamps every entry with the owning company', function () {
    $lastFixtureEntry = (int) Activity::query()->withoutGlobalScopes()->max('id');

    $this->actingAs($this->admin);
    TestDocument::create(['code' => 'D1', 'name' => 'Acme doc']);
    $this->acme->update(['phone' => '079-1234567']);
    User::factory()->create()->update(['name' => 'Changed by Acme admin']);   // a record with no company of its own

    $entries = Activity::query()->withoutGlobalScopes()->where('id', '>', $lastFixtureEntry)->get();

    expect($entries->count())->toBeGreaterThanOrEqual(4)
        ->and($entries->pluck('company_id')->unique()->all())->toBe([$this->acme->id]);
});

it('logs changes to the company master itself', function () {
    $this->actingAs($this->admin);

    $this->acme->update(['gstin' => '24AAACA1234A1Z5']);

    $entry = Activity::query()->withoutGlobalScopes()->where('subject_type', 'company')->latest('id')->first();

    expect($entry->properties['attributes'])->toBe(['gstin' => '24AAACA1234A1Z5'])
        ->and($entry->company_id)->toBe($this->acme->id);
});

it('logs the approval fields moving through the workflow as ordinary updates too', function () {
    $maker = makeUser(['Purchase User'], [$this->acme]);
    $checker = makeUser(['Accounts User'], [$this->acme]);
    $doc = makeDocument($this->acme, $this->ahd);

    $service = app(ApprovalService::class);
    $service->submit($doc, $maker);
    $service->approve($doc, $checker);

    $statuses = Activity::query()->withoutGlobalScopes()
        ->where('subject_type', 'test_document')->where('event', 'updated')->orderBy('id')->get()
        ->map(fn ($a) => $a->properties['attributes']['approval_status'] ?? null)->filter()->values()->all();

    expect($statuses)->toBe(['pending', 'approved']);
});

it('never records a password or token', function () {
    $this->actingAs($this->admin);
    $user = User::factory()->create(['name' => 'Temp']);
    $user->update(['name' => 'Renamed', 'password' => 'brand-new-secret']);

    $logged = Activity::query()->withoutGlobalScopes()->where('subject_type', 'user')->get()
        ->map(fn ($a) => json_encode($a->properties))->implode(' ');

    expect($logged)->not->toContain('brand-new-secret')->not->toContain('password')->not->toContain('remember_token');
});

it('uses the company-scoped audit model', function () {
    expect(config('activitylog.activity_model'))->toBe(ActivityLog::class);
});

// ---- viewer --------------------------------------------------------------------------------------

it('shows a company only its own audit history', function () {
    $this->actingAs($this->admin);
    TestDocument::create(['code' => 'A1', 'name' => 'Acme secret doc']);
    Company::query()->find($this->acme->id)->update(['phone' => '111']);

    app('auth')->forgetGuards();
    $this->actingAs($this->globexAdmin);
    $this->globex->update(['phone' => '222']);

    $acmeView = Livewire::actingAs($this->admin)->test(AuditLogList::class);
    expect($acmeView->instance()->render()->getData()['entries']->pluck('company_id')->unique()->all())->toBe([$this->acme->id]);

    $globexView = Livewire::actingAs($this->globexAdmin)->test(AuditLogList::class);
    expect($globexView->instance()->render()->getData()['entries']->pluck('company_id')->unique()->all())->toBe([$this->globex->id]);
});

it('lets the viewer filter by area, event and user, and expand the old/new values', function () {
    $this->actingAs($this->admin);
    $doc = TestDocument::create(['code' => 'D1', 'name' => 'Alpha']);
    $doc->update(['name' => 'Omega']);

    $component = Livewire::actingAs($this->admin)->test(AuditLogList::class)
        ->assertSee('created')->assertSee('updated')->assertSee('Carl Admin');

    $component->set('event', 'updated')->assertSee('updated');
    expect($component->instance()->render()->getData()['entries']->pluck('event')->unique()->all())->toBe(['updated']);

    $entry = ActivityLog::query()->where('event', 'updated')->firstOrFail();
    $component->call('toggle', $entry->id)->assertSee('Alpha')->assertSee('Omega');

    $component->set('event', '')->set('log', 'test_documents');
    expect($component->instance()->render()->getData()['entries']->pluck('log_name')->unique()->all())->toBe(['test_documents']);
});

it('requires the audit permission to open the viewer', function () {
    Livewire::actingAs(makeUser(['Tele-caller'], [$this->acme]))->test(AuditLogList::class)->assertForbidden();
    Livewire::actingAs($this->admin)->test(AuditLogList::class)->assertOk();
});

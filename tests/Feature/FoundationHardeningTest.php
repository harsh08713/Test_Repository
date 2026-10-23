<?php

use App\Models\User;
use App\Modules\Shared\Enums\CommentVisibility;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Exceptions\TenantAccessException;
use App\Modules\Shared\Http\Livewire\Comments;
use App\Modules\Shared\Http\Livewire\Roles\PermissionMatrix;
use App\Modules\Shared\Http\Livewire\Users\Form;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Comment;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\ProbeJob;
use Tests\Support\TestDocument;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->pun = makeLocation($this->globex, 'PUN');

    makeDocument($this->acme, $this->ahd, ['name' => 'Acme doc']);
    makeDocument($this->globex, $this->pun, ['name' => 'Globex doc']);

    ProbeJob::$seen = [];
});

// ---- tenant-aware background jobs ----------------------------------------------------------------------

it('runs a queued job strictly inside the company it was queued for', function () {
    ProbeJob::dispatch($this->globex->id);

    expect(ProbeJob::$seen)->toBe(['Globex doc']);

    ProbeJob::dispatch($this->acme->id);

    expect(ProbeJob::$seen)->toBe(['Acme doc']);
});

it('restores the previous context once the job has finished', function () {
    ProbeJob::dispatch($this->acme->id);

    // Console code outside a job is unrestricted again: both companies are visible.
    expect(TestDocument::count())->toBe(2)
        ->and(app(TenantContext::class)->isUnrestricted())->toBeTrue();
});

it('fails a job that does not say which company it belongs to', function () {
    expect(fn () => ProbeJob::dispatch())->toThrow(TenantAccessException::class, 'must declare the company');

    expect(ProbeJob::$seen)->toBe([]);
});

it('fails a job whose company no longer exists', function () {
    $this->globex->delete();

    expect(fn () => ProbeJob::dispatch($this->globex->id))->toThrow(TenantAccessException::class);
});

it('fails a job whose branch belongs to a different company', function () {
    expect(fn () => ProbeJob::dispatch($this->acme->id, $this->pun->id))->toThrow(TenantAccessException::class);

    ProbeJob::dispatch($this->acme->id, $this->ahd->id);
    expect(ProbeJob::$seen)->toBe(['Acme doc']);
});

// ---- append-only audit log ---------------------------------------------------------------------------------

it('refuses to edit or delete an audit entry', function () {
    $this->actingAs(makeUser(['Company Admin'], [$this->acme]));
    $this->acme->update(['phone' => '079-111']);

    $entry = ActivityLog::query()->latest('id')->firstOrFail();

    expect(fn () => $entry->update(['description' => 'rewritten']))->toThrow(LogicException::class, 'append-only');
    expect(fn () => $entry->delete())->toThrow(LogicException::class, 'append-only');

    expect(ActivityLog::query()->whereKey($entry->id)->exists())->toBeTrue();
});

it('still lets the retention command prune old entries', function () {
    $this->acme->update(['phone' => '079-222']);
    $old = ActivityLog::withoutGlobalScopes()->latest('id')->firstOrFail();

    DB::table('activity_log')->where('id', $old->id)->update(['created_at' => now()->subDays(400)]);
    $this->acme->update(['phone' => '079-333']);

    Artisan::call('activitylog:clean', ['--force' => true]);

    expect(ActivityLog::withoutGlobalScopes()->whereKey($old->id)->exists())->toBeFalse()
        ->and(ActivityLog::withoutGlobalScopes()->count())->toBeGreaterThan(0);
});

// ---- permissions -------------------------------------------------------------------------------------------

it('offers the assign, configure and view-audit actions', function () {
    expect(collect(PermissionAction::cases())->map->value->all())
        ->toContain('assign', 'configure', 'view_audit');

    foreach (['lead.assign', 'deal.assign', 'setting.configure', 'item.view_audit', 'quotation.view_audit'] as $name) {
        expect(Permission::where('name', $name)->exists())->toBeTrue("{$name} missing");
    }
});

it('gives assign rights to the people who distribute leads, not to the people who work them', function () {
    expect(Role::findByName('Sales Manager')->hasPermissionTo('lead.assign'))->toBeTrue()
        ->and(Role::findByName('Tele-caller')->hasPermissionTo('lead.assign'))->toBeFalse()
        ->and(Role::findByName('BDE / Field Salesperson')->hasPermissionTo('lead.assign'))->toBeFalse()
        ->and(Role::findByName('Operations User')->hasPermissionTo('setting.configure'))->toBeFalse();
});

it('shows the new action columns in the permission matrix', function () {
    Livewire::actingAs(makeUser(['Super Admin'], [$this->acme]))->test(PermissionMatrix::class)
        ->assertSee('Assign / transfer')->assertSee('Configure')->assertSee('View audit')
        ->assertSeeHtml('value="item.view_audit"')->assertSeeHtml('value="setting.configure"');
});

it('locks pending and approved documents against editing for everyone, Super Admin included', function () {
    $maker = makeUser(['Purchase User'], [$this->acme]);
    $super = makeUser(['Super Admin'], [$this->acme]);
    $doc = makeDocument($this->acme, $this->ahd, ['name' => 'Locked']);

    expect(Gate::forUser($super)->allows('update', $doc))->toBeTrue();

    app(ApprovalService::class)->submit($doc, $maker);

    expect(Gate::forUser($super)->allows('update', $doc->fresh()))->toBeFalse()
        ->and(Gate::forUser($super)->allows('delete', $doc->fresh()))->toBeFalse();
});

// ---- password policy ---------------------------------------------------------------------------------------

it('enforces one password policy when an admin sets a password', function (string $password) {
    $admin = makeUser(['Company Admin'], [$this->acme]);

    Livewire::actingAs($admin)->test(Form::class)
        ->set('name', 'New Person')->set('email', 'new.person@example.test')
        ->set('password', $password)->set('password_confirmation', $password)
        ->set('company_ids', [(string) $this->acme->id])->set('all_location_company_ids', [(string) $this->acme->id])
        ->call('save')
        ->assertHasErrors(['password']);

    expect(User::where('email', 'new.person@example.test')->exists())->toBeFalse();
})->with([
    'too short' => ['Ab1defg'],
    'no uppercase' => ['alllowercase123'],
    'no lowercase' => ['ALLUPPERCASE123'],
    'no number' => ['NoNumbersAtAllHere'],
]);

it('accepts a password that meets the policy', function () {
    $admin = makeUser(['Company Admin'], [$this->acme]);

    Livewire::actingAs($admin)->test(Form::class)
        ->set('name', 'New Person')->set('email', 'new.person@example.test')
        ->set('password', 'Sturdy-Pass-2026')->set('password_confirmation', 'Sturdy-Pass-2026')
        ->set('company_ids', [(string) $this->acme->id])->set('all_location_company_ids', [(string) $this->acme->id])
        ->call('save')->assertHasNoErrors();
});

// ---- comments ----------------------------------------------------------------------------------------------

it('stores staff comments as internal, ready for a customer portal to filter on', function () {
    $ops = makeUser(['Operations User'], [$this->acme]);
    $doc = TestDocument::withoutGlobalScopes(Tenancy::SCOPES)->where('name', 'Acme doc')->firstOrFail();

    Livewire::actingAs($ops)->test(Comments::class, ['type' => 'test_document', 'id' => $doc->id])
        ->set('body', 'Check the datasheet')->call('add');

    expect(Comment::withoutGlobalScopes()->sole()->visibility)->toBe(CommentVisibility::Internal->value);
});

it('lets only a holder of comment.moderate delete someone else’s comment', function () {
    $ops = makeUser(['Operations User'], [$this->acme]);
    $purchase = makeUser(['Purchase User'], [$this->acme]);
    $companyAdmin = makeUser(['Company Admin'], [$this->acme]);   // holds comment.moderate by default
    $doc = TestDocument::withoutGlobalScopes(Tenancy::SCOPES)->where('name', 'Acme doc')->firstOrFail();
    $props = ['type' => 'test_document', 'id' => $doc->id];

    Livewire::actingAs($ops)->test(Comments::class, $props)->set('body', 'one')->call('add');
    $comment = Comment::withoutGlobalScopes()->sole();

    Livewire::actingAs($purchase)->test(Comments::class, $props)->call('delete', $comment->id)->assertForbidden();

    Livewire::actingAs($companyAdmin)->test(Comments::class, $props)->call('delete', $comment->id);

    expect(Comment::withoutGlobalScopes(Tenancy::SCOPES)->count())->toBe(0);
});

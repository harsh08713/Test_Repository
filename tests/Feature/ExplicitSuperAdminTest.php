<?php

use App\Models\User;
use App\Modules\Shared\Enums\ApprovalLevelStatus;
use App\Modules\Shared\Exceptions\TenantAccessException;
use App\Modules\Shared\Models\Approval;
use App\Modules\Shared\Models\ApprovalLevel;
use App\Modules\Shared\Services\ApprovalService;
use App\Modules\Shared\Services\PermissionRegistry;
use App\Modules\Shared\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Super Admin must be explicit and auditable, not an accidental bypass: it is an ordinary role that the
 * seeder keeps granted with every permission, and its extra reach comes from named permissions whose use
 * is recorded.
 */

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->super = makeUser(['Super Admin'], [$this->acme, $this->globex]);
});

it('keeps Super Admin granted with every permission, and only through the role', function () {
    $role = Role::findByName('Super Admin');

    expect($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(PermissionRegistry::allPermissionNames())->sort()->values()->all());

    expect($role->hasPermissionTo('tenant.access_all'))->toBeTrue()
        ->and($role->hasPermissionTo('approval.override'))->toBeTrue()
        ->and($role->hasPermissionTo('comment.moderate'))->toBeTrue();
});

it('has no hidden bypass: take a permission away and Super Admin loses it', function () {
    $doc = makeDocument($this->acme, $this->ahd);
    expect(Gate::forUser($this->super)->allows('update', $doc))->toBeTrue();

    Role::findByName('Super Admin')->revokePermissionTo('item.edit');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Gate::forUser($this->super->fresh())->allows('update', $doc))->toBeFalse()
        ->and(Gate::forUser($this->super->fresh())->allows('view', $doc))->toBeTrue();
});

it('re-grants a permission that was missing from Super Admin the next time the seeder runs', function () {
    Role::findByName('Super Admin')->revokePermissionTo(['item.approve', 'tenant.access_all']);

    (new RolePermissionSeeder)->run();

    expect(Role::findByName('Super Admin')->hasPermissionTo('item.approve'))->toBeTrue()
        ->and(Role::findByName('Super Admin')->hasPermissionTo('tenant.access_all'))->toBeTrue();
});

it('does not grant the break-glass permissions to a Company Admin by default', function () {
    $admin = Role::findByName('Company Admin');

    expect($admin->hasPermissionTo('tenant.access_all'))->toBeFalse()
        ->and($admin->hasPermissionTo('approval.override'))->toBeFalse()
        ->and($admin->hasPermissionTo('comment.moderate'))->toBeTrue();
});

// ---- cross-company reach ---------------------------------------------------------------------------

it('gives cross-company reach to whoever holds tenant.access_all, not to a role name', function () {
    Role::create(['name' => 'Group auditor'])->givePermissionTo(['tenant.access_all', 'item.view']);
    $auditor = makeUser(['Group auditor'], []);

    $this->actingAs($auditor);
    $tenant = app(TenantContext::class);

    expect($tenant->canAccessEverything($auditor))->toBeTrue()
        ->and($tenant->accessibleCompanyIds())->toContain($this->acme->id, $this->globex->id);
});

it('records every switch into a company the user does not belong to', function () {
    $outsider = makeUser(['Super Admin'], [$this->acme]);   // member of ACME only
    $this->actingAs($outsider);

    app(TenantContext::class)->switchCompany($this->globex->id);

    $entry = Activity::query()->withoutGlobalScopes()->where('event', 'cross_company_access')->sole();

    expect($entry->causer_id)->toBe($outsider->id)
        ->and($entry->company_id)->toBe($this->globex->id)               // visible in the company that was entered
        ->and($entry->properties['company_id'])->toBe($this->globex->id)
        ->and($entry->properties['via'])->toBe('tenant.access_all');
});

it('does not log a switch between companies the user belongs to', function () {
    $this->actingAs($this->super);

    app(TenantContext::class)->switchCompany($this->globex->id);

    expect(Activity::query()->withoutGlobalScopes()->where('event', 'cross_company_access')->count())->toBe(0);
});

it('blocks cross-company access without the permission even for a long-standing admin', function () {
    $admin = makeUser(['Company Admin'], [$this->acme]);
    $this->actingAs($admin);

    expect(fn () => app(TenantContext::class)->switchCompany($this->globex->id))
        ->toThrow(TenantAccessException::class);
});

// ---- approval override --------------------------------------------------------------------------------

it('records an override when Super Admin decides a level it was not designated for', function () {
    $row = new ApprovalLevel(['document_type' => 'item', 'level' => 1, 'approver_role' => 'Accounts User']);
    $row->forceFill(['company_id' => $this->acme->id])->save();

    $maker = makeUser(['Purchase User'], [$this->acme]);
    $doc = makeDocument($this->acme, $this->ahd);
    $service = app(ApprovalService::class);

    $service->submit($doc, $maker);
    $service->approve($doc, $this->super, 'Covering for Accounts');

    $level = Approval::withoutGlobalScopes()->sole();
    expect($level->overridden)->toBeTrue()
        ->and($level->status)->toBe(ApprovalLevelStatus::Approved);

    $log = Activity::query()->withoutGlobalScopes()->where('log_name', 'approval')->where('event', 'approved')->sole();
    expect($log->properties['override'])->toBeTrue()
        ->and($log->causer_id)->toBe($this->super->id);
});

it('does not mark a designated approver’s decision as an override', function () {
    $maker = makeUser(['Purchase User'], [$this->acme]);
    $accounts = makeUser(['Accounts User'], [$this->acme]);
    $doc = makeDocument($this->acme, $this->ahd);
    $service = app(ApprovalService::class);

    $service->submit($doc, $maker);
    $service->approve($doc, $accounts);

    expect(Approval::withoutGlobalScopes()->sole()->overridden)->toBeFalse();

    $log = Activity::query()->withoutGlobalScopes()->where('event', 'approved')->sole();
    expect($log->properties['override'])->toBeFalse();
});

it('refuses an override from anyone without the approval.override permission', function () {
    $row = new ApprovalLevel(['document_type' => 'item', 'level' => 1, 'approver_role' => 'Accounts User']);
    $row->forceFill(['company_id' => $this->acme->id])->save();

    $maker = makeUser(['Purchase User'], [$this->acme]);
    $companyAdmin = makeUser(['Company Admin'], [$this->acme]);     // has item.approve, but is not Accounts
    $doc = makeDocument($this->acme, $this->ahd);
    $service = app(ApprovalService::class);

    $service->submit($doc, $maker);

    expect(fn () => $service->approve($doc, $companyAdmin))->toThrow(AuthorizationException::class, 'not an approver');
});

it('still applies maker–checker to an override', function () {
    $row = new ApprovalLevel(['document_type' => 'item', 'level' => 1, 'approver_role' => 'Accounts User']);
    $row->forceFill(['company_id' => $this->acme->id])->save();

    $doc = makeDocument($this->acme, $this->ahd);
    $service = app(ApprovalService::class);

    $service->submit($doc, $this->super);

    expect(fn () => $service->approve($doc, $this->super))->toThrow(AuthorizationException::class, 'created or submitted');
});

it('keeps the explicit override and access permissions in the matrix, ready to be granted deliberately', function () {
    foreach (['tenant.access_all', 'approval.override', 'comment.moderate'] as $name) {
        expect(Permission::where('name', $name)->exists())->toBeTrue()
            ->and(PermissionRegistry::special())->toHaveKey($name);
    }

    expect(PermissionRegistry::special())->not->toHaveKey('lead.transfer');
    expect(User::SUPER_ADMIN_ROLE)->toBe('Super Admin');
});

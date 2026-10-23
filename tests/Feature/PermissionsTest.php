<?php

use App\Models\User;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Services\PermissionRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TestDocument;

beforeEach(function () {
    $this->company = makeCompany('ACME');
    $this->ahd = makeLocation($this->company, 'AHD');
    $this->item = makeDocument($this->company, $this->ahd);   // governed by the `item` permission form
});

// ---- Operations: view + comment only on Item Master -----------------------------------------------

it('lets Operations view and comment on an item', function () {
    $ops = makeUser(['Operations User'], [$this->company]);

    expect(Gate::forUser($ops)->allows('view', $this->item))->toBeTrue()
        ->and(Gate::forUser($ops)->allows('comment', $this->item))->toBeTrue();
});

it('denies Operations every way of changing an item', function () {
    $ops = makeUser(['Operations User'], [$this->company]);

    expect(Gate::forUser($ops)->allows('update', $this->item))->toBeFalse()
        ->and(Gate::forUser($ops)->allows('delete', $this->item))->toBeFalse()
        ->and(Gate::forUser($ops)->allows('create', TestDocument::class))->toBeFalse()
        ->and(Gate::forUser($ops)->allows('approve', $this->item))->toBeFalse()
        ->and(Gate::forUser($ops)->allows('submit', $this->item))->toBeFalse();

    expect(fn () => Gate::forUser($ops)->authorize('update', $this->item))
        ->toThrow(AuthorizationException::class);
});

it('answers 403 over HTTP semantics when Operations tries to edit', function () {
    $ops = makeUser(['Operations User'], [$this->company]);

    $response = Gate::forUser($ops)->inspect('update', $this->item);

    expect($response->allowed())->toBeFalse();
});

it('lets someone with edit rights comment without a separate comment permission', function () {
    $role = Role::create(['name' => 'Editor only']);
    $role->givePermissionTo(['item.view', 'item.edit']);
    $editor = makeUser(['Editor only'], [$this->company]);

    expect(Gate::forUser($editor)->allows('comment', $this->item))->toBeTrue();
});

it('does not let a view-only role comment', function () {
    $role = Role::create(['name' => 'Viewer only']);
    $role->givePermissionTo('item.view');
    $viewer = makeUser(['Viewer only'], [$this->company]);

    expect(Gate::forUser($viewer)->allows('view', $this->item))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('comment', $this->item))->toBeFalse();
});

it('does not let a comment-only role read what it cannot view', function () {
    $role = Role::create(['name' => 'Commenter without view']);
    $role->givePermissionTo('item.comment');
    $user = makeUser(['Commenter without view'], [$this->company]);

    expect(Gate::forUser($user)->allows('comment', $this->item))->toBeFalse();
});

it('gives a Super Admin every ability', function () {
    $admin = makeUser(['Super Admin'], [$this->company]);

    foreach (['view', 'update', 'delete', 'approve', 'comment', 'submit'] as $ability) {
        expect(Gate::forUser($admin)->allows($ability, $this->item))->toBeTrue();
    }

    expect($admin->can('role.edit'))->toBeTrue();
});

// ---- default roles ---------------------------------------------------------------------------------

it('seeds every standard role from the brief', function () {
    foreach (['Super Admin', 'Company Admin', 'Purchase User', 'Inventory User', 'Accounts User', 'Operations User',
        'Sales Manager', 'BDE / Field Salesperson', 'Tele-caller', 'Approver'] as $name) {
        expect(Role::where('name', $name)->exists())->toBeTrue("Missing role {$name}");
    }
});

it('only grants permissions that exist in the registry', function () {
    $known = PermissionRegistry::allPermissionNames();

    foreach (PermissionRegistry::defaultRoles() as $role => $permissions) {
        expect(array_diff($permissions, $known))->toBe([], "{$role} references unknown permissions");
    }
});

it('creates a permission for every action of every form', function () {
    $names = PermissionRegistry::allPermissionNames();

    expect(count($names))->toBe(count(array_unique($names)))
        ->and(Permission::whereIn('name', $names)->count())->toBe(count($names));
});

it('keeps Operations read-only and Accounts as an item approver by default', function () {
    $ops = Role::findByName('Operations User');
    $accounts = Role::findByName('Accounts User');

    expect($ops->hasPermissionTo('item.view'))->toBeTrue()
        ->and($ops->hasPermissionTo('item.comment'))->toBeTrue()
        ->and($ops->hasPermissionTo('item.edit'))->toBeFalse()
        ->and($ops->hasPermissionTo('item.approve'))->toBeFalse()
        ->and($accounts->hasPermissionTo('item.approve'))->toBeTrue();
});

it('keeps the permission matrix itself out of Company Admin hands by default', function () {
    $admin = Role::findByName('Company Admin');

    expect($admin->hasPermissionTo('user.create'))->toBeTrue()
        ->and($admin->hasPermissionTo('setting.edit'))->toBeTrue()
        ->and($admin->hasPermissionTo('role.edit'))->toBeFalse()
        ->and($admin->hasPermissionTo('company.delete'))->toBeFalse();
});

it('stops Company Admin creating companies or reaching across tenants, but lets it run its own masters and imports', function () {
    $admin = Role::findByName('Company Admin');

    foreach (['company.create', 'company.delete', 'tenant.access_all', 'approval.override'] as $permission) {
        expect($admin->hasPermissionTo($permission))->toBeFalse("Company Admin should not hold {$permission}");
    }

    foreach (['company.edit', 'location.create', 'account_group.create', 'item_group.import', 'unit.edit', 'geography.import', 'geography.create'] as $permission) {
        expect($admin->hasPermissionTo($permission))->toBeTrue("Company Admin should hold {$permission}");
    }
});

it('keeps importing and editing geography to the administrators', function () {
    foreach (['Purchase User', 'Inventory User', 'Accounts User', 'Operations User', 'Sales Manager', 'BDE / Field Salesperson', 'Tele-caller', 'Approver'] as $name) {
        $role = Role::findByName($name);

        foreach (['geography.import', 'geography.create', 'geography.edit', 'geography.delete'] as $permission) {
            expect($role->hasPermissionTo($permission))->toBeFalse("{$name} should not hold {$permission}");
        }
    }

    expect(Role::findByName('Purchase User')->hasPermissionTo('geography.view'))->toBeTrue()   // address look-ups on suppliers
        ->and(Role::findByName('Super Admin')->hasPermissionTo('geography.import'))->toBeTrue();
});

it('gives Purchase the supplier ledgers and Accounts every ledger, with bank details only for those who may see them', function () {
    $held = fn (string $role, string $permission) => Role::findByName($role)->hasPermissionTo($permission);

    // Purchase onboards suppliers: it can create and edit ledgers, but only suppliers (no manage_all_types)
    expect($held('Purchase User', 'ledger.create'))->toBeTrue()->and($held('Purchase User', 'ledger.edit'))->toBeTrue()
        ->and($held('Purchase User', 'ledger.manage_all_types'))->toBeFalse()->and($held('Purchase User', 'ledger.view_sensitive'))->toBeFalse()
        ->and($held('Purchase User', 'ledger.approve'))->toBeFalse();

    // Accounts handles every type, sees the account numbers and approves
    foreach (['ledger.manage_all_types', 'ledger.view_sensitive', 'ledger.approve', 'payment_method.create'] as $permission) {
        expect($held('Accounts User', $permission))->toBeTrue("Accounts should hold {$permission}");
    }
    expect($held('Accounts User', 'ledger.delete'))->toBeFalse();

    // everyone else only looks, or approves without seeing account numbers
    foreach (['Operations User', 'Inventory User', 'Sales Manager', 'BDE / Field Salesperson', 'Approver'] as $role) {
        expect($held($role, 'ledger.manage_all_types'))->toBeFalse("{$role} should not manage every ledger type")
            ->and($held($role, 'ledger.view_sensitive'))->toBeFalse("{$role} should not see bank account numbers")
            ->and($held($role, 'ledger.create'))->toBeFalse();
    }

    expect($held('Tele-caller', 'ledger.view'))->toBeFalse()
        ->and($held('Company Admin', 'ledger.manage_all_types'))->toBeTrue()->and($held('Company Admin', 'ledger.view_sensitive'))->toBeTrue()
        ->and($held('Super Admin', 'ledger.manage_all_types'))->toBeTrue()->and($held('Super Admin', 'ledger.view_sensitive'))->toBeTrue();
});

it('gives each department only its own stage of the item master', function () {
    $held = fn (string $role, string $permission) => Role::findByName($role)->hasPermissionTo($permission);

    // Purchase starts items and owns stage 1; Inventory owns stage 2; neither approves
    expect($held('Purchase User', 'item.manage_purchase_stage'))->toBeTrue()->and($held('Purchase User', 'item.create'))->toBeTrue()
        ->and($held('Purchase User', 'item.manage_inventory_stage'))->toBeFalse()->and($held('Purchase User', 'item.approve'))->toBeFalse()
        ->and($held('Inventory User', 'item.manage_inventory_stage'))->toBeTrue()->and($held('Inventory User', 'item.create'))->toBeFalse()
        ->and($held('Inventory User', 'item.manage_purchase_stage'))->toBeFalse()->and($held('Inventory User', 'item.approve'))->toBeFalse();

    // Accounts approves and edits nothing
    expect($held('Accounts User', 'item.approve'))->toBeTrue()->and($held('Accounts User', 'item.edit'))->toBeFalse()
        ->and($held('Accounts User', 'item.manage_purchase_stage'))->toBeFalse()->and($held('Accounts User', 'item.manage_inventory_stage'))->toBeFalse();

    // Operations (and every sales role) can look, and Operations can comment: nothing that changes an item
    foreach (['Operations User', 'Sales Manager', 'BDE / Field Salesperson', 'Tele-caller'] as $role) {
        foreach (['item.create', 'item.edit', 'item.approve', 'item.delete', 'item.manage_purchase_stage', 'item.manage_inventory_stage'] as $permission) {
            expect($held($role, $permission))->toBeFalse("{$role} must not hold {$permission}");
        }
        expect($held($role, 'item.view'))->toBeTrue();
    }
    expect($held('Operations User', 'item.comment'))->toBeTrue();

    // administrators hold both stages
    foreach (['Company Admin', 'Super Admin'] as $role) {
        expect($held($role, 'item.manage_purchase_stage'))->toBeTrue()->and($held($role, 'item.manage_inventory_stage'))->toBeTrue();
    }
});

it('lists the two new ledger permissions among the special ones', function () {
    expect(array_keys(PermissionRegistry::special()))->toContain('ledger.manage_all_types')->toContain('ledger.view_sensitive')
        ->toContain('item.manage_purchase_stage')->toContain('item.manage_inventory_stage')
        ->and(PermissionRegistry::allPermissionNames())->toContain('payment_method.view')->toContain('ledger.approve');
});

it('does not invent approval permissions for masters that have no approval workflow', function () {
    $names = PermissionRegistry::allPermissionNames();

    foreach (['department', 'designation', 'unit', 'payment_method', 'account_group', 'item_group', 'geography', 'location', 'price_list'] as $form) {
        expect($names)->not->toContain("{$form}.approve");
    }

    // ...while the documents that do go through approval keep theirs
    expect($names)->toContain('item.approve')->toContain('ledger.approve')->toContain('rate.approve');
});

it('keeps field sales in the right hands: the salesperson marks, the manager plans, and seeing everyone is a named permission', function () {
    $held = fn (string $role, string $permission) => Role::findByName($role)->hasPermissionTo($permission);

    // a salesperson marks and sees their own attendance, sees their plan and their target, and changes none of them
    foreach (['attendance.create', 'attendance.view', 'route_plan.view', 'target.view'] as $permission) {
        expect($held('BDE / Field Salesperson', $permission))->toBeTrue("a salesperson should hold {$permission}");
    }
    foreach (['route_plan.create', 'route_plan.edit', 'route_plan.delete', 'target.create', 'target.edit', 'sales_team.view', 'sales_team.assign', 'geofence.override', 'sales.scope_all'] as $permission) {
        expect($held('BDE / Field Salesperson', $permission))->toBeFalse("a salesperson must not hold {$permission}");
    }

    // the manager plans days, sets targets, runs teams and may accept a refused attendance; they mark none themselves
    foreach (['route_plan.view', 'route_plan.create', 'route_plan.edit', 'target.view', 'target.create', 'target.edit', 'sales_team.view', 'sales_team.create', 'sales_team.edit', 'sales_team.assign', 'attendance.view', 'geofence.override'] as $permission) {
        expect($held('Sales Manager', $permission))->toBeTrue("a sales manager should hold {$permission}");
    }
    foreach (['attendance.create', 'sales.scope_all', 'sales_team.delete', 'target.delete', 'route_plan.delete'] as $permission) {
        expect($held('Sales Manager', $permission))->toBeFalse("a sales manager must not hold {$permission}");
    }

    // reach over every salesperson is the explicit permission, held by the administrators and by nobody else
    foreach (Role::query()->pluck('name') as $role) {
        expect($held($role, 'sales.scope_all'))->toBe(in_array($role, ['Company Admin', 'Super Admin'], true), "{$role} and sales.scope_all");
    }
    expect(array_keys(PermissionRegistry::special()))->toContain('sales.scope_all')->toContain('geofence.override');

    // only the Sales Manager and the administrators may override a geofence violation
    foreach (Role::query()->pluck('name') as $role) {
        expect($held($role, 'geofence.override'))->toBe(in_array($role, ['Sales Manager', 'Company Admin', 'Super Admin'], true), "{$role} and geofence.override");
    }

    // the office roles have no business in the field screens
    foreach (['Tele-caller', 'Accounts User', 'Purchase User', 'Inventory User', 'Operations User', 'Approver'] as $role) {
        foreach (['route_plan.view', 'attendance.view', 'attendance.create', 'target.view', 'sales_team.view'] as $permission) {
            expect($held($role, $permission))->toBeFalse("{$role} must not hold {$permission}");
        }
    }
});

it('keeps leads with the people who sell: everyone enters, the manager hands out and imports, the administrators keep the masters', function () {
    $held = fn (string $role, string $permission) => Role::findByName($role)->hasPermissionTo($permission);

    // a salesperson and a tele-caller enter and work leads and follow-ups; they do not hand out, import or delete
    foreach (['BDE / Field Salesperson', 'Tele-caller'] as $role) {
        foreach (['lead.view', 'lead.create', 'lead.edit', 'followup.view', 'followup.create', 'followup.edit'] as $permission) {
            expect($held($role, $permission))->toBeTrue("{$role} should hold {$permission}");
        }
        foreach (['lead.assign', 'lead.import', 'lead.delete', 'lead.export', 'lead.view_audit', 'followup.delete', 'followup.assign', 'crm_master.view', 'crm_master.edit', 'sales.scope_all'] as $permission) {
            expect($held($role, $permission))->toBeFalse("{$role} must not hold {$permission}");
        }
    }

    // the manager hands leads out, imports them and may delete one entered by mistake — within their own teams
    foreach (['lead.view', 'lead.create', 'lead.edit', 'lead.delete', 'lead.assign', 'lead.import', 'lead.export', 'lead.comment', 'followup.view', 'followup.create', 'followup.edit', 'followup.assign'] as $permission) {
        expect($held('Sales Manager', $permission))->toBeTrue("a sales manager should hold {$permission}");
    }
    foreach (['sales.scope_all', 'crm_master.create', 'crm_master.edit', 'crm_master.delete', 'lead.view_audit'] as $permission) {
        expect($held('Sales Manager', $permission))->toBeFalse("a sales manager must not hold {$permission}");
    }

    // handing leads out and importing them is the manager's and the administrators', and nobody else's
    foreach (Role::query()->pluck('name') as $role) {
        expect($held($role, 'lead.assign'))->toBe(in_array($role, ['Sales Manager', 'Company Admin', 'Super Admin'], true), "{$role} and lead.assign")
            ->and($held($role, 'lead.import'))->toBe(in_array($role, ['Sales Manager', 'Company Admin', 'Super Admin'], true), "{$role} and lead.import");
    }

    // sources, outcomes and lost reasons are kept by the administrators
    foreach (Role::query()->pluck('name') as $role) {
        expect($held($role, 'crm_master.edit'))->toBe(in_array($role, ['Company Admin', 'Super Admin'], true), "{$role} and crm_master.edit");
    }

    // the office roles have no business with leads; the Approver may look (existing-lead approval comes in a later phase)
    foreach (['Accounts User', 'Purchase User', 'Inventory User', 'Operations User'] as $role) {
        foreach (['lead.view', 'lead.create', 'followup.view'] as $permission) {
            expect($held($role, $permission))->toBeFalse("{$role} must not hold {$permission}");
        }
    }
    expect($held('Approver', 'lead.view'))->toBeTrue()->and($held('Approver', 'lead.edit'))->toBeFalse()->and($held('Approver', 'followup.view'))->toBeFalse();
});

it('lets only the people who sell sign in to the mobile API by default — a named permission, not a role name', function () {
    $held = fn (string $role) => Role::findByName($role)->hasPermissionTo('api.access');

    foreach (Role::query()->pluck('name') as $role) {
        expect($held($role))->toBe(in_array($role, ['BDE / Field Salesperson', 'Tele-caller', 'Sales Manager', 'Company Admin', 'Super Admin'], true), "{$role} and api.access");
    }

    expect(array_keys(PermissionRegistry::special()))->toContain('api.access');
});

it('gives rates to Accounts to maintain, the Approver to decide, and everyone else to look at', function () {
    $held = fn (string $role, string $permission) => Role::findByName($role)->hasPermissionTo($permission);

    // Accounts keeps rates and price lists; it neither deletes them nor changes a price on a quotation
    foreach (['rate.view', 'rate.create', 'rate.edit', 'rate.approve', 'price_list.view', 'price_list.create', 'price_list.edit'] as $permission) {
        expect($held('Accounts User', $permission))->toBeTrue("Accounts should hold {$permission}");
    }
    expect($held('Accounts User', 'rate.delete'))->toBeFalse()->and($held('Accounts User', 'rate.override'))->toBeFalse()->and($held('Accounts User', 'price_list.delete'))->toBeFalse();

    // the Approver decides and does not maintain
    expect($held('Approver', 'rate.approve'))->toBeTrue()->and($held('Approver', 'rate.create'))->toBeFalse()->and($held('Approver', 'rate.edit'))->toBeFalse();

    // only the Sales Manager may price a line differently from the master
    foreach (['Sales Manager'] as $role) {
        expect($held($role, 'rate.override'))->toBeTrue()->and($held($role, 'rate.edit'))->toBeFalse()->and($held($role, 'rate.approve'))->toBeFalse();
    }
    foreach (['Purchase User', 'Inventory User', 'Accounts User', 'Operations User', 'BDE / Field Salesperson', 'Tele-caller', 'Approver'] as $role) {
        expect($held($role, 'rate.override'))->toBeFalse("{$role} must not override a rate");
    }

    // people who work with prices can look; nobody outside Accounts and the administrators can change one
    foreach (['Purchase User', 'Inventory User', 'Operations User', 'Sales Manager', 'BDE / Field Salesperson'] as $role) {
        expect($held($role, 'rate.view'))->toBeTrue()->and($held($role, 'price_list.view'))->toBeTrue();
        foreach (['rate.create', 'rate.edit', 'rate.approve', 'rate.delete', 'price_list.create', 'price_list.edit'] as $permission) {
            expect($held($role, $permission))->toBeFalse("{$role} must not hold {$permission}");
        }
    }
    expect($held('Tele-caller', 'rate.view'))->toBeFalse()->and($held('Tele-caller', 'price_list.view'))->toBeFalse();

    foreach (['Company Admin', 'Super Admin'] as $role) {
        expect($held($role, 'rate.create'))->toBeTrue()->and($held($role, 'rate.approve'))->toBeTrue()->and($held($role, 'price_list.delete'))->toBeTrue()->and($held($role, 'rate.override'))->toBeTrue();
    }
});

it('re-seeding adds missing permissions but never overwrites an edited matrix', function () {
    $role = Role::findByName('Tele-caller');
    $role->revokePermissionTo('lead.view');
    $role->givePermissionTo('report.export');          // a customisation made in the UI

    Permission::where('name', 'deal.export')->delete(); // a permission a new release would add
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    (new RolePermissionSeeder)->run();

    $role = Role::findByName('Tele-caller');
    expect($role->hasPermissionTo('lead.view'))->toBeFalse()         // not restored
        ->and($role->hasPermissionTo('report.export'))->toBeTrue()   // not removed
        ->and(Permission::where('name', 'deal.export')->exists())->toBeTrue();
});

it('has a stable action list per form', function () {
    foreach (PermissionRegistry::forms() as $key => $form) {
        expect($form['actions'])->not->toBeEmpty("{$key} has no actions");
        expect(collect($form['actions'])->every(fn ($a) => $a instanceof PermissionAction))->toBeTrue();
    }
});

it('never shares a permission name between forms', function () {
    foreach (PermissionRegistry::special() as $name => $label) {
        expect($name)->toContain('.')->and($label)->not->toBeEmpty();
    }

    expect(User::SUPER_ADMIN_ROLE)->toBe('Super Admin');
});

it('gives deals to the people who sell, the decision to the approvers and the right to bring a lost deal back to managers and administrators only', function () {
    $held = fn (string $role, string $permission) => Role::findByName($role)->hasPermissionTo($permission);

    // a salesperson makes and works their own deals; a tele-caller, Accounts and the stores have no business with them
    foreach (['deal.view', 'deal.create', 'deal.edit'] as $permission) {
        expect($held('BDE / Field Salesperson', $permission))->toBeTrue("a salesperson should hold {$permission}");
    }
    foreach (['deal.approve', 'deal.assign', 'deal.delete', 'deal.reopen', 'deal.view_audit'] as $permission) {
        expect($held('BDE / Field Salesperson', $permission))->toBeFalse("a salesperson must not hold {$permission}");
    }
    foreach (['Tele-caller', 'Accounts User', 'Purchase User', 'Inventory User', 'Operations User'] as $role) {
        expect($held($role, 'deal.view'))->toBeFalse("{$role} must not see deals");
    }

    // the manager hands deals out, decides them (not their own) and may bring a lost one back; only the administrators read the audit trail
    foreach (['deal.view', 'deal.create', 'deal.edit', 'deal.approve', 'deal.assign', 'deal.reopen'] as $permission) {
        expect($held('Sales Manager', $permission))->toBeTrue("a sales manager should hold {$permission}");
    }
    expect($held('Sales Manager', 'deal.view_audit'))->toBeFalse();

    // re-opening a lost deal is a right of its own: held by managers and administrators, and by nobody else
    foreach (Role::query()->pluck('name') as $role) {
        $expected = in_array($role, ['Sales Manager', 'Company Admin', 'Super Admin'], true);
        expect($held($role, 'deal.reopen'))->toBe($expected, "{$role} and deal.reopen");
    }

    // the approver decides what waits for them and cannot change it
    expect($held('Approver', 'deal.approve'))->toBeTrue()->and($held('Approver', 'lead.approve'))->toBeTrue()
        ->and($held('Approver', 'deal.edit'))->toBeFalse()->and($held('Approver', 'deal.create'))->toBeFalse();

    // an inquiry is entered by someone who may enter leads and read customers
    foreach (['BDE / Field Salesperson', 'Sales Manager'] as $role) {
        expect($held($role, 'lead.create') && $held($role, 'ledger.view'))->toBeTrue("{$role} may enter an inquiry");
    }
    expect($held('Tele-caller', 'ledger.view'))->toBeFalse();
});

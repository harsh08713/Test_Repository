<?php

use App\Modules\Shared\Http\Livewire\Roles\PermissionMatrix;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->company = makeCompany('ACME');
    $this->super = makeUser(['Super Admin'], [$this->company]);
    $this->purchase = Role::findByName('Purchase User');
});

it('lists every role except Super Admin and loads the selected role’s permissions', function () {
    $component = Livewire::actingAs($this->super)->test(PermissionMatrix::class)
        ->assertSeeHtml('>Purchase User</option>')->assertSeeHtml('>Operations User</option>')
        ->assertDontSeeHtml('>Super Admin</option>')   // never selectable: it bypasses the matrix
        ->set('roleId', $this->purchase->id);

    expect($component->get('selected'))->toContain('item.create')->not->toContain('item.approve');
});

it('renders a checkbox only where an action applies to a form', function () {
    Livewire::actingAs($this->super)->test(PermissionMatrix::class)
        ->assertSeeHtml('value="item.approve"')
        ->assertSeeHtml('value="lead.assign"')
        ->assertDontSeeHtml('value="role.approve"')      // no Approve column for the matrix itself
        ->assertDontSeeHtml('value="audit_log.delete"');
});

it('grants and revokes permissions and audits the change', function () {
    Livewire::actingAs($this->super)->test(PermissionMatrix::class)
        ->set('roleId', $this->purchase->id)
        ->set('selected', ['item.view', 'item.edit', 'rate.approve'])
        ->call('save')
        ->assertHasNoErrors();

    $role = $this->purchase->fresh();
    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(['item.edit', 'item.view', 'rate.approve']);

    $entry = Activity::query()->withoutGlobalScopes()->where('event', 'permissions_changed')->latest('id')->first();
    expect($entry->causer_id)->toBe($this->super->id)
        ->and($entry->properties['role'])->toBe('Purchase User')
        ->and($entry->properties['granted'])->toContain('rate.approve')
        ->and($entry->properties['revoked'])->toContain('item.create');
});

it('takes effect for users holding the role straight away', function () {
    $user = makeUser(['Purchase User'], [$this->company]);
    expect($user->can('item.create'))->toBeTrue();

    Livewire::actingAs($this->super)->test(PermissionMatrix::class)
        ->set('roleId', $this->purchase->id)->set('selected', ['item.view'])->call('save');

    expect($user->fresh()->can('item.create'))->toBeFalse()->and($user->fresh()->can('item.view'))->toBeTrue();
});

it('ignores permission names that are not in the registry', function () {
    Livewire::actingAs($this->super)->test(PermissionMatrix::class)
        ->set('roleId', $this->purchase->id)
        ->set('selected', ['item.view', 'made.up', 'item.explode'])
        ->call('save');

    expect(Permission::whereIn('name', ['made.up', 'item.explode'])->count())->toBe(0)
        ->and($this->purchase->fresh()->permissions->pluck('name')->all())->toBe(['item.view']);
});

it('does not write an audit entry when nothing changed', function () {
    $before = Activity::query()->withoutGlobalScopes()->count();

    Livewire::actingAs($this->super)->test(PermissionMatrix::class)
        ->set('roleId', $this->purchase->id)->call('save');

    expect(Activity::query()->withoutGlobalScopes()->count())->toBe($before);
});

it('refuses access to someone without the view permission', function () {
    Livewire::actingAs(makeUser(['Company Admin'], [$this->company]))->test(PermissionMatrix::class)->assertForbidden();
});

it('lets a view-only user look but not save', function () {
    Role::create(['name' => 'Matrix reader'])->givePermissionTo('role.view');
    $reader = makeUser(['Matrix reader'], [$this->company]);

    Livewire::actingAs($reader)->test(PermissionMatrix::class)
        ->assertDontSee('Save permissions')
        ->set('roleId', $this->purchase->id)->set('selected', ['item.view'])->call('save')->assertForbidden();
});

it('stops someone granting permissions they do not hold themselves', function () {
    Role::create(['name' => 'Matrix editor'])->givePermissionTo(['role.view', 'role.edit', 'lead.view']);
    $editor = makeUser(['Matrix editor'], [$this->company]);

    Livewire::actingAs($editor)->test(PermissionMatrix::class)
        ->set('roleId', $this->purchase->id)
        ->set('selected', ['item.view', 'item.approve'])      // item.approve is not theirs to give
        ->call('save')
        ->assertHasErrors(['selected']);

    expect($this->purchase->fresh()->hasPermissionTo('item.approve'))->toBeFalse();
});

it('can never edit the Super Admin role', function () {
    $superRole = Role::findByName('Super Admin');

    Livewire::actingAs($this->super)->test(PermissionMatrix::class)
        ->set('roleId', $superRole->id)
        ->assertNotFound();
});

it('creates a new role', function () {
    Livewire::actingAs($this->super)->test(PermissionMatrix::class)
        ->set('newRole', 'Stores Clerk')->call('createRole')->assertHasNoErrors()
        ->assertSet('newRole', '');

    expect(Role::where('name', 'Stores Clerk')->exists())->toBeTrue();
});

it('refuses duplicate and reserved role names', function () {
    Livewire::actingAs($this->super)->test(PermissionMatrix::class)
        ->set('newRole', 'Purchase User')->call('createRole')->assertHasErrors(['newRole'])
        ->set('newRole', 'super admin')->call('createRole')->assertHasErrors(['newRole']);
});

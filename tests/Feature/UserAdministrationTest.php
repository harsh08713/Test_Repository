<?php

use App\Models\User;
use App\Modules\Shared\Http\Livewire\Users\Form;
use App\Modules\Shared\Http\Livewire\Users\UserList;
use App\Modules\Shared\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');
    $this->pun = makeLocation($this->globex, 'PUN');

    $this->super = makeUser(['Super Admin'], [$this->acme, $this->globex], attributes: ['name' => 'Sue Super']);
    $this->admin = makeUser(['Company Admin'], [$this->acme], attributes: ['name' => 'Carl Admin']);
});

function validForm(array $overrides = []): array
{
    return [
        'name' => 'New Person',
        'email' => 'new.person@example.test',
        'password' => 'Strong-Pass-123',
        'password_confirmation' => 'Strong-Pass-123',
        'company_ids' => [(string) test()->acme->id],
        'all_location_company_ids' => [(string) test()->acme->id],
        'role_names' => ['Sales Manager'],
        ...$overrides,
    ];
}

function fillForm($component, array $values)
{
    foreach ($values as $property => $value) {
        $component->set($property, $value);
    }

    return $component;
}

// ---- creating ----------------------------------------------------------------------------------

it('creates a user with company access, roles and a working password', function () {
    fillForm(Livewire::actingAs($this->admin)->test(Form::class), validForm())
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.users.index'));

    $user = User::where('email', 'new.person@example.test')->firstOrFail();

    expect($user->is_active)->toBeTrue()
        ->and($user->default_company_id)->toBe($this->acme->id)
        ->and($user->roles->pluck('name')->all())->toBe(['Sales Manager'])
        ->and(Hash::check('Strong-Pass-123', $user->password))->toBeTrue()
        ->and(DB::table('company_user')->where('user_id', $user->id)->value('all_locations'))->toBe(1);
});

it('limits a branch-restricted user to the branches ticked', function () {
    fillForm(Livewire::actingAs($this->admin)->test(Form::class), validForm([
        'all_location_company_ids' => [],
        'location_ids' => [(string) $this->ahd->id],
    ]))->call('save')->assertHasNoErrors();

    $user = User::where('email', 'new.person@example.test')->firstOrFail();

    expect($user->locations()->withoutGlobalScopes()->pluck('locations.id')->all())->toBe([$this->ahd->id])
        ->and(DB::table('company_user')->where('user_id', $user->id)->value('all_locations'))->toBe(0);

    // ...and the data scope really does hide the other branch from them.
    $this->actingAs($user);
    $this->mum->forceFill([])->save();
    expect(Location::pluck('code')->all())->toBe(['AHD']);
});

it('validates the profile fields', function () {
    fillForm(Livewire::actingAs($this->admin)->test(Form::class), validForm([
        'name' => '', 'email' => 'not-an-email', 'password' => 'short', 'password_confirmation' => 'different',
    ]))->call('save')->assertHasErrors(['name', 'email', 'password']);
});

it('requires a password for a new user and a unique email', function () {
    fillForm(Livewire::actingAs($this->admin)->test(Form::class), validForm(['password' => '', 'password_confirmation' => '']))
        ->call('save')->assertHasErrors(['password' => 'required']);

    fillForm(Livewire::actingAs($this->admin)->test(Form::class), validForm(['email' => $this->super->email]))
        ->call('save')->assertHasErrors(['email' => 'unique']);
});

it('forbids a user without the create permission', function () {
    $manager = makeUser(['Sales Manager'], [$this->acme]);

    Livewire::actingAs($manager)->test(Form::class)->assertForbidden();
});

// ---- privilege-escalation guards -----------------------------------------------------------------

it('stops a Company Admin granting the Super Admin role', function () {
    fillForm(Livewire::actingAs($this->admin)->test(Form::class), validForm(['role_names' => ['Super Admin']]))
        ->call('save')->assertHasErrors(['role_names']);

    expect(User::where('email', 'new.person@example.test')->exists())->toBeFalse();
});

it('lets a Super Admin grant the Super Admin role', function () {
    fillForm(Livewire::actingAs($this->super)->test(Form::class), validForm(['role_names' => ['Super Admin']]))
        ->call('save')->assertHasNoErrors();

    expect(User::where('email', 'new.person@example.test')->first()->isSuperAdmin())->toBeTrue();
});

it('only lets someone grant roles whose permissions they hold themselves', function () {
    Role::create(['name' => 'User manager'])->givePermissionTo(['user.view', 'user.create', 'user.edit', 'lead.view']);
    Role::create(['name' => 'Lead viewer'])->givePermissionTo('lead.view');
    $limited = makeUser(['User manager'], [$this->acme]);

    // Company Admin holds far more than they do: not assignable.
    fillForm(Livewire::actingAs($limited)->test(Form::class), validForm(['role_names' => ['Company Admin']]))
        ->call('save')->assertHasErrors(['role_names']);

    fillForm(Livewire::actingAs($limited)->test(Form::class), validForm(['role_names' => ['Lead viewer']]))
        ->call('save')->assertHasNoErrors();
});

it('does not even list roles the actor may not assign', function () {
    Livewire::actingAs($this->admin)->test(Form::class)
        ->assertSee('Sales Manager')->assertDontSee('Super Admin');

    Livewire::actingAs($this->super)->test(Form::class)->assertSee('Super Admin');
});

it('stops granting access to a company the actor cannot access', function () {
    fillForm(Livewire::actingAs($this->admin)->test(Form::class), validForm(['company_ids' => [(string) $this->globex->id]]))
        ->call('save')->assertHasErrors(['company_ids']);
});

it('rejects a branch that belongs to a company that was not selected', function () {
    fillForm(Livewire::actingAs($this->super)->test(Form::class), validForm([
        'all_location_company_ids' => [], 'location_ids' => [(string) $this->pun->id],
    ]))->call('save')->assertHasErrors(['location_ids']);
});

it('does not offer or accept a deleted branch', function () {
    $closed = makeLocation($this->acme, 'ZZCLOSED');
    $closed->delete();

    // Branches are only listed once "every branch" is unticked.
    Livewire::actingAs($this->admin)->test(Form::class)
        ->set('all_location_company_ids', [])
        ->assertSee('AHD')->assertDontSee('ZZCLOSED');

    fillForm(Livewire::actingAs($this->admin)->test(Form::class), validForm([
        'all_location_company_ids' => [], 'location_ids' => [(string) $closed->id],
    ]))->call('save')->assertHasErrors(['location_ids']);
});

it('requires the default company to be one of the selected companies', function () {
    fillForm(Livewire::actingAs($this->super)->test(Form::class), validForm(['default_company_id' => (string) $this->globex->id]))
        ->call('save')->assertHasErrors(['default_company_id']);
});

it('stops a Company Admin opening or editing a Super Admin', function () {
    Livewire::actingAs($this->admin)->test(Form::class, ['user' => $this->super])->assertForbidden();
});

it('stops editing a user of a company the actor cannot access', function () {
    $foreign = makeUser(['Tele-caller'], [$this->globex]);

    Livewire::actingAs($this->admin)->test(Form::class, ['user' => $foreign])->assertForbidden();
});

it('stops a user deactivating their own account', function () {
    Livewire::actingAs($this->admin)->test(Form::class, ['user' => $this->admin])
        ->set('is_active', false)->call('save')->assertHasErrors(['is_active']);

    expect($this->admin->fresh()->is_active)->toBeTrue();
});

// ---- editing -------------------------------------------------------------------------------------

it('loads the existing access when editing', function () {
    $target = makeUser(['Tele-caller'], [$this->acme], locations: [$this->ahd], attributes: ['name' => 'Tina Target']);

    Livewire::actingAs($this->admin)->test(Form::class, ['user' => $target])
        ->assertSet('name', 'Tina Target')
        ->assertSet('role_names', ['Tele-caller'])
        ->assertSet('company_ids', [(string) $this->acme->id])
        ->assertSet('location_ids', [(string) $this->ahd->id]);
});

it('keeps the password when the field is left blank on edit', function () {
    $target = makeUser(['Tele-caller'], [$this->acme]);
    $before = $target->password;

    Livewire::actingAs($this->admin)->test(Form::class, ['user' => $target])
        ->set('name', 'Renamed')->call('save')->assertHasNoErrors();

    expect($target->fresh()->name)->toBe('Renamed')->and($target->fresh()->password)->toBe($before);
});

it('changes roles and records the change in the audit log', function () {
    $target = makeUser(['Tele-caller'], [$this->acme]);

    Livewire::actingAs($this->admin)->test(Form::class, ['user' => $target])
        ->set('role_names', ['Sales Manager'])->call('save')->assertHasNoErrors();

    expect($target->fresh()->roles->pluck('name')->all())->toBe(['Sales Manager']);

    $entry = Activity::query()->withoutGlobalScopes()->where('event', 'access_changed')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->company_id)->toBe($this->acme->id)
        ->and($entry->properties['old']['roles'])->toBe(['Tele-caller'])
        ->and($entry->properties['attributes']['roles'])->toBe(['Sales Manager']);
});

// ---- list ----------------------------------------------------------------------------------------

it('lists only the users the actor may manage', function () {
    $mine = makeUser(['Tele-caller'], [$this->acme], attributes: ['name' => 'Visible Vera']);
    makeUser(['Tele-caller'], [$this->globex], attributes: ['name' => 'Foreign Frank']);

    Livewire::actingAs($this->admin)->test(UserList::class)
        ->assertSee('Visible Vera')
        ->assertSee('Carl Admin')
        ->assertDontSee('Foreign Frank')
        ->assertDontSee('Sue Super');

    Livewire::actingAs($this->super)->test(UserList::class)
        ->assertSee('Visible Vera')->assertSee('Foreign Frank')->assertSee('Sue Super');
});

it('searches and filters the list', function () {
    makeUser(['Tele-caller'], [$this->acme], attributes: ['name' => 'Alpha Ann', 'email' => 'ann@example.test']);
    makeUser(['Tele-caller'], [$this->acme], attributes: ['name' => 'Beta Bob', 'email' => 'bob@example.test', 'is_active' => false]);

    Livewire::actingAs($this->admin)->test(UserList::class)
        ->set('search', 'alpha')->assertSee('Alpha Ann')->assertDontSee('Beta Bob')
        ->set('search', '')->set('status', 'inactive')->assertSee('Beta Bob')->assertDontSee('Alpha Ann');
});

it('lists nobody for a user without the view permission', function () {
    Livewire::actingAs(makeUser(['Tele-caller'], [$this->acme]))->test(UserList::class)->assertForbidden();
});

it('toggles a user active state but never your own', function () {
    $target = makeUser(['Tele-caller'], [$this->acme]);

    Livewire::actingAs($this->admin)->test(UserList::class)
        ->call('toggleActive', $target->id);
    expect($target->fresh()->is_active)->toBeFalse();

    Livewire::actingAs($this->admin)->test(UserList::class)
        ->call('toggleActive', $this->admin->id)->assertStatus(422);
    expect($this->admin->fresh()->is_active)->toBeTrue();
});

it('cannot deactivate a Super Admin through the list', function () {
    Livewire::actingAs($this->admin)->test(UserList::class)
        ->call('toggleActive', $this->super->id)->assertNotFound();

    expect($this->super->fresh()->is_active)->toBeTrue();
});

it('never writes passwords into the audit log', function () {
    fillForm(Livewire::actingAs($this->admin)->test(Form::class), validForm())->call('save');

    $logged = Activity::query()->withoutGlobalScopes()->where('log_name', 'user')->get()
        ->map(fn ($a) => json_encode($a->properties))->implode(' ');

    expect($logged)->not->toContain('Strong-Pass-123')->not->toContain('password');
});

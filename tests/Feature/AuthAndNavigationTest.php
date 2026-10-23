<?php

use App\Modules\Shared\Http\Livewire\Auth\Login;
use App\Modules\Shared\Http\Livewire\NotificationBell;
use App\Modules\Shared\Notifications\ApprovalRequestedNotification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = makeCompany('ACME', ['name' => 'Acme Industries']);
    $this->ahd = makeLocation($this->company, 'AHD');
    $this->user = makeUser(['Tele-caller'], [$this->company], attributes: ['email' => 'tanvi@example.test', 'name' => 'Tanvi Telecaller']);
});

// ---- authentication ------------------------------------------------------------------------------

it('sends guests to the sign-in page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/admin/users')->assertRedirect('/login');
    $this->get('/approvals')->assertRedirect('/login');
    $this->post('/context', ['company_id' => 1])->assertRedirect('/login');
});

it('renders the sign-in page', function () {
    $this->get('/login')->assertOk()->assertSee('Sign in')->assertSee('Email')->assertSee('Password');
});

it('keeps signed-in users away from the sign-in page', function () {
    $this->actingAs($this->user)->get('/login')->assertRedirect();
});

it('signs in with valid credentials', function () {
    Livewire::test(Login::class)
        ->set('email', 'tanvi@example.test')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($this->user);
});

it('records the last sign-in time', function () {
    expect($this->user->last_login_at)->toBeNull();

    Livewire::test(Login::class)->set('email', 'tanvi@example.test')->set('password', 'password')->call('login');

    expect($this->user->fresh()->last_login_at)->not->toBeNull();
});

it('rejects a wrong password without revealing which part was wrong', function () {
    Livewire::test(Login::class)
        ->set('email', 'tanvi@example.test')
        ->set('password', 'nope')
        ->call('login')
        ->assertHasErrors(['email'])
        ->assertSee('do not match an active account');

    $this->assertGuest();

    Livewire::test(Login::class)->set('email', 'ghost@example.test')->set('password', 'x')->call('login')
        ->assertSee('do not match an active account');
});

it('refuses a deactivated account even with the right password', function () {
    $this->user->forceFill(['is_active' => false])->save();

    Livewire::test(Login::class)->set('email', 'tanvi@example.test')->set('password', 'password')->call('login')->assertHasErrors(['email']);

    $this->assertGuest();
});

it('requires both fields', function () {
    Livewire::test(Login::class)->call('login')->assertHasErrors(['email' => 'required', 'password' => 'required']);
});

it('throttles repeated failed attempts', function () {
    RateLimiter::clear('tanvi@example.test|127.0.0.1');

    $login = Livewire::test(Login::class)->set('email', 'tanvi@example.test')->set('password', 'wrong');

    foreach (range(1, 5) as $_) {
        $login->call('login');
    }

    $login->set('password', 'password')->call('login')->assertSee('Too many sign-in attempts');

    $this->assertGuest();
});

it('signs out', function () {
    $this->actingAs($this->user)->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
});

it('signs a user out on their next request once deactivated', function () {
    $this->actingAs($this->user)->get('/dashboard')->assertOk();

    $this->user->forceFill(['is_active' => false])->save();

    $this->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

// ---- pages & navigation --------------------------------------------------------------------------

it('shows the dashboard with the company and the user’s name', function () {
    $this->actingAs($this->user)->get('/dashboard')
        ->assertOk()
        ->assertSee('Welcome back, Tanvi Telecaller')
        ->assertSee('Acme Industries')
        ->assertSee('AHD');
});

it('redirects the root URL to the dashboard', function () {
    $this->actingAs($this->user)->get('/')->assertRedirect('/dashboard');
});

it('shows only the menu entries a user is allowed to open', function () {
    $this->actingAs($this->user)->get('/dashboard')
        ->assertSee('Dashboard')->assertSee('Approvals')
        ->assertDontSee('Roles &amp; permissions', false)->assertDontSee('Audit log')->assertDontSee('Number series');

    $admin = makeUser(['Company Admin'], [$this->company]);
    $this->actingAs($admin)->get('/dashboard')
        ->assertSee('Users')->assertSee('Audit log')->assertSee('Number series')
        ->assertDontSee('Roles &amp; permissions', false);

    $super = makeUser(['Super Admin'], [$this->company]);
    $this->actingAs($super)->get('/dashboard')->assertSee('Roles &amp; permissions', false);
});

it('enforces page permissions on the server, not just in the menu', function (string $role, string $url, int $status) {
    $user = makeUser([$role], [$this->company]);

    $this->actingAs($user)->get($url)->assertStatus($status);
})->with([
    'telecaller / users' => ['Tele-caller', '/admin/users', 403],
    'telecaller / roles' => ['Tele-caller', '/admin/roles', 403],
    'telecaller / settings' => ['Tele-caller', '/admin/settings', 403],
    'telecaller / audit' => ['Tele-caller', '/admin/audit-log', 403],
    'telecaller / approvals' => ['Tele-caller', '/approvals', 200],
    'sales manager / users' => ['Sales Manager', '/admin/users', 403],
    'company admin / users' => ['Company Admin', '/admin/users', 200],
    'company admin / settings' => ['Company Admin', '/admin/settings', 200],
    'company admin / approval levels' => ['Company Admin', '/admin/settings/approval-levels', 200],
    'company admin / number series' => ['Company Admin', '/admin/settings/number-series', 200],
    'company admin / audit' => ['Company Admin', '/admin/audit-log', 200],
    'company admin / roles' => ['Company Admin', '/admin/roles', 403],
    'super admin / roles' => ['Super Admin', '/admin/roles', 200],
    'super admin / users form' => ['Super Admin', '/admin/users/create', 200],
]);

// ---- company / branch switcher -------------------------------------------------------------------

it('switches to another company the user belongs to', function () {
    $globex = makeCompany('GLOBEX', ['name' => 'Globex Ltd']);
    makeLocation($globex, 'PUN');
    $user = makeUser(['Company Admin'], [$this->company, $globex]);

    $this->actingAs($user)->get('/dashboard')->assertSee('Acme Industries');

    $this->post('/context', ['company_id' => $globex->id])->assertRedirect();

    $this->get('/dashboard')->assertOk()->assertSee('PUN')->assertDontSee('>AHD<', false);
    expect(session('tenant.company_id'))->toBe($globex->id);
});

it('refuses to switch to a company the user does not belong to', function () {
    $globex = makeCompany('GLOBEX');

    $this->actingAs($this->user)->post('/context', ['company_id' => $globex->id])->assertForbidden();

    expect(session('tenant.company_id'))->toBeNull();
});

it('refuses to switch to a branch the user cannot access', function () {
    $mum = makeLocation($this->company, 'MUM');
    $restricted = makeUser(['Sales Manager'], [$this->company], locations: [$this->ahd]);

    $this->actingAs($restricted)->post('/context', ['location_id' => $mum->id])->assertForbidden();
});

it('remembers the chosen default branch', function () {
    $mum = makeLocation($this->company, 'MUM');
    $admin = makeUser(['Company Admin'], [$this->company]);

    $this->actingAs($admin)->post('/context', ['location_id' => $mum->id])->assertRedirect();

    expect(session('tenant.location_id'))->toBe($mum->id);

    $this->post('/context', ['location_id' => ''])->assertRedirect();
    expect(session('tenant.location_id'))->toBeNull();
});

it('validates the context form', function () {
    $this->actingAs($this->user)->post('/context', ['company_id' => 'abc'])->assertSessionHasErrors('company_id');
});

// ---- notifications bell --------------------------------------------------------------------------

it('shows unread notifications in the bell and marks them read', function () {
    $this->user->notify(new ApprovalRequestedNotification('Item 7 — Valve', '/items/7', 'Pooja', 1));

    $component = Livewire::actingAs($this->user)->test(NotificationBell::class)
        ->assertSee('Pooja submitted Item 7 — Valve for your approval.');

    expect($this->user->unreadNotifications()->count())->toBe(1);

    $component->call('markAllRead');

    expect($this->user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('opens a notification and follows only relative links', function () {
    $this->user->notify(new ApprovalRequestedNotification('Safe', '/items/7', 'Pooja', 1));
    $this->user->notify(new ApprovalRequestedNotification('Evil', 'https://evil.example/phish', 'Mallory', 1));

    $safe = $this->user->notifications->firstWhere('data.title', 'Safe');
    $evil = $this->user->notifications->firstWhere('data.title', 'Evil');

    Livewire::actingAs($this->user)->test(NotificationBell::class)
        ->call('openNotification', $safe->id)->assertRedirect('/items/7');

    Livewire::actingAs($this->user)->test(NotificationBell::class)
        ->call('openNotification', $evil->id)->assertNoRedirect();

    expect($this->user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('cannot open someone else’s notification', function () {
    $other = makeUser(['Tele-caller'], [$this->company]);
    $other->notify(new ApprovalRequestedNotification('Private', null, 'X', 1));
    $theirs = $other->notifications()->first();

    Livewire::actingAs($this->user)->test(NotificationBell::class)
        ->call('openNotification', $theirs->id)
        ->assertNotFound();

    expect($other->fresh()->unreadNotifications()->count())->toBe(1);   // untouched
});

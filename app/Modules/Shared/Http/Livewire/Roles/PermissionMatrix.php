<?php

namespace App\Modules\Shared\Http\Livewire\Roles;

use App\Models\User;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Services\PermissionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * The permission matrix: one row per form, one column per action, per role.
 * Super Admin is not listed — it bypasses permission checks entirely.
 *
 * Non-super-admins can only grant permissions they hold themselves, so editing a role can
 * never be used to escalate beyond your own access.
 */
#[Title('Roles & permissions')]
class PermissionMatrix extends Component
{
    public ?int $roleId = null;

    /** @var list<string> permission names ticked for the selected role */
    public array $selected = [];

    public string $newRole = '';

    public function mount(): void
    {
        Gate::authorize('role.view');

        $this->roleId = $this->roles()->first()?->id;
        $this->loadRole();
    }

    /** @return Collection<int, Role> */
    public function roles(): Collection
    {
        return Role::query()->where('name', '!=', User::SUPER_ADMIN_ROLE)->orderBy('name')->get();
    }

    public function updatedRoleId(): void
    {
        $this->loadRole();
    }

    private function loadRole(): void
    {
        $this->selected = $this->roleId
            ? $this->role()->permissions()->pluck('name')->all()
            : [];
    }

    private function role(): Role
    {
        return Role::query()
            ->where('name', '!=', User::SUPER_ADMIN_ROLE)
            ->findOrFail($this->roleId);
    }

    public function save(): void
    {
        Gate::authorize('role.edit');

        $role = $this->role();
        $valid = PermissionRegistry::allPermissionNames();
        $requested = array_values(array_intersect($this->selected, $valid));

        $before = $role->permissions()->pluck('name')->all();
        $added = array_values(array_diff($requested, $before));
        $removed = array_values(array_diff($before, $requested));

        // Same rule for everyone, Super Admin included (who simply holds every permission): no escalation.
        $actor = Auth::user();
        $held = $actor->getAllPermissions()->pluck('name')->all();

        if (array_diff($added, $held) !== []) {
            throw ValidationException::withMessages(['selected' => 'You can only grant permissions that you hold yourself.']);
        }

        if ($added === [] && $removed === []) {
            session()->flash('status', 'No changes to save.');

            return;
        }

        $role->syncPermissions($requested);

        activity('role')
            ->causedBy($actor)->performedOn($role)->event('permissions_changed')
            ->withProperties(['role' => $role->name, 'granted' => $added, 'revoked' => $removed])
            ->log('permissions_changed');

        session()->flash('status', "Permissions for {$role->name} saved.");
    }

    public function createRole(): void
    {
        Gate::authorize('role.edit');

        $this->validate(
            ['newRole' => ['required', 'string', 'max:60', 'unique:roles,name']],
            ['newRole.unique' => 'A role with this name already exists.'],
        );

        if (strcasecmp($this->newRole, User::SUPER_ADMIN_ROLE) === 0) {
            throw ValidationException::withMessages(['newRole' => 'That name is reserved.']);
        }

        $role = Role::query()->create(['name' => trim($this->newRole)]);

        activity('role')->causedBy(Auth::user())->performedOn($role)->event('created')->log('created');

        $this->newRole = '';
        $this->roleId = $role->id;
        $this->loadRole();
    }

    public function render(): View
    {
        return view('shared.roles.matrix', [
            'roles' => $this->roles(),
            'groups' => PermissionRegistry::groups(),
            'forms' => PermissionRegistry::forms(),
            'actions' => PermissionAction::cases(),
            'special' => PermissionRegistry::special(),
            'canEdit' => Gate::allows('role.edit'),
        ]);
    }
}

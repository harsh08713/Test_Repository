<?php

namespace App\Modules\Shared\Http\Livewire\Users;

use App\Models\User;
use App\Modules\Shared\Models\Company;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * Create / edit a user together with their access: companies, branches and roles.
 *
 * Privilege-escalation guards (all enforced on the server):
 *  - only a Super Admin can grant the Super Admin role;
 *  - others can only grant roles whose permissions they hold themselves;
 *  - companies/branches can only be granted from the actor's own access;
 *  - nobody can deactivate their own account.
 */
#[Title('User')]
class Form extends Component
{
    #[Locked]
    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $is_active = true;

    /** Bound to a <select>, which submits '' for "none", so not a strict ?int. */
    public string|int|null $default_company_id = null;

    /** @var list<int|string> */
    public array $company_ids = [];

    /** @var list<int|string> companies where the user may see every branch */
    public array $all_location_company_ids = [];

    /** @var list<int|string> */
    public array $location_ids = [];

    /** @var list<string> */
    public array $role_names = [];

    public function mount(?User $user = null): void
    {
        if ($user?->exists) {
            Gate::authorize('update', $user);

            $this->userId = $user->id;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->phone = (string) $user->phone;
            $this->is_active = $user->is_active;
            $this->default_company_id = $user->default_company_id;
            $this->role_names = $user->roles->pluck('name')->all();

            $memberships = DB::table('company_user')->where('user_id', $user->id)->get();
            $this->company_ids = $memberships->pluck('company_id')->map(fn ($id) => (string) $id)->all();
            $this->all_location_company_ids = $memberships->where('all_locations', true)->pluck('company_id')->map(fn ($id) => (string) $id)->all();
            $this->location_ids = $user->locations()->withoutGlobalScopes(Tenancy::SCOPES)->pluck('locations.id')->map(fn ($id) => (string) $id)->all();
        } else {
            Gate::authorize('create', User::class);
            $this->default_company_id = app(TenantContext::class)->activeCompanyId();
            $this->company_ids = $this->default_company_id ? [(string) $this->default_company_id] : [];
            $this->all_location_company_ids = $this->company_ids;
        }
    }

    /** @return Collection<int, Company> */
    public function companies(): Collection
    {
        return Company::query()->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, Location> every branch of every company the actor can access */
    public function locations(): Collection
    {
        $companyIds = app(TenantContext::class)->accessibleCompanyIds();

        return Location::withoutGlobalScopes(Tenancy::SCOPES)->whereIn('company_id', $companyIds)->orderBy('name')->get(['id', 'company_id', 'name', 'code']);
    }

    /** @return Collection<int, Role> */
    public function assignableRoles(): Collection
    {
        $actor = Auth::user();
        $held = $actor->getAllPermissions()->pluck('name');

        // Nobody can hand out more than they hold; and the Super Admin role itself is only ever
        // assigned by a Super Admin, even if someone else happens to hold every permission.
        return Role::query()->with('permissions:id,name')->orderBy('name')->get()
            ->filter(fn (Role $role) => $role->permissions->pluck('name')->diff($held)->isEmpty()
                && ($role->name !== User::SUPER_ADMIN_ROLE || $actor->isSuperAdmin()))
            ->values();
    }

    public function save(): void
    {
        $editing = $this->userId !== null ? User::query()->findOrFail($this->userId) : null;
        Gate::authorize($editing ? 'update' : 'create', $editing ?? User::class);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->userId)],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => [$editing ? 'nullable' : 'required', 'string', Password::defaults(), 'confirmed'],
            'is_active' => ['boolean'],
            'default_company_id' => ['nullable', 'integer'],
            'company_ids' => ['array'],
            'company_ids.*' => ['integer'],
            'all_location_company_ids' => ['array'],
            'location_ids' => ['array'],
            'location_ids.*' => ['integer'],
            'role_names' => ['array'],
            'role_names.*' => ['string'],
        ]);

        $actor = Auth::user();
        $companyIds = collect($this->company_ids)->map(fn ($id) => (int) $id)->unique()->values();
        $accessible = collect(app(TenantContext::class)->accessibleCompanyIds());

        if ($companyIds->diff($accessible)->isNotEmpty()) {
            throw ValidationException::withMessages(['company_ids' => 'You can only grant access to companies you can access yourself.']);
        }

        $defaultCompanyId = filled($this->default_company_id) ? (int) $this->default_company_id : null;

        if ($defaultCompanyId !== null && ! $companyIds->contains($defaultCompanyId)) {
            throw ValidationException::withMessages(['default_company_id' => 'The default company must be one of the selected companies.']);
        }

        $allowedRoles = $this->assignableRoles()->pluck('name');
        if (collect($this->role_names)->diff($allowedRoles)->isNotEmpty()) {
            throw ValidationException::withMessages(['role_names' => 'You cannot assign one of the selected roles.']);
        }

        if ($editing?->is($actor) && ! $this->is_active) {
            throw ValidationException::withMessages(['is_active' => 'You cannot deactivate your own account.']);
        }

        $locationIds = collect($this->location_ids)->map(fn ($id) => (int) $id)->unique();
        $validLocationIds = Location::withoutGlobalScopes(Tenancy::SCOPES)
            ->whereIn('company_id', $companyIds)->whereIn('id', $locationIds)->pluck('id');

        if ($locationIds->diff($validLocationIds)->isNotEmpty()) {
            throw ValidationException::withMessages(['location_ids' => 'A selected branch does not belong to the selected companies.']);
        }

        DB::transaction(function () use ($data, $editing, $companyIds, $validLocationIds, $actor, $defaultCompanyId) {
            $attributes = [
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?: null,
                'is_active' => $this->is_active,
                'default_company_id' => $defaultCompanyId,
            ];

            $user = $editing ?? new User;
            $before = $editing ? $this->accessSnapshot($editing) : null;

            $user->fill($attributes);
            if (filled($data['password'] ?? null)) {
                $user->password = $data['password'];
            }
            $user->save();

            $allLocations = collect($this->all_location_company_ids)->map(fn ($id) => (int) $id);
            $user->companies()->sync($companyIds->mapWithKeys(fn ($id) => [
                $id => ['all_locations' => $allLocations->contains($id)],
            ])->all());
            $user->locations()->sync($validLocationIds->all());
            $user->syncRoles($this->role_names);

            app(TenantContext::class)->flush();

            $after = $this->accessSnapshot($user->fresh());

            if ($before !== $after) {
                activity('user')
                    ->causedBy($actor)->performedOn($user)->event('access_changed')
                    ->withProperties(['old' => $before, 'attributes' => $after])
                    ->log('access_changed');
            }
        });

        session()->flash('status', $editing ? 'User updated.' : 'User created.');

        $this->redirectRoute('admin.users.index', navigate: false);
    }

    /** @return array<string, mixed> */
    private function accessSnapshot(User $user): array
    {
        return [
            'roles' => $user->roles()->pluck('name')->sort()->values()->all(),
            'companies' => DB::table('company_user')->where('user_id', $user->id)->orderBy('company_id')
                ->get(['company_id', 'all_locations'])->map(fn ($r) => $r->company_id.($r->all_locations ? ' (all branches)' : ''))->all(),
            'locations' => $user->locations()->withoutGlobalScopes(Tenancy::SCOPES)->pluck('locations.id')->sort()->values()->all(),
        ];
    }

    public function render(): View
    {
        return view('shared.users.form', [
            'companies' => $this->companies(),
            'locations' => $this->locations()->groupBy('company_id'),
            'roles' => $this->assignableRoles(),
        ]);
    }
}

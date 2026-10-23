<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Users</h1>
            <p class="mt-1 text-sm text-slate-500">People who can sign in, the companies and branches they can see, and their roles.</p>
        </div>
        @can('create', \App\Models\User::class)
            <a href="{{ route('admin.users.create') }}" wire:navigate class="btn-primary">Add user</a>
        @endcan
    </div>

    <div class="card">
        <div class="card-header">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="user-search" class="sr-only">Search users</label>
                <input id="user-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search name or email…" class="input max-w-xs">
                <label for="user-status" class="sr-only">Status</label>
                <select id="user-status" wire:model.live="status" class="input w-40">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Name</th><th>Email</th><th>Roles</th><th>Last sign-in</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr wire:key="user-{{ $user->id }}">
                            <td class="font-medium text-slate-900">{{ $user->name }}</td>
                            <td>{{ $user->email }}</td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($user->roles as $role)
                                        <span class="badge bg-brand-50 text-brand-700">{{ $role->name }}</span>
                                    @empty
                                        <span class="text-slate-400">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="whitespace-nowrap text-slate-500">{{ $user->last_login_at ? \App\Modules\Shared\Support\Format::dateTime($user->last_login_at) : 'Never' }}</td>
                            <td>
                                <span class="badge {{ $user->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $user->is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td class="text-right">
                                @can('update', $user)
                                    <a href="{{ route('admin.users.edit', $user) }}" wire:navigate class="btn-ghost btn-sm">Edit</a>
                                    @if ($user->id !== auth()->id())
                                        <button type="button" wire:click="toggleActive({{ $user->id }})"
                                                wire:confirm="{{ $user->is_active ? 'Deactivate this user? They will be signed out.' : 'Reactivate this user?' }}"
                                                class="btn-ghost btn-sm">{{ $user->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-slate-500">No users match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $users->links() }}</div>
        @endif
    </div>
</div>

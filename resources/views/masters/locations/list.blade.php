<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Branches</h1>
            <p class="mt-1 text-sm text-slate-500">Offices, plants and warehouses of the active company. The branch code appears in every document number.</p>
        </div>
        @if ($canCreate)
            <a href="{{ route('masters.locations.create') }}" wire:navigate class="btn-primary">Add branch</a>
        @endif
    </div>

    <x-status />

    @error('list')
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>
    @enderror

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="l-search" class="sr-only">Search branches</label>
                <input id="l-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search code, name or city…" class="input max-w-xs">
                <label for="l-status" class="sr-only">Status</label>
                <select id="l-status" wire:model.live="status" class="input w-40">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Branch</th><th>City</th><th>State</th><th>PIN</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($locations as $location)
                        <tr wire:key="loc-{{ $location->id }}">
                            <td class="font-mono text-xs">{{ $location->code }}</td>
                            <td class="font-medium text-slate-900">{{ $location->name }}</td>
                            <td>{{ $location->city ?: '—' }}</td>
                            <td>{{ $location->state ?: '—' }}</td>
                            <td class="font-mono text-xs">{{ $location->pin_code ?: '—' }}</td>
                            <td><span class="badge {{ $location->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($location->status) }}</span></td>
                            <td class="whitespace-nowrap text-right">
                                @can('update', $location)
                                    <a href="{{ route('masters.locations.edit', $location) }}" wire:navigate class="btn-ghost btn-sm">Edit</a>
                                    <button type="button" wire:click="toggleStatus({{ $location->id }})" class="btn-ghost btn-sm">{{ $location->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                @endcan
                                @can('delete', $location)
                                    <button type="button" wire:click="delete({{ $location->id }})" wire:confirm="Delete this branch?" class="btn-ghost btn-sm text-rose-600">Delete</button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-slate-500">No branches yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($locations->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $locations->links() }}</div>
        @endif
    </div>
</div>

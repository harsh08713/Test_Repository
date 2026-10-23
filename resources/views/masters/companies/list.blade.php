<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Companies</h1>
            <p class="mt-1 text-sm text-slate-500">The legal entities you can work in. Each company has its own branches, masters, documents and numbering.</p>
        </div>
        @if ($canCreate)
            <a href="{{ route('masters.companies.create') }}" wire:navigate class="btn-primary">Add company</a>
        @endif
    </div>

    <x-status />

    @error('list')
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>
    @enderror

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="c-search" class="sr-only">Search companies</label>
                <input id="c-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, code or GSTIN…" class="input max-w-xs">
                <label for="c-status" class="sr-only">Status</label>
                <select id="c-status" wire:model.live="status" class="input w-40">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Company</th><th>Code</th><th>GSTIN</th><th>State</th><th>Branches</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($companies as $company)
                        <tr wire:key="company-{{ $company->id }}">
                            <td>
                                <div class="flex items-center gap-3">
                                    @if ($company->logo_path)
                                        <img src="{{ route('masters.companies.logo', $company) }}" alt="" class="size-8 rounded object-contain" loading="lazy">
                                    @else
                                        <span class="flex size-8 items-center justify-center rounded bg-slate-100 text-xs font-semibold text-slate-500">{{ \Illuminate\Support\Str::substr($company->name, 0, 1) }}</span>
                                    @endif
                                    <div>
                                        <p class="font-medium text-slate-900">{{ $company->name }}</p>
                                        @if ($company->legal_name && $company->legal_name !== $company->name)<p class="text-xs text-slate-500">{{ $company->legal_name }}</p>@endif
                                    </div>
                                </div>
                            </td>
                            <td class="font-mono text-xs">{{ $company->code }}</td>
                            <td class="font-mono text-xs">{{ $company->gstin ?: '—' }}</td>
                            <td>{{ $company->state ?: '—' }}</td>
                            <td>{{ $company->locations_count }}</td>
                            <td><span class="badge {{ $company->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($company->status) }}</span></td>
                            <td class="whitespace-nowrap text-right">
                                @can('update', $company)
                                    <a href="{{ route('masters.companies.edit', $company) }}" wire:navigate class="btn-ghost btn-sm">Edit</a>
                                    <button type="button" wire:click="toggleStatus({{ $company->id }})" class="btn-ghost btn-sm">{{ $company->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                @endcan
                                @can('delete', $company)
                                    <button type="button" wire:click="delete({{ $company->id }})" wire:confirm="Delete this company?" class="btn-ghost btn-sm text-rose-600">Delete</button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-slate-500">No companies.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($companies->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $companies->links() }}</div>
        @endif
    </div>
</div>

<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Territories</h1>
            <p class="mt-1 text-sm text-slate-500">A territory is a part of the map with one person responsible for it: the states, districts, blocks, PIN codes and customers it covers. It says who <em>should</em> work a place — it never moves a lead or a plan by itself.</p>
        </div>
        @if ($canCreate)
            <a href="{{ route('field.territories.create') }}" wire:navigate class="btn-primary">Add territory</a>
        @endif
    </div>

    <x-status />

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="tr-search" class="sr-only">Search</label>
                <input id="tr-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search code or name…" class="input max-w-xs">

                <label for="tr-status" class="sr-only">Status</label>
                <select id="tr-status" wire:model.live="status" class="input w-40">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Territory</th><th>Owner</th><th>Branch</th><th class="text-right">Covers</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($territories as $row)
                        <tr wire:key="terr-{{ $row->id }}">
                            <td class="font-mono text-xs">{{ $row->code }}</td>
                            <td><a href="{{ route('field.territories.show', $row) }}" wire:navigate class="font-medium text-slate-900 hover:text-brand-700">{{ $row->name }}</a></td>
                            <td class="text-slate-600">{{ $row->owner?->name ?? '—' }}</td>
                            <td class="text-slate-600">{{ $row->branch?->name ?? 'Company-wide' }}</td>
                            <td class="text-right tabular-nums">{{ $row->areas_count }}</td>
                            <td><span class="badge {{ $row->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($row->status) }}</span></td>
                            <td class="whitespace-nowrap text-right">
                                <a href="{{ route('field.territories.show', $row) }}" wire:navigate class="btn-ghost btn-sm">View</a>
                                @can('update', $row)
                                    <a href="{{ route('field.territories.edit', $row) }}" wire:navigate class="btn-ghost btn-sm">Edit</a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-slate-500">No territories yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($territories->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $territories->links() }}</div>
        @endif
    </div>
</div>

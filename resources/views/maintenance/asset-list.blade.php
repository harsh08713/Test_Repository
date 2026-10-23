<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Assets</h1>
            <p class="mt-1 text-sm text-slate-500">Machines, vehicles and instruments that are maintained, by branch. An asset is never deleted: its history stays, and it is made inactive when it is retired.</p>
        </div>
        @if ($canCreate)<a href="{{ route('maintenance.assets.create') }}" wire:navigate class="btn-primary">New asset</a>@endif
    </div>

    <x-status />

    <div class="card">
        <div class="card-header"><label for="as-q" class="sr-only">Search</label><input id="as-q" type="search" wire:model.live.debounce.400ms="search" placeholder="Code, name or serial number" class="input w-64" maxlength="80"></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Asset</th><th>Kind</th><th>Branch</th><th>Criticality</th><th class="text-right">Open work</th><th>State</th></tr></thead>
                <tbody>
                    @forelse ($assets as $a)
                        <tr wire:key="as-{{ $a->id }}">
                            <td><a href="{{ route('maintenance.assets.show', $a) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $a->code }}</a></td>
                            <td class="font-medium text-slate-900">{{ $a->name }}@if ($a->make_model) <span class="font-normal text-slate-500">· {{ $a->make_model }}</span>@endif</td>
                            <td>{{ $categories[$a->category] ?? $a->category }}</td>
                            <td>{{ $a->location?->name }}</td>
                            <td>{{ $criticality[$a->criticality] ?? $a->criticality }}</td>
                            <td class="text-right tabular-nums">{{ $a->open_orders_count }}</td>
                            <td>{{ $a->status === 'active' ? 'In service' : 'Inactive' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-slate-500">No assets yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3">{{ $assets->links() }}</div>
    </div>
</div>

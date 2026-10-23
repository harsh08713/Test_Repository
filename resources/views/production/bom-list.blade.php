<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Bills of materials</h1>
            <p class="mt-1 text-sm text-slate-500">What it takes to make an item. A bill is approved by another person before a production order can use it; once approved it does not change — a change is a new bill, and the old one is made inactive.</p>
        </div>
        @if ($canCreate)<a href="{{ route('production.boms.create') }}" wire:navigate class="btn-primary">New bill</a>@endif
    </div>

    <x-status />

    <div class="card">
        <div class="card-header"><label for="bl-q" class="sr-only">Search</label><input id="bl-q" type="search" wire:model.live.debounce.400ms="search" placeholder="Code or item" class="input w-64" maxlength="80"></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Makes</th><th class="text-right">Output</th><th class="text-right">Components</th><th>Approval</th><th>In use</th></tr></thead>
                <tbody>
                    @forelse ($boms as $b)
                        <tr wire:key="bom-{{ $b->id }}">
                            <td><a href="{{ route('production.boms.show', $b) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $b->code }}</a></td>
                            <td class="font-medium text-slate-900">{{ $b->item?->name }}</td>
                            <td class="text-right tabular-nums">{{ rtrim(rtrim((string) $b->output_quantity, '0'), '.') }}</td>
                            <td class="text-right tabular-nums">{{ $b->components_count }}</td>
                            <td><span class="badge {{ $b->approval_status->badge() }}">{{ $b->approval_status->label() }}</span></td>
                            <td>{{ $b->status === 'active' ? 'Yes' : 'Inactive' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-slate-500">No bills of materials yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3">{{ $boms->links() }}</div>
    </div>
</div>

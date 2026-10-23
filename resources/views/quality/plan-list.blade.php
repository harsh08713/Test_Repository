<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Inspection plans</h1>
            <p class="mt-1 text-sm text-slate-500">What to check on an item at each stage. A plan is approved by another person before an inspection can use it; once approved it does not change — a change is a new plan, and the old one is made inactive.</p>
        </div>
        @if ($canCreate)<a href="{{ route('quality.plans.create') }}" wire:navigate class="btn-primary">New plan</a>@endif
    </div>

    <x-status />

    <div class="card">
        <div class="card-header"><label for="pl-q" class="sr-only">Search</label><input id="pl-q" type="search" wire:model.live.debounce.400ms="search" placeholder="Code, name or item" class="input w-64" maxlength="80"></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Plan</th><th>Item</th><th>Stage</th><th class="text-right">Checks</th><th>Approval</th><th>In use</th></tr></thead>
                <tbody>
                    @forelse ($plans as $p)
                        <tr wire:key="plan-{{ $p->id }}">
                            <td><a href="{{ route('quality.plans.show', $p) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $p->code }}</a></td>
                            <td class="font-medium text-slate-900">{{ $p->name }}</td>
                            <td>{{ $p->item?->name }}</td>
                            <td>{{ $stages[$p->stage] ?? $p->stage }}</td>
                            <td class="text-right tabular-nums">{{ $p->parameters_count }}</td>
                            <td><span class="badge {{ $p->approval_status->badge() }}">{{ $p->approval_status->label() }}</span></td>
                            <td>{{ $p->status === 'active' ? 'Yes' : 'Inactive' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-slate-500">No inspection plans yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3">{{ $plans->links() }}</div>
    </div>
</div>

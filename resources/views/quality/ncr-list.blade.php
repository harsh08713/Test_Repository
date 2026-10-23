@php $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Non-conformances</h1>
            <p class="mt-1 text-sm text-slate-500">Something that did not meet its requirement: what was decided about the goods, and the loss incident and corrective action it led to. A rejected inspection raises one by itself.</p>
        </div>
        @if ($canCreate)<a href="{{ route('quality.ncrs.create') }}" wire:navigate class="btn-primary">Raise one</a>@endif
    </div>

    <x-status />

    <div class="card">
        <div class="card-header flex flex-wrap items-center gap-3">
            <label for="nc-q" class="sr-only">Search</label><input id="nc-q" type="search" wire:model.live.debounce.400ms="search" placeholder="Number, item or batch" class="input w-64" maxlength="80">
            <label for="nc-st" class="sr-only">State</label>
            <select id="nc-st" wire:model.live="state" class="input w-40"><option value="">Every state</option>@foreach ($states as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>
            <label for="nc-sv" class="sr-only">Severity</label>
            <select id="nc-sv" wire:model.live="severity" class="input w-40"><option value="">Every severity</option>@foreach ($severities as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Report</th><th>Item</th><th>Batch</th><th>Branch</th><th class="text-right">Quantity</th><th>Severity</th><th>State</th></tr></thead>
                <tbody>
                    @forelse ($ncrs as $n)
                        <tr wire:key="ncr-{{ $n->id }}">
                            <td><a href="{{ route('quality.ncrs.show', $n) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $n->ncr_number }}</a></td>
                            <td class="font-medium text-slate-900">{{ $n->item_name }}</td>
                            <td>{{ $n->batch?->batch_no ?? '—' }}</td>
                            <td>{{ $n->location?->name }}</td>
                            <td class="text-right tabular-nums">{{ $qty($n->quantity_affected) }}</td>
                            <td><span @class(['badge', 'bg-slate-100 text-slate-700' => $n->severity === 'minor', 'bg-amber-100 text-amber-800' => $n->severity === 'major', 'bg-rose-100 text-rose-800' => $n->severity === 'critical'])>{{ $severities[$n->severity] }}</span></td>
                            <td>{{ $states[$n->state] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-slate-500">No non-conformance reports yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3">{{ $ncrs->links() }}</div>
    </div>
</div>

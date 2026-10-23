@php $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Inspections</h1>
            <p class="mt-1 text-sm text-slate-500">A lot checked against an approved plan. A batch is held (nobody can issue or move it) while it is inspected, released when it passes or is accepted with a deviation, and kept on hold when it is rejected.</p>
        </div>
        @if ($canCreate)<a href="{{ route('quality.inspections.create') }}" wire:navigate class="btn-primary">Start an inspection</a>@endif
    </div>

    <x-status />

    <div class="card">
        <div class="card-header flex flex-wrap items-center gap-3">
            <label for="in-q" class="sr-only">Search</label><input id="in-q" type="search" wire:model.live.debounce.400ms="search" placeholder="Number, item or batch" class="input w-64" maxlength="80">
            <label for="in-st" class="sr-only">State</label>
            <select id="in-st" wire:model.live="state" class="input w-64"><option value="">Every state</option>@foreach ($states as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Inspection</th><th>Item</th><th>Batch</th><th>Branch</th><th class="text-right">Lot</th><th>Started</th><th>State</th></tr></thead>
                <tbody>
                    @forelse ($inspections as $i)
                        <tr wire:key="qi-{{ $i->id }}">
                            <td><a href="{{ route('quality.inspections.show', $i) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $i->inspection_number }}</a></td>
                            <td class="font-medium text-slate-900">{{ $i->item_name }}</td>
                            <td>{{ $i->batch?->batch_no ?? '—' }}</td>
                            <td>{{ $i->location?->name }}</td>
                            <td class="text-right tabular-nums">{{ $qty($i->lot_quantity) }}</td>
                            <td class="whitespace-nowrap tabular-nums text-slate-600">{{ $i->created_at?->format('d-m-Y') }}</td>
                            <td><span @class(['badge', 'bg-amber-100 text-amber-800' => in_array($i->state, ['open', 'failed'], true), 'bg-emerald-100 text-emerald-800' => in_array($i->state, ['accepted', 'deviation'], true), 'bg-rose-100 text-rose-800' => $i->state === 'rejected', 'bg-slate-100 text-slate-700' => $i->state === 'cancelled'])>{{ $states[$i->state] }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-slate-500">No inspections yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3">{{ $inspections->links() }}</div>
    </div>
</div>

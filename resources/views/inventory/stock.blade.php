@php use App\Modules\Shared\Support\Format; $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Stock on hand</h1>
            <p class="mt-1 text-sm text-slate-500">What each branch holds now, in the item’s base unit, valued at moving-average cost. Goods come in through approved goods receipts; use a stock entry for a count difference, scrap or transfer.</p>
        </div>
        @if ($canEnter)<a href="{{ route('inventory.entry') }}" wire:navigate class="btn-primary">New stock entry</a>@endif
    </div>

    <x-status />

    <dl class="grid gap-3 sm:grid-cols-2">
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Lines shown</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format($rows->total()) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Value of the stock shown</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($value, 2) }}</dd></div>
    </dl>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="st-search" class="sr-only">Search</label>
                <input id="st-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Item code or name…" class="input max-w-xs">
                <label for="st-branch" class="sr-only">Branch</label>
                <select id="st-branch" wire:model.live="branch" class="input w-44">
                    <option value="">All my branches</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Item</th><th>Branch</th><th>Batch</th><th class="text-right">On hand</th><th class="text-right">Average cost (₹)</th><th class="text-right">Value (₹)</th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="sb-{{ $r->id }}">
                            <td><span class="font-medium text-slate-900">{{ $r->item?->name }}</span> <span class="font-mono text-xs text-slate-500">{{ $r->item?->code }}</span></td>
                            <td>{{ $r->location?->name }}</td>
                            <td>@if ($r->batch)<span class="font-mono text-xs">{{ $r->batch->batch_no }}</span>@if ($r->batch->expiry_date) <span class="text-xs text-slate-500">exp {{ $r->batch->expiry_date->format('d-m-Y') }}</span>@endif @else <span class="text-slate-400">—</span>@endif</td>
                            <td class="text-right tabular-nums">{{ $qty($r->quantity) }} <span class="text-slate-500">{{ $r->item?->baseUnit?->code }}</span></td>
                            <td class="text-right tabular-nums">{{ Format::number(bcdiv((string) $r->value, (string) $r->quantity, 4), 2) }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($r->value, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-slate-500">No stock yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($rows->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $rows->links() }}</div>@endif
    </div>
</div>

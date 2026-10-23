@php use App\Modules\Shared\Support\Format; $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
<div class="space-y-5">
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">Stock ledger</h1>
        <p class="mt-1 text-sm text-slate-500">Every movement of stock, newest first. A movement is never changed or removed: a mistake is put right by another one.</p>
    </div>

    <x-status />

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="mv-search" class="sr-only">Search</label>
                <input id="mv-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Item code or name…" class="input max-w-xs">
                <label for="mv-type" class="sr-only">Kind</label>
                <select id="mv-type" wire:model.live="type" class="input w-48">
                    <option value="">All kinds</option>
                    @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
                <label for="mv-branch" class="sr-only">Branch</label>
                <select id="mv-branch" wire:model.live="branch" class="input w-44">
                    <option value="">All my branches</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>When</th><th>Kind</th><th>Item</th><th>Branch</th><th>Batch</th><th class="text-right">Quantity</th><th class="text-right">Value (₹)</th><th>Why</th><th></th></tr></thead>
                <tbody>
                    @forelse ($movements as $m)
                        <tr wire:key="mv-{{ $m->id }}">
                            <td class="whitespace-nowrap tabular-nums text-slate-600">{{ $m->moved_at->format('d-m-Y H:i') }}</td>
                            <td>{{ $m->label() }}</td>
                            <td><span class="font-medium text-slate-900">{{ $m->item?->name }}</span> <span class="font-mono text-xs text-slate-500">{{ $m->item?->code }}</span></td>
                            <td>{{ $m->location?->name }}</td>
                            <td class="font-mono text-xs">{{ $m->batch?->batch_no }}</td>
                            <td @class(['text-right tabular-nums', 'text-rose-700' => bccomp((string) $m->quantity, '0', 3) < 0])>{{ $qty($m->quantity) }} <span class="text-slate-500">{{ $m->item?->baseUnit?->code }}</span></td>
                            <td class="text-right tabular-nums">{{ Format::number($m->value, 2) }}</td>
                            <td class="text-xs text-slate-600">{{ $m->reason ?? ($m->source_type ? strtoupper($m->source_type).' #'.$m->source_id : '') }}@if ($m->creator) · {{ $m->creator->name }}@endif</td>
                            <td class="whitespace-nowrap text-right">@if ($canReportLoss && in_array($m->movement_type, ['scrap', 'adjustment_out'], true))<a href="{{ route('loss.incidents.create', ['source' => 'stock_movement', 'id' => $m->id]) }}" wire:navigate class="btn-ghost btn-sm">Report as loss</a>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-10 text-center text-slate-500">No movements yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($movements->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $movements->links() }}</div>@endif
    </div>
</div>

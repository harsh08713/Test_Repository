@php $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Production orders</h1>
            <p class="mt-1 text-sm text-slate-500">What is to be made, where, and how far it has got. Material is issued from stock and the finished goods are received into stock at the order’s branch.</p>
        </div>
        @if ($canCreate)<a href="{{ route('production.orders.create') }}" wire:navigate class="btn-primary">New order</a>@endif
    </div>

    <x-status />

    <div class="card">
        <div class="card-header flex flex-wrap items-center gap-3">
            <label for="po-q" class="sr-only">Search</label><input id="po-q" type="search" wire:model.live.debounce.400ms="search" placeholder="Number or item" class="input w-64" maxlength="80">
            <label for="po-st" class="sr-only">State</label>
            <select id="po-st" wire:model.live="state" class="input w-44"><option value="">Every state</option>@foreach ($states as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Order</th><th>Item</th><th>Branch</th><th class="text-right">Planned</th><th class="text-right">Made</th><th>Dates</th><th>State</th></tr></thead>
                <tbody>
                    @forelse ($orders as $o)
                        <tr wire:key="mo-{{ $o->id }}">
                            <td><a href="{{ route('production.orders.show', $o) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $o->order_number }}</a></td>
                            <td class="font-medium text-slate-900">{{ $o->item_name }}</td>
                            <td>{{ $o->location?->name }}</td>
                            <td class="text-right tabular-nums">{{ $qty($o->planned_quantity) }}</td>
                            <td class="text-right tabular-nums">{{ $qty($made[$o->id]->good ?? 0) }}@if (($made[$o->id]->rejected ?? 0) > 0) <span class="text-xs text-rose-700">(+{{ $qty($made[$o->id]->rejected) }} rejected)</span>@endif</td>
                            <td class="whitespace-nowrap tabular-nums text-slate-600">{{ $o->planned_start->format('d-m-Y') }} → {{ $o->planned_end->format('d-m-Y') }}</td>
                            <td><span @class(['badge', 'bg-slate-100 text-slate-700' => in_array($o->state, ['draft', 'cancelled'], true), 'bg-amber-100 text-amber-800' => in_array($o->state, ['released', 'in_progress'], true), 'bg-emerald-100 text-emerald-800' => $o->state === 'completed'])>{{ $states[$o->state] }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-slate-500">No production orders yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3">{{ $orders->links() }}</div>
    </div>
</div>

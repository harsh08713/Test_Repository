@php $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Reorder suggestions</h1>
            <p class="mt-1 text-sm text-slate-500">Items whose stock, together with what is still due on approved orders, is at or below the reorder level set on the item for a branch. The suggestion brings stock up to the maximum. Quantities are in base units. Nothing is ordered for you.</p>
        </div>
        @if ($canOrder)<a href="{{ route('procurement.orders.create') }}" wire:navigate class="btn-primary">New purchase order</a>@endif
    </div>

    <div class="card">
        <div class="card-header">
            <label for="ro-branch" class="sr-only">Branch</label>
            <select id="ro-branch" wire:model.live="branch" class="input w-44">
                <option value="">All my branches</option>
                @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Item</th><th>Branch</th><th class="text-right">On hand</th><th class="text-right">Due on orders</th><th class="text-right">Reorder level</th><th class="text-right">Maximum</th><th class="text-right">Suggested</th><th>Last supplier</th><th>Could arrive by</th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="ro-{{ $r['item_id'] }}-{{ $r['location_id'] }}">
                            <td><span class="font-medium text-slate-900">{{ $r['item_name'] }}</span> <span class="font-mono text-xs text-slate-500">{{ $r['item_code'] }}</span></td>
                            <td>{{ $r['location_name'] }}</td>
                            <td class="text-right tabular-nums">{{ $qty($r['on_hand']) }}</td>
                            <td class="text-right tabular-nums">{{ $qty($r['on_order']) }}</td>
                            <td class="text-right tabular-nums">{{ $qty($r['reorder_level']) }}</td>
                            <td class="text-right tabular-nums">{{ $qty($r['maximum_stock']) }}</td>
                            <td class="text-right font-semibold tabular-nums">{{ $qty($r['suggested']) }}</td>
                            <td>{{ $r['supplier_name'] ?? '—' }}@if ($r['lead_time_days'] !== null) <span class="text-xs text-slate-500">({{ $r['lead_time_days'] }} days)</span>@endif</td>
                            <td class="tabular-nums">{{ $r['arrives_by'] ? \Illuminate\Support\Carbon::parse($r['arrives_by'])->format('d-m-Y') : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-10 text-center text-slate-500">Nothing needs ordering: every item with stock levels set is above its reorder level.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

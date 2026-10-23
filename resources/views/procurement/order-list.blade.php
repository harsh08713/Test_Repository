@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Purchase orders</h1>
            <p class="mt-1 text-sm text-slate-500">Orders to suppliers. Once an order is approved, goods are received against it with a goods receipt, within the item’s PO tolerance.</p>
        </div>
        @if ($canCreate)<a href="{{ route('procurement.orders.create') }}" wire:navigate class="btn-primary">New purchase order</a>@endif
    </div>

    <x-status />

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="po-search" class="sr-only">Search</label>
                <input id="po-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Number or supplier…" class="input max-w-xs">
                <label for="po-appr" class="sr-only">Approval</label>
                <select id="po-appr" wire:model.live="approval" class="input w-48">
                    <option value="">Any state</option>
                    <option value="draft">Draft</option>
                    <option value="pending">Waiting for approval</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <label for="po-branch" class="sr-only">Branch</label>
                <select id="po-branch" wire:model.live="branch" class="input w-44">
                    <option value="">All my branches</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Order</th><th>Supplier</th><th>Branch</th><th>Approval</th><th class="text-right">Total (₹)</th><th>Date</th><th>Expected</th></tr></thead>
                <tbody>
                    @forelse ($orders as $o)
                        <tr wire:key="po-{{ $o->id }}">
                            <td><a href="{{ route('procurement.orders.show', $o) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $o->po_number }}</a></td>
                            <td class="font-medium text-slate-900">{{ $o->supplier_name }}</td>
                            <td>{{ $o->location?->name }}</td>
                            <td>
                                @if ($o->isCancelled())<span class="badge bg-rose-100 text-rose-800">Cancelled</span>
                                @else<span class="badge {{ $o->approval_status->badge() }}">{{ $o->approval_status->label() }}</span>@endif
                            </td>
                            <td class="text-right tabular-nums">{{ Format::number($o->total_amount, 2) }}</td>
                            <td class="tabular-nums text-slate-600">{{ $o->po_date->format('d-m-Y') }}</td>
                            <td class="tabular-nums text-slate-600">{{ $o->expected_date->format('d-m-Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-slate-500">No purchase orders yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($orders->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $orders->links() }}</div>@endif
    </div>
</div>

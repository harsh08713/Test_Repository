@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Sales orders</h1>
            <p class="mt-1 text-sm text-slate-500">What customers have ordered, at which prices and for when. An order is made from an accepted quotation or an approved deal (from that page) or, for a regular customer, directly. Approving an order makes its invoice. You see the orders credited to you and your team; Accounts and Operations see them all.</p>
        </div>
        @if ($canCreate)<a href="{{ route('sales.orders.direct') }}" wire:navigate class="btn-primary">Direct order</a>@endif
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif

    <dl class="grid gap-3 sm:grid-cols-3">
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Approved orders</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format($standingCount) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Value of approved orders</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($standingValue, 0) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Waiting for approval</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format($waitingCount) }}</dd></div>
    </dl>

    <nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Order states">
        @foreach ($tabs as [$value, $label, $count])
            <button type="button" wire:click="setState('{{ $value }}')" wire:key="tab-{{ $value === '' ? 'all' : $value }}"
                    @class([
                        'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
                        'border-brand-600 text-brand-700' => $state === $value,
                        'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $state !== $value,
                    ])
                    @if ($state === $value) aria-current="page" @endif>{{ $label }} <span class="ml-1 tabular-nums text-xs text-slate-400">{{ number_format($count) }}</span></button>
        @endforeach
    </nav>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="so-search" class="sr-only">Search</label>
                <input id="so-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Customer, order number, customer’s order…" class="input max-w-xs">

                <label for="so-approval" class="sr-only">Approval</label>
                <select id="so-approval" wire:model.live="approval" class="input w-44">
                    <option value="">Any approval state</option>
                    @foreach ($approvals as $a)<option value="{{ $a->value }}">{{ $a->label() }}</option>@endforeach
                </select>

                <label for="so-branch" class="sr-only">Branch</label>
                <select id="so-branch" wire:model.live="branch" class="input w-44">
                    <option value="">All branches</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Order</th><th>Customer</th><th>Salesperson</th><th>State</th><th>Approval</th><th class="text-right">Total (₹)</th><th>Date</th><th>Delivery by</th></tr>
                </thead>
                <tbody>
                    @forelse ($orders as $o)
                        <tr wire:key="so-{{ $o->id }}">
                            <td>
                                <a href="{{ route('sales.orders.show', $o) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $o->so_number }}</a>
                                <div class="text-xs text-slate-500">{{ $o->location?->name }}@if ($o->customer_po_number) · PO {{ $o->customer_po_number }}@endif</div>
                            </td>
                            <td class="font-medium text-slate-900">{{ $o->party_name }}</td>
                            <td class="text-slate-600">{{ $o->owner?->name }}</td>
                            <td><span class="badge {{ $o->state->badge() }}">{{ $o->state->label() }}</span></td>
                            <td><span class="badge {{ $o->approval_status->badge() }}">{{ $o->approval_status->label() }}</span></td>
                            <td class="text-right tabular-nums">{{ Format::number($o->grand_total, 2) }}</td>
                            <td class="whitespace-nowrap tabular-nums text-slate-600">{{ $o->order_date->format('j M Y') }}</td>
                            <td class="whitespace-nowrap tabular-nums text-slate-600">{{ $o->delivery_date->format('j M Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-10 text-center text-slate-500">No sales orders match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($orders->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $orders->links() }}</div>
        @endif
    </div>
</div>

@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-5">
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">Sales invoices</h1>
        <p class="mt-1 text-sm text-slate-500">The invoice document made when a sales order is approved: one for each order, with its own number, a copy of the order’s prices and tax, and a link back to the order. Nothing is posted to stock or to accounts.</p>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif

    <dl class="grid gap-3 sm:grid-cols-2">
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Invoices issued</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format($tabs[0][2]) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Value issued</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($issuedValue, 0) }}</dd></div>
    </dl>

    <nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Invoice status">
        @foreach ($tabs as [$value, $label, $count])
            <button type="button" wire:click="setStatus('{{ $value }}')" wire:key="tab-{{ $value === '' ? 'all' : $value }}"
                    @class([
                        'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
                        'border-brand-600 text-brand-700' => $status === $value,
                        'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $status !== $value,
                    ])
                    @if ($status === $value) aria-current="page" @endif>{{ $label }} <span class="ml-1 tabular-nums text-xs text-slate-400">{{ number_format($count) }}</span></button>
        @endforeach
    </nav>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="inv-search" class="sr-only">Search</label>
                <input id="inv-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Customer, invoice number, customer’s order…" class="input max-w-xs">

                <label for="inv-branch" class="sr-only">Branch</label>
                <select id="inv-branch" wire:model.live="branch" class="input w-44">
                    <option value="">All branches</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Invoice</th><th>Customer</th><th>Order</th><th>Status</th><th class="text-right">Total (₹)</th><th>Date</th><th>Due</th></tr></thead>
                <tbody>
                    @forelse ($invoices as $inv)
                        <tr wire:key="inv-{{ $inv->id }}">
                            <td>
                                <a href="{{ route('sales.invoices.show', $inv) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $inv->invoice_number }}</a>
                                <div class="text-xs text-slate-500">{{ $inv->location?->name }}</div>
                            </td>
                            <td class="font-medium text-slate-900">{{ $inv->party_name }}</td>
                            <td class="font-mono text-xs text-slate-600">{{ $inv->order?->so_number }}</td>
                            <td><span @class(['badge', 'bg-emerald-100 text-emerald-800' => $inv->status === 'issued', 'bg-rose-100 text-rose-800' => $inv->status !== 'issued'])>{{ ucfirst($inv->status) }}</span></td>
                            <td class="text-right tabular-nums">{{ Format::number($inv->grand_total, 2) }}</td>
                            <td class="whitespace-nowrap tabular-nums text-slate-600">{{ $inv->invoice_date->format('j M Y') }}</td>
                            <td class="whitespace-nowrap"><span @class(['tabular-nums', 'font-medium text-rose-700' => $inv->status === 'issued' && $inv->due_date->lt($today), 'text-slate-700' => ! ($inv->status === 'issued' && $inv->due_date->lt($today))])>{{ $inv->due_date->format('j M Y') }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-slate-500">No invoices match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($invoices->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $invoices->links() }}</div>
        @endif
    </div>
</div>

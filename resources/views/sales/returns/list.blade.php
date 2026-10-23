@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-5">
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">Returns and credit notes</h1>
        <p class="mt-1 text-sm text-slate-500">Goods that come back, or money credited to a customer, against an issued invoice. Once approved they reduce net revenue. Start one from the invoice.</p>
    </div>

    <x-status />

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="ret-search" class="sr-only">Search</label>
                <input id="ret-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Customer or number…" class="input max-w-xs">

                <label for="ret-type" class="sr-only">Kind</label>
                <select id="ret-type" wire:model.live="type" class="input w-44">
                    <option value="">Returns and credit notes</option>
                    <option value="return">Returns of goods</option>
                    <option value="credit_note">Credit notes</option>
                </select>

                <label for="ret-approval" class="sr-only">Approval</label>
                <select id="ret-approval" wire:model.live="approval" class="input w-44">
                    <option value="">Any approval state</option>
                    <option value="draft">Draft</option>
                    <option value="pending">Waiting for approval</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Number</th><th>Kind</th><th>Customer</th><th>Invoice</th><th>Approval</th><th class="text-right">Taxable (₹)</th><th class="text-right">Total (₹)</th><th>Date</th></tr></thead>
                <tbody>
                    @forelse ($returns as $r)
                        <tr wire:key="ret-{{ $r->id }}">
                            <td><a href="{{ route('sales.returns.show', $r) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $r->return_number }}</a></td>
                            <td>{{ $r->typeLabel() }}</td>
                            <td class="font-medium text-slate-900">{{ $r->party_name }}</td>
                            <td class="font-mono text-xs">{{ $r->invoice?->invoice_number }}</td>
                            <td><span class="badge {{ $r->approval_status->badge() }}">{{ $r->approval_status->label() }}</span></td>
                            <td class="text-right tabular-nums">{{ Format::number($r->taxable_total, 2) }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($r->grand_total, 2) }}</td>
                            <td class="tabular-nums text-slate-600">{{ $r->return_date->format('j M Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-10 text-center text-slate-500">Nothing here yet. Open an invoice and choose “Return or credit”.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($returns->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $returns->links() }}</div>
        @endif
    </div>
</div>

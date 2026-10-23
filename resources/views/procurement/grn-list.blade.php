<div class="space-y-5">
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">Goods receipts</h1>
        <p class="mt-1 text-sm text-slate-500">What arrived from suppliers. Start a receipt from its approved purchase order (“Receive goods”). The accepted goods go into stock when the receipt is approved.</p>
    </div>

    <x-status />

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="gr-search" class="sr-only">Search</label>
                <input id="gr-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Number, supplier, supplier’s invoice…" class="input max-w-xs">
                <label for="gr-appr" class="sr-only">Approval</label>
                <select id="gr-appr" wire:model.live="approval" class="input w-48">
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
                <thead><tr><th>Receipt</th><th>Supplier</th><th>Order</th><th>Branch</th><th>Approval</th><th>Stock</th><th>Date</th></tr></thead>
                <tbody>
                    @forelse ($grns as $g)
                        <tr wire:key="gr-{{ $g->id }}">
                            <td><a href="{{ route('procurement.grns.show', $g) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $g->grn_number }}</a></td>
                            <td class="font-medium text-slate-900">{{ $g->supplier_name }}</td>
                            <td class="font-mono text-xs">{{ $g->purchaseOrder?->po_number }}</td>
                            <td>{{ $g->location?->name }}</td>
                            <td><span class="badge {{ $g->approval_status->badge() }}">{{ $g->approval_status->label() }}</span></td>
                            <td>{{ $g->posted_at ? 'In stock' : '—' }}</td>
                            <td class="tabular-nums text-slate-600">{{ $g->grn_date->format('d-m-Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-slate-500">No goods receipts yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($grns->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $grns->links() }}</div>@endif
    </div>
</div>

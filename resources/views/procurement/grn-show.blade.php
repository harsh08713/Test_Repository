@php use App\Modules\Shared\Support\Format; $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('procurement.grns.index') }}" wire:navigate class="hover:text-brand-700">Goods receipts</a> › {{ $grn->location?->name }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $grn->supplier_name }} <span class="font-mono text-base font-normal text-slate-500">{{ $grn->grn_number }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span class="badge {{ $grn->approval_status->badge() }}">{{ $grn->approval_status->label() }}</span>
                @if ($grn->posted_at)<span class="badge bg-emerald-100 text-emerald-800">In stock since {{ $grn->posted_at->format('d-m-Y H:i') }}</span>@endif
                <span class="tabular-nums text-slate-600">Received {{ $grn->grn_date->format('d-m-Y') }}</span>
                <span class="text-slate-600">Against
                    @if ($canViewOrder)<a href="{{ route('procurement.orders.show', $grn->purchaseOrder) }}" wire:navigate class="font-mono text-xs text-brand-700 hover:underline">{{ $grn->purchaseOrder?->po_number }}</a>@else<span class="font-mono text-xs">{{ $grn->purchaseOrder?->po_number }}</span>@endif</span>
                @if ($grn->supplier_invoice_no)<span class="text-slate-600">Supplier’s invoice {{ $grn->supplier_invoice_no }}</span>@endif
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('inspection.create')@if ($grn->posted_at)<a href="{{ route('quality.inspections.create', ['grn' => $grn->id]) }}" wire:navigate class="btn-secondary">Inspect the goods</a>@endif @endcan
            @if ($canEdit)<a href="{{ route('procurement.grns.edit', $grn) }}" wire:navigate class="btn-primary">Edit</a>@endif
            @if ($canPost)<button type="button" wire:click="postNow" class="btn-secondary">Put into stock now</button>@endif
        </div>
    </div>

    <x-status />
    @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @if ($grn->isApproved() && $grn->posted_at === null && $grn->post_error)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Approved, but the stock could not be posted yet: {{ $grn->post_error }}. It is retried automatically.</div>
    @endif

    <section class="card" aria-labelledby="s-lines">
        <div class="card-header"><h2 id="s-lines" class="text-sm font-semibold text-slate-900">Lines</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>#</th><th>Item</th><th class="text-right">Accepted</th><th class="text-right">Rejected</th><th>Batch</th><th>Expires</th><th class="text-right">Cost per base unit (₹)</th></tr></thead>
                <tbody>
                    @foreach ($grn->items as $line)
                        <tr wire:key="gi-{{ $line->id }}">
                            <td class="tabular-nums text-slate-500">{{ $line->line_no }}</td>
                            <td class="font-medium text-slate-900">{{ $line->item_name }}</td>
                            <td class="text-right tabular-nums">{{ $qty($line->accepted_quantity) }} {{ $line->unit?->code }}</td>
                            <td class="text-right tabular-nums">{{ $qty($line->rejected_quantity) }}@if ($line->reject_reason)<span class="block text-xs text-slate-500">{{ $line->reject_reason }}</span>@endif @if ($canReportLoss && $grn->isApproved() && bccomp((string) $line->rejected_quantity, '0', 3) > 0)<a href="{{ route('loss.incidents.create', ['source' => 'grn_item', 'id' => $line->id]) }}" wire:navigate class="block text-xs text-brand-700 hover:underline">Report as loss</a>@endif</td>
                            <td class="font-mono text-xs">{{ $line->batch_no }}</td>
                            <td class="tabular-nums">{{ $line->expiry_date?->format('d-m-Y') }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($line->unit_cost, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <livewire:shared.approval-panel type="grn" :id="$grn->id" :key="'ap-'.$grn->id" />

    @if ($history->isNotEmpty())
        <section class="card" aria-labelledby="s-hist">
            <div class="card-header"><h2 id="s-hist" class="text-sm font-semibold text-slate-900">Audit trail</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($history as $entry)
                    <li class="flex justify-between gap-3 px-5 py-2" wire:key="h-{{ $entry->id }}"><span>{{ str_replace('_', ' ', ucfirst((string) ($entry->event ?? $entry->description))) }} · {{ $entry->causer?->name ?? 'System' }}</span><span class="tabular-nums text-xs text-slate-500">{{ $entry->created_at?->format('d-m-Y H:i') }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif
</div>

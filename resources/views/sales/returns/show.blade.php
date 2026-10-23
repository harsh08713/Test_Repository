@php
    use App\Modules\Shared\Support\Format;

    $inter = $return->supply_type === 'inter';
    $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
@endphp

<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('sales.returns.index') }}" wire:navigate class="hover:text-brand-700">Returns and credit notes</a> › {{ $return->location?->name }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $return->party_name }} <span class="font-mono text-base font-normal text-slate-500">{{ $return->return_number }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span class="badge bg-slate-100 text-slate-700">{{ $return->typeLabel() }}</span>
                <span class="badge {{ $return->approval_status->badge() }}">{{ $return->approval_status->label() }}</span>
                <span class="tabular-nums text-slate-600">Dated {{ $return->return_date->format('j M Y') }}</span>
                <span class="text-slate-600">Against invoice
                    @if ($canViewInvoice)<a href="{{ route('sales.invoices.show', $invoice) }}" wire:navigate class="font-mono text-xs text-brand-700 hover:underline">{{ $invoice->invoice_number }}</a>@else<span class="font-mono text-xs">{{ $invoice->invoice_number }}</span>@endif</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canEdit)<a href="{{ route('sales.returns.edit', $return) }}" wire:navigate class="btn-primary">Edit</a>@endif
            @if ($canReportLoss && $return->isApproved())<a href="{{ route('loss.incidents.create', ['source' => 'sales_return', 'id' => $return->id]) }}" wire:navigate class="btn-secondary">Report as loss</a>@endif
        </div>
    </div>

    <x-status />

    @if ($return->isPendingApproval())
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">This is waiting for approval, so it is locked.</div>
    @endif

    <section class="card" aria-labelledby="s-value">
        <div class="card-header"><h2 id="s-value" class="text-sm font-semibold text-slate-900">Value</h2></div>
        <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-4">
            <div><dt class="text-xs text-slate-500">Taxable value</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">₹ {{ Format::number($return->taxable_total, 2) }}</dd></div>
            <div><dt class="text-xs text-slate-500">{{ $inter ? 'IGST' : 'CGST + SGST' }}</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">₹ {{ Format::number($inter ? $return->igst_total : bcadd((string) $return->cgst_total, (string) $return->sgst_total, 2), 2) }}</dd></div>
            <div><dt class="text-xs text-slate-500">Credited to the customer</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">₹ {{ Format::number($return->grand_total, 2) }}</dd></div>
            <div><dt class="text-xs text-slate-500">Cost of what came back</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">{{ $return->cost_total === null ? 'Not known' : '₹ '.Format::number($return->cost_total, 2) }}</dd></div>
        </dl>
        <p class="border-t border-slate-100 px-5 py-3 text-sm text-slate-700"><span class="text-xs text-slate-500">Reason</span><br>{{ $return->reason }}</p>
    </section>

    <section class="card" aria-labelledby="s-lines">
        <div class="card-header"><h2 id="s-lines" class="text-sm font-semibold text-slate-900">Lines</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>#</th><th>Item</th><th class="text-right">Quantity</th><th class="text-right">Rate (₹)</th><th class="text-right">Taxable (₹)</th><th class="text-right">GST %</th><th class="text-right">Total (₹)</th></tr></thead>
                <tbody>
                    @foreach ($return->items as $line)
                        <tr wire:key="rli-{{ $line->id }}">
                            <td class="tabular-nums text-slate-500">{{ $line->line_no }}</td>
                            <td class="font-medium text-slate-900">{{ $line->item_name }}</td>
                            <td class="text-right tabular-nums">{{ $qty($line->quantity) }} {{ $line->unit?->code }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($line->rate, 2) }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($line->taxable_amount, 2) }}</td>
                            <td class="text-right tabular-nums">{{ $qty($line->gst_rate) }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($line->line_total, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <livewire:shared.approval-panel type="sales_return" :id="$return->id" :key="'ap-'.$return->id" />

    @if ($history->isNotEmpty())
        <section class="card" aria-labelledby="s-hist">
            <div class="card-header"><h2 id="s-hist" class="text-sm font-semibold text-slate-900">History</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($history as $entry)
                    <li class="flex justify-between gap-3 px-5 py-2"><span>{{ $entry->event ?? $entry->description }} · {{ $entry->causer?->name }}</span><span class="tabular-nums text-xs text-slate-500">{{ $entry->created_at->format('j M Y H:i') }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif
</div>

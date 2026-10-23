@php
    use App\Modules\Shared\Support\Format;

    $inter = $invoice->isInterState();
    $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
    $rate = function ($v) {
        $t = rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
        $d = str_contains($t, '.') ? strlen(substr($t, strpos($t, '.') + 1)) : 0;

        return Format::number($t === '' ? '0' : $t, max(2, $d));
    };
@endphp

<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('sales.invoices.index') }}" wire:navigate class="hover:text-brand-700">Sales invoices</a> › {{ $invoice->location?->name }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $invoice->party_name }} <span class="font-mono text-base font-normal text-slate-500">{{ $invoice->invoice_number }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span @class(['badge', 'bg-emerald-100 text-emerald-800' => $invoice->status === 'issued', 'bg-rose-100 text-rose-800' => $invoice->status !== 'issued'])>{{ ucfirst($invoice->status) }}</span>
                <span class="tabular-nums text-slate-600">Dated {{ $invoice->invoice_date->format('j M Y') }} · due {{ $invoice->due_date->format('j M Y') }}</span>
                <span class="text-slate-600">Credited to {{ $invoice->owner?->name }}</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canDownload)<a href="{{ route('sales.invoices.pdf', $invoice) }}" class="btn-secondary">Download PDF</a>@endif
            @if ($canViewOrder)<a href="{{ route('sales.orders.show', $invoice->order) }}" wire:navigate class="btn-secondary">View the order</a>@endif
            @if ($canReturn)<a href="{{ route('sales.returns.make', $invoice) }}" wire:navigate class="btn-secondary">Return or credit…</a>@endif
        </div>
    </div>

    @if ($invoice->isCancelled())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">Cancelled{{ $invoice->cancelled_at ? ' on '.$invoice->cancelled_at->format('j M Y') : '' }}@if ($invoice->cancel_reason): {{ $invoice->cancel_reason }}@endif</div>
    @endif

    <section class="card" aria-labelledby="s-value">
        <div class="card-header"><h2 id="s-value" class="text-sm font-semibold text-slate-900">Value</h2></div>
        <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-4">
            <div><dt class="text-xs text-slate-500">Taxable value</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">₹ {{ Format::number($invoice->taxable_total, 2) }}</dd></div>
            <div><dt class="text-xs text-slate-500">{{ $inter ? 'IGST' : 'CGST + SGST' }}</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">₹ {{ Format::number($inter ? $invoice->igst_total : bcadd((string) $invoice->cgst_total, (string) $invoice->sgst_total, 2), 2) }}</dd></div>
            <div><dt class="text-xs text-slate-500">Total</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">₹ {{ Format::number($invoice->grand_total, 2) }}</dd></div>
            <div><dt class="text-xs text-slate-500">Supply</dt><dd class="text-sm text-slate-900">{{ $inter ? 'Inter-state' : 'Within the state' }} · {{ $invoice->placeOfSupply?->name }}</dd></div>
        </dl>
    </section>

    <section class="card" aria-labelledby="s-details">
        <div class="card-header"><h2 id="s-details" class="text-sm font-semibold text-slate-900">Details</h2></div>
        <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-3">
            <div><dt class="text-xs text-slate-500">Customer</dt><dd class="text-sm text-slate-900">@if ($canViewCustomer)<a href="{{ route('masters.ledgers.show', $invoice->customer) }}" wire:navigate class="text-brand-700 hover:underline">{{ $invoice->party_name }}</a>@else{{ $invoice->party_name }}@endif</dd></div>
            @if ($invoice->customer_gstin)<div><dt class="text-xs text-slate-500">Customer GSTIN</dt><dd class="font-mono text-sm text-slate-900">{{ $invoice->customer_gstin }}</dd></div>@endif
            <div><dt class="text-xs text-slate-500">Sales order</dt><dd class="text-sm text-slate-900">@if ($canViewOrder)<a href="{{ route('sales.orders.show', $invoice->order) }}" wire:navigate class="font-mono text-xs text-brand-700 hover:underline">{{ $invoice->order?->so_number }}</a>@else<span class="font-mono text-xs">{{ $invoice->order?->so_number }}</span>@endif</dd></div>
            <div class="sm:col-span-3"><dt class="text-xs text-slate-500">Billing address</dt><dd class="text-sm text-slate-900">{{ implode(', ', $invoice->billingLines()) }}</dd></div>
            <div class="sm:col-span-3"><dt class="text-xs text-slate-500">Shipping address</dt><dd class="text-sm text-slate-900">{{ implode(', ', $invoice->shippingLines()) }}</dd></div>
            @if ($invoice->customer_po_number)<div><dt class="text-xs text-slate-500">Customer’s order</dt><dd class="text-sm text-slate-900">{{ $invoice->customer_po_number }}@if ($invoice->customer_po_date) · {{ $invoice->customer_po_date->format('j M Y') }}@endif</dd></div>@endif
            @if ($invoice->terms)<div class="sm:col-span-3"><dt class="text-xs text-slate-500">Terms and conditions</dt><dd class="whitespace-pre-line text-sm text-slate-900">{{ $invoice->terms }}</dd></div>@endif
        </dl>
    </section>

    <section class="card" aria-labelledby="s-items">
        <div class="card-header"><h2 id="s-items" class="text-sm font-semibold text-slate-900">Item lines</h2><span class="text-xs text-slate-500">{{ $invoice->items->count() }} {{ $invoice->items->count() === 1 ? 'line' : 'lines' }}</span></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>#</th><th>Item</th><th class="text-right">Quantity</th><th class="text-right">Rate (₹)</th><th class="text-right">Disc %</th><th class="text-right">Taxable (₹)</th><th class="text-right">GST %</th><th class="text-right">Tax (₹)</th><th class="text-right">Total (₹)</th></tr></thead>
                <tbody>
                    @foreach ($invoice->items as $line)
                        <tr wire:key="ln-{{ $line->id }}">
                            <td class="tabular-nums text-slate-500">{{ $line->line_no }}</td>
                            <td><span class="font-medium text-slate-900">{{ $line->item_name }}</span>@if ($line->hsn_code)<span class="ml-1 text-xs text-slate-500">HSN {{ $line->hsn_code }}</span>@endif</td>
                            <td class="text-right tabular-nums">{{ $qty($line->quantity) }} <span class="text-slate-500">{{ $line->unit?->code }}</span></td>
                            <td class="text-right tabular-nums">{{ $rate($line->rate) }}</td>
                            <td class="text-right tabular-nums">{{ bccomp((string) $line->discount_percent, '0', 2) === 0 ? '–' : $qty($line->discount_percent) }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($line->taxable_amount, 2) }}</td>
                            <td class="text-right tabular-nums">{{ $qty($line->gst_rate) ?: '0' }}</td>
                            <td class="text-right tabular-nums">{{ Format::number(bcadd(bcadd((string) $line->cgst_amount, (string) $line->sgst_amount, 2), (string) $line->igst_amount, 2), 2) }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($line->line_total, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><td colspan="8" class="text-right text-sm text-slate-600">Total before discount</td><td class="text-right tabular-nums">{{ Format::number($invoice->gross_total, 2) }}</td></tr>
                    @if (bccomp((string) $invoice->discount_total, '0', 2) > 0)<tr><td colspan="8" class="text-right text-sm text-slate-600">Discount</td><td class="text-right tabular-nums">– {{ Format::number($invoice->discount_total, 2) }}</td></tr>@endif
                    @if ($inter)
                        <tr><td colspan="8" class="text-right text-sm text-slate-600">IGST</td><td class="text-right tabular-nums">{{ Format::number($invoice->igst_total, 2) }}</td></tr>
                    @else
                        <tr><td colspan="8" class="text-right text-sm text-slate-600">CGST</td><td class="text-right tabular-nums">{{ Format::number($invoice->cgst_total, 2) }}</td></tr>
                        <tr><td colspan="8" class="text-right text-sm text-slate-600">SGST</td><td class="text-right tabular-nums">{{ Format::number($invoice->sgst_total, 2) }}</td></tr>
                    @endif
                    @if (bccomp((string) $invoice->round_off, '0', 2) !== 0)<tr><td colspan="8" class="text-right text-sm text-slate-600">Round off</td><td class="text-right tabular-nums">{{ $invoice->round_off }}</td></tr>@endif
                    <tr><td colspan="8" class="text-right text-sm font-medium text-slate-700">Total</td><td class="text-right font-semibold tabular-nums text-slate-900">{{ Format::number($invoice->grand_total, 2) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </section>

    @if ($returns->isNotEmpty())
        <section class="card" aria-labelledby="s-returns">
            <div class="card-header"><h2 id="s-returns" class="text-sm font-semibold text-slate-900">Returns and credit notes</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($returns as $r)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-2.5" wire:key="rr-{{ $r->id }}">
                        <span><a href="{{ route('sales.returns.show', $r) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $r->return_number }}</a>
                            · {{ $r->typeLabel() }} · <span class="badge {{ $r->approval_status->badge() }}">{{ $r->approval_status->label() }}</span></span>
                        <span class="tabular-nums">₹ {{ Format::number($r->grand_total, 2) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <livewire:shared.comments type="sales_invoice" :id="$invoice->id" :key="'cm-'.$invoice->id" />

    @if ($history->isNotEmpty())
        <section class="card" aria-labelledby="s-history">
            <div class="card-header"><h2 id="s-history" class="text-sm font-semibold text-slate-900">Audit trail</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($history as $entry)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-2.5" wire:key="h-{{ $entry->id }}">
                        <span class="font-medium text-slate-900">{{ str_replace('_', ' ', ucfirst((string) ($entry->event ?? $entry->description))) }}</span>
                        <span class="text-xs text-slate-500">{{ $entry->causer?->name ?? 'System' }} · {{ $entry->created_at?->format(config('formezy.display.datetime')) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>

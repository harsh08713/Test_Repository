@php use App\Modules\Shared\Support\Format; $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('procurement.orders.index') }}" wire:navigate class="hover:text-brand-700">Purchase orders</a> › {{ $order->location?->name }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $order->supplier_name }} <span class="font-mono text-base font-normal text-slate-500">{{ $order->po_number }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                @if ($order->isCancelled())<span class="badge bg-rose-100 text-rose-800">Cancelled</span>@endif
                <span class="badge {{ $order->approval_status->badge() }}">{{ $order->approval_status->label() }}</span>
                <span class="tabular-nums text-slate-600">Dated {{ $order->po_date->format('d-m-Y') }} · expected {{ $order->expected_date->format('d-m-Y') }}</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canReceive)<a href="{{ route('procurement.grns.create', $order) }}" wire:navigate class="btn-primary">Receive goods</a>@endif
            @if ($canEdit)<a href="{{ route('procurement.orders.edit', $order) }}" wire:navigate class="btn-secondary">Edit</a>@endif
            @if ($canCancel)<button type="button" wire:click="openCancel" class="btn-ghost text-rose-600">Cancel the order…</button>@endif
        </div>
    </div>

    <x-status />
    @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if ($order->isCancelled())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">Cancelled{{ $order->cancelled_at ? ' on '.$order->cancelled_at->format('d-m-Y') : '' }}: {{ $order->cancel_reason }}</div>
    @endif

    <section class="card" aria-labelledby="s-lines">
        <div class="card-header"><h2 id="s-lines" class="text-sm font-semibold text-slate-900">Lines</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>#</th><th>Item</th><th class="text-right">Ordered</th><th class="text-right">Rate (₹)</th><th class="text-right">Amount (₹)</th><th class="text-right">Received</th><th class="text-right">Still allowed</th></tr></thead>
                <tbody>
                    @foreach ($order->items as $line)
                        <tr wire:key="pl-{{ $line->id }}">
                            <td class="tabular-nums text-slate-500">{{ $line->line_no }}</td>
                            <td class="font-medium text-slate-900">{{ $line->item_name }}</td>
                            <td class="text-right tabular-nums">{{ $qty($line->quantity) }} {{ $line->unit?->code }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($line->rate, 2) }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($line->amount, 2) }}</td>
                            <td class="text-right tabular-nums">{{ $qty($received[$line->id] ?? 0) }} <span class="text-xs text-slate-500">base</span></td>
                            <td class="text-right tabular-nums">{{ $qty($remaining[$line->id] ?? 0) }} <span class="text-xs text-slate-500">incl. {{ $qty($line->tolerance_percent) }}%</span></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr><td colspan="4" class="text-right text-sm text-slate-600">Total before tax</td><td class="text-right font-semibold tabular-nums">{{ Format::number($order->total_amount, 2) }}</td><td colspan="2"></td></tr></tfoot>
            </table>
        </div>
        @if ($order->terms)<p class="border-t border-slate-100 px-5 py-3 text-sm text-slate-700"><span class="text-xs text-slate-500">Terms</span><br>{{ $order->terms }}</p>@endif
    </section>

    @if ($grns->isNotEmpty())
        <section class="card" aria-labelledby="s-grns">
            <div class="card-header"><h2 id="s-grns" class="text-sm font-semibold text-slate-900">Goods receipts</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($grns as $g)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-2.5" wire:key="g-{{ $g->id }}">
                        <span><a href="{{ route('procurement.grns.show', $g) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $g->grn_number }}</a>
                            · <span class="badge {{ $g->approval_status->badge() }}">{{ $g->approval_status->label() }}</span>@if ($g->posted_at) · <span class="text-xs text-emerald-700">in stock</span>@endif</span>
                        <span class="tabular-nums text-slate-600">{{ $g->grn_date->format('d-m-Y') }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <livewire:shared.approval-panel type="purchase_order" :id="$order->id" :key="'ap-'.$order->id" />

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

    @if ($cancelling)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="c-title">
            <form wire:submit="cancelOrder" class="card w-full max-w-lg" novalidate>
                <div class="card-header"><h2 id="c-title" class="text-sm font-semibold text-slate-900">Cancel purchase order {{ $order->po_number }}</h2></div>
                <div class="card-body">
                    <label for="c-reason" class="label">Why?</label>
                    <textarea id="c-reason" rows="3" wire:model="reason" class="input" maxlength="500"></textarea>
                    @error('reason')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeCancel" class="btn-secondary">Keep the order</button>
                    <button type="submit" class="btn-primary">Cancel it</button>
                </div>
            </form>
        </div>
    @endif
</div>

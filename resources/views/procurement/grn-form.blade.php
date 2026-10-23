@php $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
<div class="mx-auto max-w-5xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('procurement.orders.show', $po) }}" wire:navigate class="hover:text-brand-700">Purchase order {{ $po->po_number }}</a></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $editing ? 'Change' : 'New' }} goods receipt <span class="text-base font-normal text-slate-500">· {{ $po->supplier_name }} → {{ $po->location?->name }}</span></h1>
        <p class="mt-1 text-sm text-slate-500">Enter what arrived on each line, accepted and rejected. Nothing reaches stock until the receipt is approved. A line may not go over what was ordered plus the item’s PO tolerance.</p>
    </div>

    @error('form')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('lines')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <form wire:submit="save" class="space-y-5" novalidate>
        <section class="card" aria-labelledby="g-head">
            <div class="card-header"><h2 id="g-head" class="text-sm font-semibold text-slate-900">Receipt</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="g-date" class="label">Date received</label>
                    <input id="g-date" type="date" wire:model="grnDate" max="{{ now()->toDateString() }}" class="input">
                    @error('grn_date')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="g-inv" class="label">Supplier’s invoice / challan no.</label>
                    <input id="g-inv" type="text" wire:model="supplierInvoiceNo" class="input" maxlength="60">
                    @error('supplier_invoice_no')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="g-rem" class="label">Remarks</label>
                    <input id="g-rem" type="text" wire:model="remarks" class="input" maxlength="1000">
                </div>
            </div>
        </section>

        @foreach ($po->items as $line)
            @php $item = $items[$line->item_id] ?? null; $batch = $item?->tracking === 'batch'; @endphp
            <section class="card" wire:key="gl-{{ $line->id }}" aria-label="{{ $line->item_name }}">
                <div class="card-header flex-wrap">
                    <h2 class="text-sm font-semibold text-slate-900">{{ $line->line_no }}. {{ $line->item_name }}</h2>
                    <span class="text-xs text-slate-500">Ordered {{ $qty($line->quantity) }} {{ $line->unit?->code }} · may still receive {{ $qty($remaining[$line->id] ?? 0) }} in base units</span>
                </div>
                <div class="card-body grid gap-3 sm:grid-cols-4">
                    <div>
                        <label for="g{{ $line->id }}-a" class="label">Accepted ({{ $line->unit?->code }})</label>
                        <input id="g{{ $line->id }}-a" type="text" inputmode="decimal" wire:model="lines.{{ $line->id }}.accepted" class="input tabular-nums" autocomplete="off">
                        @error("lines.{$line->id}.accepted")<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="g{{ $line->id }}-r" class="label">Rejected ({{ $line->unit?->code }})</label>
                        <input id="g{{ $line->id }}-r" type="text" inputmode="decimal" wire:model="lines.{{ $line->id }}.rejected" class="input tabular-nums" autocomplete="off">
                        @error("lines.{$line->id}.rejected")<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="g{{ $line->id }}-rr" class="label">Why rejected</label>
                        <input id="g{{ $line->id }}-rr" type="text" wire:model="lines.{{ $line->id }}.reject_reason" class="input" maxlength="500">
                        @error("lines.{$line->id}.reject_reason")<p class="error">{{ $message }}</p>@enderror
                    </div>
                    @if ($batch)
                        <div>
                            <label for="g{{ $line->id }}-b" class="label">Batch no.</label>
                            <input id="g{{ $line->id }}-b" type="text" wire:model="lines.{{ $line->id }}.batch_no" class="input font-mono" maxlength="60">
                            @error("lines.{$line->id}.batch_no")<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="g{{ $line->id }}-l" class="label">Lot no.</label>
                            <input id="g{{ $line->id }}-l" type="text" wire:model="lines.{{ $line->id }}.lot_no" class="input font-mono" maxlength="60">
                        </div>
                        <div>
                            <label for="g{{ $line->id }}-m" class="label">Made on</label>
                            <input id="g{{ $line->id }}-m" type="date" wire:model="lines.{{ $line->id }}.mfg_date" class="input">
                            @error("lines.{$line->id}.mfg_date")<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="g{{ $line->id }}-e" class="label">Expires on{{ $item?->has_expiry ? '' : ' (if any)' }}</label>
                            <input id="g{{ $line->id }}-e" type="date" wire:model="lines.{{ $line->id }}.expiry_date" class="input">
                            @error("lines.{$line->id}.expiry_date")<p class="error">{{ $message }}</p>@enderror
                        </div>
                    @endif
                    @error("lines.{$line->id}")<p class="error sm:col-span-4">{{ $message }}</p>@enderror
                </div>
            </section>
        @endforeach

        <div class="flex justify-end gap-3">
            <a href="{{ route('procurement.orders.show', $po) }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save as draft</button>
        </div>
    </form>
</div>

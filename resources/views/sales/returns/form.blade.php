@php
    use App\Modules\Shared\Support\Format;

    $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
    $totals = $preview['totals'];
@endphp

<div class="mx-auto max-w-5xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('sales.invoices.show', $invoice) }}" wire:navigate class="hover:text-brand-700">Invoice {{ $invoice->invoice_number }}</a></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $editing ? 'Change' : 'New' }} return or credit note <span class="text-base font-normal text-slate-500">· {{ $invoice->party_name }}</span></h1>
        <p class="mt-1 text-sm text-slate-500">Choose how much of each line comes back. Prices, discount and GST are the invoice’s own: they cannot be changed here.</p>
    </div>

    @error('form')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <form wire:submit="save" class="space-y-5" novalidate>
        <section class="card" aria-labelledby="s-head">
            <div class="card-header"><h2 id="s-head" class="text-sm font-semibold text-slate-900">What is this?</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="r-type" class="label">Kind</label>
                    <select id="r-type" wire:model="returnType" class="input">
                        <option value="return">Return of goods</option>
                        <option value="credit_note">Credit note (goods stay)</option>
                    </select>
                    @error('return_type')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="r-date" class="label">Date</label>
                    <input id="r-date" type="date" wire:model="returnDate" min="{{ $invoice->invoice_date->toDateString() }}" max="{{ $today }}" class="input">
                    @error('return_date')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="r-reason" class="label">Reason</label>
                    <textarea id="r-reason" rows="2" wire:model="reason" class="input" maxlength="1000"></textarea>
                    @error('reason')<p class="error">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="s-lines">
            <div class="card-header"><h2 id="s-lines" class="text-sm font-semibold text-slate-900">Lines</h2></div>
            @error('lines')<p class="error px-5 pt-3">{{ $message }}</p>@enderror
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Item</th><th class="text-right">Invoiced</th><th class="text-right">Can still return</th><th class="w-40">Return</th><th class="text-right">Credit (₹)</th></tr></thead>
                    <tbody>
                        @foreach ($lines as $line)
                            @php $priced = collect($preview['lines'])->first(fn ($l) => $l['invoice_line']->id === $line->id); @endphp
                            <tr wire:key="rl-{{ $line->id }}">
                                <td class="font-medium text-slate-900">{{ $line->item_name }}</td>
                                <td class="text-right tabular-nums">{{ $qty($line->quantity) }} {{ $line->unit?->code }}</td>
                                <td class="text-right tabular-nums">{{ $qty($left[$line->id] ?? '0') }}</td>
                                <td>
                                    <label class="sr-only" for="q-{{ $line->id }}">Quantity of {{ $line->item_name }} to return</label>
                                    <input id="q-{{ $line->id }}" type="text" inputmode="decimal" wire:model.live.debounce.600ms="quantities.{{ $line->id }}" class="input" autocomplete="off">
                                    @error('lines.'.$line->id)<p class="error">{{ $message }}</p>@enderror
                                    @if (isset($preview['errors']['lines.'.$line->id]))<p class="error">{{ $preview['errors']['lines.'.$line->id] }}</p>@endif
                                </td>
                                <td class="text-right tabular-nums">{{ $priced ? Format::number($priced['figures']['total'], 2) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if ($totals)
            <dl class="grid gap-3 sm:grid-cols-4">
                <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Taxable value</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($totals['taxable'], 2) }}</dd></div>
                <div class="card px-5 py-3"><dt class="text-xs text-slate-500">GST</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($totals['tax'], 2) }}</dd></div>
                <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Round-off</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ $totals['round_off'] }}</dd></div>
                <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Credited to the customer</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($totals['grand_total'], 2) }}</dd></div>
            </dl>
        @endif

        <div class="flex justify-end gap-3">
            <a href="{{ $editing ? url()->previous() : route('sales.invoices.show', $invoice) }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save as draft</button>
        </div>
    </form>
</div>

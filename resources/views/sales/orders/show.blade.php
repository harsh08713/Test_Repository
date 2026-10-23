@php
    use App\Modules\Sales\Enums\CreditResult;
    use App\Modules\Sales\Enums\SalesOrderState;
    use App\Modules\Shared\Support\Format;

    $inter = $order->isInterState();
    $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
    $rate = function ($v) {
        $t = rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
        $d = str_contains($t, '.') ? strlen(substr($t, strpos($t, '.') + 1)) : 0;

        return Format::number($t === '' ? '0' : $t, max(2, $d));
    };
    $source = match ($order->source_type) { 'quotation' => 'a quotation', 'deal' => 'a deal', default => 'a direct order' };
@endphp

<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('sales.orders.index') }}" wire:navigate class="hover:text-brand-700">Sales orders</a> › {{ $order->location?->name }} @can('task.create')· <a href="{{ route('workspace.tasks.create', ['type' => 'sales_order', 'id' => $order->id]) }}" wire:navigate class="hover:text-brand-700">Add a task</a>@endcan @if ($order->customer_ledger_id) · <a href="{{ route('crm.customers.show', $order->customer_ledger_id) }}" wire:navigate class="hover:text-brand-700">Customer 360</a>@endif</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $order->party_name }} <span class="font-mono text-base font-normal text-slate-500">{{ $order->so_number }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span class="badge {{ $order->state->badge() }}">{{ $order->state->label() }}</span>
                <span class="badge {{ $order->approval_status->badge() }}">{{ $order->approval_status->label() }}</span>
                @if (! in_array($order->credit_result, [CreditResult::NotChecked, CreditResult::Ignored, CreditResult::NoLimit], true))
                    <span class="badge {{ $order->credit_result->badge() }}">{{ $order->credit_result->label() }}</span>
                @endif
                <span class="tabular-nums text-slate-600">Delivery by {{ $order->delivery_date->format('j M Y') }}</span>
                <span class="text-slate-600">Credited to {{ $order->owner?->name }}</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canDownload)<a href="{{ route('sales.orders.pdf', $order) }}" class="btn-secondary">Order acknowledgment (PDF)</a>@endif
            @if ($canViewInvoice)<a href="{{ route('sales.invoices.show', $order->invoice) }}" wire:navigate class="btn-primary">View the invoice</a>@endif
            @if ($canMakeInvoice)<button type="button" wire:click="makeInvoiceNow" class="btn-primary">Make the invoice now</button>@endif
            @if ($canCredit)<button type="button" wire:click="openDialog('credit')" class="btn-secondary">Allow over the credit limit…</button>@endif
            @if ($canReopen)<button type="button" wire:click="openDialog('reopen-approval')" class="btn-secondary">Re-open for editing…</button>@endif
            @if ($canCancel)<button type="button" wire:click="openDialog('cancel')" class="btn-secondary">Cancel the order…</button>@endif
            @if ($canEdit)<a href="{{ route('sales.orders.edit', $order) }}" wire:navigate class="btn-secondary">Edit</a>@endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif
    @if ($dialog === '')
        @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @endif

    @if ($order->isCancelled())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">Cancelled{{ $order->cancelled_at ? ' on '.$order->cancelled_at->format('j M Y') : '' }}@if ($order->cancel_reason): “{{ $order->cancel_reason }}”@endif</div>
    @elseif ($order->isApproved() && $order->state === SalesOrderState::Open)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            This order is approved but its invoice has not been made yet.
            @if ($order->invoice_error)<span class="block text-xs">The last attempt failed: {{ $order->invoice_error }}. It is tried again automatically.</span>@endif
        </div>
    @elseif ($order->isPendingApproval())
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">This order is waiting for approval, so it is locked. Approving it makes its invoice.</div>
    @endif

    <section class="card" aria-labelledby="s-value">
        <div class="card-header"><h2 id="s-value" class="text-sm font-semibold text-slate-900">Value</h2></div>
        <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-4">
            <div><dt class="text-xs text-slate-500">Taxable value</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">₹ {{ Format::number($order->taxable_total, 2) }}</dd></div>
            <div><dt class="text-xs text-slate-500">{{ $inter ? 'IGST' : 'CGST + SGST' }}</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">₹ {{ Format::number($inter ? $order->igst_total : bcadd((string) $order->cgst_total, (string) $order->sgst_total, 2), 2) }}</dd></div>
            <div><dt class="text-xs text-slate-500">Total</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">₹ {{ Format::number($order->grand_total, 2) }}</dd></div>
            <div><dt class="text-xs text-slate-500">Supply</dt><dd class="text-sm text-slate-900">{{ $inter ? 'Inter-state' : 'Within the state' }} · {{ $order->placeOfSupply?->name }}</dd></div>
        </dl>
    </section>

    <section class="card" aria-labelledby="s-credit">
        <div class="card-header"><h2 id="s-credit" class="text-sm font-semibold text-slate-900">Credit check</h2><span class="badge {{ $order->credit_result->badge() }}">{{ $order->credit_result->label() }}</span></div>
        <div class="card-body space-y-1 text-sm text-slate-700">
            @if ($order->credit_limit !== null)
                <p>Credit limit <strong class="tabular-nums">₹ {{ Format::number($order->credit_limit, 2) }}</strong> · already out on other orders still in their credit days <strong class="tabular-nums">₹ {{ Format::number($order->credit_exposure, 2) }}</strong> · this order <strong class="tabular-nums">₹ {{ Format::number($order->grand_total, 2) }}</strong>.</p>
            @elseif ($order->credit_result === CreditResult::Ignored)
                <p>The company has switched the credit check off.</p>
            @elseif ($order->credit_result === CreditResult::NoLimit)
                <p>The customer has no credit limit on the ledger, so there is nothing to exceed.</p>
            @else
                <p>Not checked yet: it is checked when the order is saved and again when it is sent for approval.</p>
            @endif
            @if ($order->credit_override_reason)<p class="text-indigo-800">Allowed over the limit: “{{ $order->credit_override_reason }}”</p>@endif
            <p class="text-xs text-slate-500">Nothing records payments yet, so goods sold on credit count as unpaid until the customer’s credit days are over.</p>
        </div>
    </section>

    <section class="card" aria-labelledby="s-details">
        <div class="card-header"><h2 id="s-details" class="text-sm font-semibold text-slate-900">Details</h2></div>
        <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-3">
            <div><dt class="text-xs text-slate-500">Customer</dt><dd class="text-sm text-slate-900">@if ($canViewCustomer)<a href="{{ route('masters.ledgers.show', $order->customer) }}" wire:navigate class="text-brand-700 hover:underline">{{ $order->party_name }}</a>@else{{ $order->party_name }}@endif</dd></div>
            @if ($order->contact_person)<div><dt class="text-xs text-slate-500">Contact person</dt><dd class="text-sm text-slate-900">{{ $order->contact_person }}</dd></div>@endif
            @if ($order->mobile)<div><dt class="text-xs text-slate-500">Mobile</dt><dd class="font-mono text-sm text-slate-900">{{ $order->mobile }}</dd></div>@endif
            <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Billing address</dt><dd class="text-sm text-slate-900">{{ implode(', ', $order->billingLines()) }}</dd></div>
            @if ($order->customer_gstin)<div><dt class="text-xs text-slate-500">Customer GSTIN</dt><dd class="font-mono text-sm text-slate-900">{{ $order->customer_gstin }}</dd></div>@endif
            <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Shipping address</dt><dd class="text-sm text-slate-900">{{ implode(', ', $order->shippingLines()) }}</dd></div>
            @if ($order->customer_po_number)<div><dt class="text-xs text-slate-500">Customer’s order</dt><dd class="text-sm text-slate-900">{{ $order->customer_po_number }}@if ($order->customer_po_date) · {{ $order->customer_po_date->format('j M Y') }}@endif</dd></div>@endif
            <div><dt class="text-xs text-slate-500">Order date</dt><dd class="text-sm text-slate-900">{{ $order->order_date->format('j M Y') }}</dd></div>
            <div><dt class="text-xs text-slate-500">Prices as on</dt><dd class="text-sm text-slate-900">{{ $order->price_date->format('j M Y') }} · {{ $order->priceList?->name ?? 'standard rates' }}</dd></div>
            <div><dt class="text-xs text-slate-500">Made from</dt><dd class="text-sm text-slate-900">
                {{ $source }}
                @if ($quotation)· @if ($canViewQuotation)<a href="{{ route('sales.quotations.show', $quotation) }}" wire:navigate class="font-mono text-xs text-brand-700 hover:underline">{{ $quotation->displayNumber() }}</a>@else<span class="font-mono text-xs">{{ $quotation->displayNumber() }}</span>@endif @endif
                @if ($deal)· @if ($canViewDeal)<a href="{{ route('crm.deals.show', $deal) }}" wire:navigate class="font-mono text-xs text-brand-700 hover:underline">{{ $deal->deal_number }}</a>@else<span class="font-mono text-xs">{{ $deal->deal_number }}</span>@endif @endif
            </dd></div>
            @if ($order->terms)<div class="sm:col-span-3"><dt class="text-xs text-slate-500">Terms and conditions</dt><dd class="whitespace-pre-line text-sm text-slate-900">{{ $order->terms }}</dd></div>@endif
            @if ($order->remarks)<div class="sm:col-span-3"><dt class="text-xs text-slate-500">Internal remarks</dt><dd class="whitespace-pre-line text-sm text-slate-900">{{ $order->remarks }}</dd></div>@endif
        </dl>
    </section>

    <section class="card" aria-labelledby="s-items">
        <div class="card-header"><h2 id="s-items" class="text-sm font-semibold text-slate-900">Item lines</h2><span class="text-xs text-slate-500">{{ $order->items->count() }} {{ $order->items->count() === 1 ? 'line' : 'lines' }}</span></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>#</th><th>Item</th><th class="text-right">Quantity</th><th class="text-right">Rate (₹)</th><th class="text-right">Disc %</th><th class="text-right">Taxable (₹)</th><th class="text-right">GST %</th><th class="text-right">Tax (₹)</th><th class="text-right">Total (₹)</th></tr></thead>
                <tbody>
                    @foreach ($order->items as $line)
                        <tr wire:key="ln-{{ $line->id }}">
                            <td class="tabular-nums text-slate-500">{{ $line->line_no }}</td>
                            <td>
                                <span class="font-medium text-slate-900">{{ $line->item_name }}</span>
                                @if ($line->hsn_code)<span class="ml-1 text-xs text-slate-500">HSN {{ $line->hsn_code }}</span>@endif
                                @if ($line->rate_overridden)<span class="badge ml-1 bg-amber-100 text-amber-800" title="Master rate ₹ {{ $rate($line->list_rate) }} — {{ $line->override_reason }}">Rate changed</span>@endif
                            </td>
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
                    <tr><td colspan="8" class="text-right text-sm text-slate-600">Total before discount</td><td class="text-right tabular-nums">{{ Format::number($order->gross_total, 2) }}</td></tr>
                    @if (bccomp((string) $order->discount_total, '0', 2) > 0)<tr><td colspan="8" class="text-right text-sm text-slate-600">Discount</td><td class="text-right tabular-nums">– {{ Format::number($order->discount_total, 2) }}</td></tr>@endif
                    @if ($inter)
                        <tr><td colspan="8" class="text-right text-sm text-slate-600">IGST</td><td class="text-right tabular-nums">{{ Format::number($order->igst_total, 2) }}</td></tr>
                    @else
                        <tr><td colspan="8" class="text-right text-sm text-slate-600">CGST</td><td class="text-right tabular-nums">{{ Format::number($order->cgst_total, 2) }}</td></tr>
                        <tr><td colspan="8" class="text-right text-sm text-slate-600">SGST</td><td class="text-right tabular-nums">{{ Format::number($order->sgst_total, 2) }}</td></tr>
                    @endif
                    @if (bccomp((string) $order->round_off, '0', 2) !== 0)<tr><td colspan="8" class="text-right text-sm text-slate-600">Round off</td><td class="text-right tabular-nums">{{ $order->round_off }}</td></tr>@endif
                    <tr><td colspan="8" class="text-right text-sm font-medium text-slate-700">Total</td><td class="text-right font-semibold tabular-nums text-slate-900">{{ Format::number($order->grand_total, 2) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </section>

    <livewire:shared.approval-panel type="sales_order" :id="$order->id" :key="'ap-'.$order->id" />

    <section class="card" aria-labelledby="s-states">
        <div class="card-header"><h2 id="s-states" class="text-sm font-semibold text-slate-900">Status history</h2></div>
        <ul class="divide-y divide-slate-100 text-sm">
            @foreach ($states as $entry)
                <li class="px-5 py-2.5" wire:key="st-{{ $entry->id }}">
                    <p class="flex flex-wrap items-baseline justify-between gap-2">
                        <span class="font-medium text-slate-900">{{ $entry->label() }}</span>
                        <span class="text-xs text-slate-500">{{ $entry->actor?->name ?? 'System' }} · {{ Format::dateTime($entry->created_at) }}</span>
                    </p>
                    @if ($entry->comment)<p class="text-slate-600">{{ $entry->comment }}</p>@endif
                </li>
            @endforeach
        </ul>
    </section>

    <livewire:shared.comments type="sales_order" :id="$order->id" :key="'cm-'.$order->id" />

    @if ($history->isNotEmpty())
        <section class="card" aria-labelledby="s-history">
            <div class="card-header"><h2 id="s-history" class="text-sm font-semibold text-slate-900">Audit trail</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($history as $entry)
                    @php
                        $changed = collect($entry->properties['attributes'] ?? [])->keys()->reject(fn ($k) => in_array($k, ['updated_by', 'created_by'], true))->map(fn ($k) => str_replace('_', ' ', $k))->implode(', ');
                    @endphp
                    <li class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-2.5" wire:key="h-{{ $entry->id }}">
                        <span>
                            <span class="font-medium text-slate-900">{{ str_replace('_', ' ', ucfirst((string) ($entry->event ?? $entry->description))) }}</span>
                            @if ($changed !== '')<span class="text-slate-500"> — {{ $changed }}</span>@endif
                        </span>
                        <span class="text-xs text-slate-500">{{ $entry->causer?->name ?? 'System' }} · {{ $entry->created_at?->format(config('formezy.display.datetime')) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- ---- dialogs ---- --}}
    @if ($dialog !== '')
        @php
            [$title, $submit, $action] = match ($dialog) {
                'credit' => ['Allow this order over the credit limit', 'Allow it', 'allowOverCredit'],
                'cancel' => ['Cancel this order', 'Cancel the order', 'cancelOrder'],
                default => ['Re-open this order for editing', 'Re-open', 'reopenApproval'],
            };
        @endphp
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="dialog-title">
            <form wire:submit="{{ $action }}" class="card w-full max-w-lg" novalidate wire:key="dialog-{{ $dialog }}">
                <div class="card-header">
                    <h2 id="dialog-title" class="text-sm font-semibold text-slate-900">{{ $title }}</h2>
                    <button type="button" wire:click="closeDialog" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="card-body space-y-3">
                    @error('workflow')<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror

                    @if ($dialog === 'credit')
                        <p class="text-sm text-slate-600">{{ $creditText }}. The company blocks orders over the limit; if you allow this one, the reason is kept on the order and in the audit log. A bigger order later needs it allowed again.</p>
                    @elseif ($dialog === 'cancel')
                        <p class="text-sm text-slate-600">The order is withdrawn{{ $order->invoice ? ', and the invoice made from it is cancelled with it' : '' }}. It stops counting towards targets, and its quotation or deal can be ordered again.</p>
                    @else
                        <p class="text-sm text-slate-600">The order goes back to a draft so it can be corrected. It has to be approved again, and only then is its invoice made.</p>
                    @endif
                    <div>
                        <label for="d-reason" class="label">Why?</label>
                        <textarea id="d-reason" rows="2" wire:model="reason" class="input" maxlength="1000"></textarea>
                        @error('reason') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeDialog" class="btn-secondary">Back</button>
                    <button type="submit" @class(['btn-danger' => $dialog === 'cancel', 'btn-primary' => $dialog !== 'cancel']) wire:loading.attr="disabled">{{ $submit }}</button>
                </div>
            </form>
        </div>
    @endif
</div>

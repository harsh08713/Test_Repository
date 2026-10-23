@php
    use App\Modules\Sales\Enums\QuotationState;
    use App\Modules\Shared\Support\Format;

    $inter = $quotation->isInterState();
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
            <p class="text-sm text-slate-500"><a href="{{ route('sales.quotations.index') }}" wire:navigate class="hover:text-brand-700">Quotations</a> › {{ $quotation->location?->name }} @can('task.create')· <a href="{{ route('workspace.tasks.create', ['type' => 'quotation', 'id' => $quotation->id]) }}" wire:navigate class="hover:text-brand-700">Add a task</a>@endcan @if ($quotation->customer_ledger_id) · <a href="{{ route('crm.customers.show', $quotation->customer_ledger_id) }}" wire:navigate class="hover:text-brand-700">Customer 360</a>@endif</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $quotation->party_name }} <span class="font-mono text-base font-normal text-slate-500">{{ $quotation->quotation_number }} · Rev {{ $quotation->revision_no }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span class="badge {{ $quotation->state->badge() }}">{{ $quotation->state->label() }}</span>
                <span class="badge {{ $quotation->approval_status->badge() }}">{{ $quotation->approval_status->label() }}</span>
                <span class="tabular-nums text-slate-600">Valid until {{ $quotation->valid_until->format('j M Y') }}</span>
                @if ($quotation->state === QuotationState::Sent && $quotation->isPastValidity())<span class="badge bg-amber-100 text-amber-800">Validity has run out</span>@endif
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canDownload)<a href="{{ route('sales.quotations.pdf', $quotation) }}" class="btn-secondary">Download PDF</a>@endif
            @if ($canSend)<button type="button" wire:click="openDialog('send')" class="btn-primary">Send to customer…</button>@endif
            @if ($canOrder)<a href="{{ route('sales.orders.from-quotation', $quotation) }}" wire:navigate class="btn-primary">Make a sales order</a>@endif
            @if ($canRespond)
                <button type="button" wire:click="openDialog('accept')" class="btn-secondary">Customer accepted…</button>
                <button type="button" wire:click="openDialog('decline')" class="btn-secondary">Customer declined…</button>
            @endif
            @if ($canRevise)<button type="button" wire:click="openDialog('revise')" class="btn-secondary">Make a new revision…</button>@endif
            @if ($canCancel)<button type="button" wire:click="openDialog('cancel')" class="btn-secondary">Withdraw…</button>@endif
            @if ($canReopenApproval)<button type="button" wire:click="openDialog('reopen-approval')" class="btn-secondary">Re-open for editing…</button>@endif
            @if ($canEdit)<a href="{{ route('sales.quotations.edit', $quotation) }}" wire:navigate class="btn-secondary">Edit</a>@endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif
    @if ($dialog === '')
        @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @endif

    @if (! $quotation->isLatestRevision())
        <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">This revision has been replaced by a newer one and is kept as the customer saw it. <a href="#revisions" class="text-brand-700 hover:underline">See the revisions</a>.</div>
    @elseif ($quotation->state === QuotationState::Draft && $quotation->isPendingApproval())
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">This quotation is waiting for approval, so it is locked. It can be sent once it is approved.</div>
    @elseif ($quotation->state === QuotationState::Draft && $quotation->isApproved())
        <div class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900">Approved. Download the PDF, or send it to the customer.</div>
    @elseif ($quotation->state === QuotationState::Declined)
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">Declined{{ $quotation->declined_at ? ' on '.$quotation->declined_at->format('j M Y') : '' }}@if ($quotation->declined_reason): “{{ $quotation->declined_reason }}”@endif</div>
    @elseif ($quotation->state === QuotationState::Cancelled)
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">Withdrawn{{ $quotation->cancelled_at ? ' on '.$quotation->cancelled_at->format('j M Y') : '' }}@if ($quotation->cancel_reason): “{{ $quotation->cancel_reason }}”@endif</div>
    @elseif ($quotation->state === QuotationState::Accepted)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">Accepted{{ $quotation->accepted_at ? ' on '.$quotation->accepted_at->format('j M Y') : '' }}@if ($quotation->accepted_note): “{{ $quotation->accepted_note }}”@endif</div>
    @endif

    <section class="card" aria-labelledby="s-value">
        <div class="card-header"><h2 id="s-value" class="text-sm font-semibold text-slate-900">Value</h2></div>
        <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-4">
            <div><dt class="text-xs text-slate-500">Taxable value</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">₹ {{ Format::number($quotation->taxable_total, 2) }}</dd></div>
            <div><dt class="text-xs text-slate-500">{{ $inter ? 'IGST' : 'CGST + SGST' }}</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">₹ {{ Format::number($inter ? $quotation->igst_total : bcadd((string) $quotation->cgst_total, (string) $quotation->sgst_total, 2), 2) }}</dd></div>
            <div><dt class="text-xs text-slate-500">Total</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">₹ {{ Format::number($quotation->grand_total, 2) }}</dd></div>
            <div><dt class="text-xs text-slate-500">Supply</dt><dd class="text-sm text-slate-900">{{ $inter ? 'Inter-state' : 'Within the state' }} · {{ $quotation->placeOfSupply?->name }}</dd></div>
        </dl>
    </section>

    <section class="card" aria-labelledby="s-details">
        <div class="card-header"><h2 id="s-details" class="text-sm font-semibold text-slate-900">Details</h2></div>
        <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-3">
            <div><dt class="text-xs text-slate-500">Customer</dt><dd class="text-sm text-slate-900">
                @if ($quotation->customer && $canViewCustomer)<a href="{{ route('masters.ledgers.show', $quotation->customer) }}" wire:navigate class="text-brand-700 hover:underline">{{ $quotation->party_name }}</a>@else{{ $quotation->party_name }}@endif
                @if (! $quotation->customer)<span class="text-xs text-slate-500"> (not a customer yet)</span>@endif
            </dd></div>
            @if ($quotation->contact_person)<div><dt class="text-xs text-slate-500">Contact person</dt><dd class="text-sm text-slate-900">{{ $quotation->contact_person }}</dd></div>@endif
            @if ($quotation->mobile)<div><dt class="text-xs text-slate-500">Mobile</dt><dd class="font-mono text-sm text-slate-900">{{ $quotation->mobile }}</dd></div>@endif
            @if ($quotation->email)<div><dt class="text-xs text-slate-500">E-mail</dt><dd class="text-sm text-slate-900">{{ $quotation->email }}</dd></div>@endif
            <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Billing address</dt><dd class="text-sm text-slate-900">{{ implode(', ', $quotation->addressLines()) }}</dd></div>
            @if ($quotation->customer_gstin)<div><dt class="text-xs text-slate-500">Customer GSTIN</dt><dd class="font-mono text-sm text-slate-900">{{ $quotation->customer_gstin }}</dd></div>@endif
            <div><dt class="text-xs text-slate-500">Quotation date</dt><dd class="text-sm text-slate-900">{{ $quotation->quotation_date->format('j M Y') }}</dd></div>
            <div><dt class="text-xs text-slate-500">Prices as on</dt><dd class="text-sm text-slate-900">{{ $quotation->price_date->format('j M Y') }} · {{ $quotation->priceList?->name ?? 'standard rates' }}</dd></div>
            <div><dt class="text-xs text-slate-500">Made from</dt><dd class="text-sm text-slate-900">
                @if ($deal)
                    @if ($canViewDeal)<a href="{{ route('crm.deals.show', $deal) }}" wire:navigate class="font-mono text-xs text-brand-700 hover:underline">{{ $deal->deal_number }}</a>@else<span class="font-mono text-xs">{{ $deal->deal_number }}</span>@endif · deal
                @elseif ($lead)
                    @if ($canViewLead)<a href="{{ route('crm.leads.show', $lead) }}" wire:navigate class="font-mono text-xs text-brand-700 hover:underline">{{ $lead->lead_number }}</a>@else<span class="font-mono text-xs">{{ $lead->lead_number }}</span>@endif · inquiry
                @endif
            </dd></div>
            @if ($quotation->terms)<div class="sm:col-span-3"><dt class="text-xs text-slate-500">Terms and conditions</dt><dd class="whitespace-pre-line text-sm text-slate-900">{{ $quotation->terms }}</dd></div>@endif
            @if ($quotation->remarks)<div class="sm:col-span-3"><dt class="text-xs text-slate-500">Internal remarks</dt><dd class="whitespace-pre-line text-sm text-slate-900">{{ $quotation->remarks }}</dd></div>@endif
        </dl>
    </section>

    <section class="card" aria-labelledby="s-items">
        <div class="card-header"><h2 id="s-items" class="text-sm font-semibold text-slate-900">Item lines</h2><span class="text-xs text-slate-500">{{ $quotation->items->count() }} {{ $quotation->items->count() === 1 ? 'line' : 'lines' }}</span></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>#</th><th>Item</th><th class="text-right">Quantity</th><th class="text-right">Rate (₹)</th><th class="text-right">Disc %</th><th class="text-right">Taxable (₹)</th><th class="text-right">GST %</th><th class="text-right">Tax (₹)</th><th class="text-right">Total (₹)</th></tr></thead>
                <tbody>
                    @foreach ($quotation->items as $line)
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
                    <tr><td colspan="8" class="text-right text-sm text-slate-600">Total before discount</td><td class="text-right tabular-nums">{{ Format::number($quotation->gross_total, 2) }}</td></tr>
                    @if (bccomp((string) $quotation->discount_total, '0', 2) > 0)<tr><td colspan="8" class="text-right text-sm text-slate-600">Discount</td><td class="text-right tabular-nums">– {{ Format::number($quotation->discount_total, 2) }}</td></tr>@endif
                    @if ($inter)
                        <tr><td colspan="8" class="text-right text-sm text-slate-600">IGST</td><td class="text-right tabular-nums">{{ Format::number($quotation->igst_total, 2) }}</td></tr>
                    @else
                        <tr><td colspan="8" class="text-right text-sm text-slate-600">CGST</td><td class="text-right tabular-nums">{{ Format::number($quotation->cgst_total, 2) }}</td></tr>
                        <tr><td colspan="8" class="text-right text-sm text-slate-600">SGST</td><td class="text-right tabular-nums">{{ Format::number($quotation->sgst_total, 2) }}</td></tr>
                    @endif
                    @if (bccomp((string) $quotation->round_off, '0', 2) !== 0)<tr><td colspan="8" class="text-right text-sm text-slate-600">Round off</td><td class="text-right tabular-nums">{{ $quotation->round_off }}</td></tr>@endif
                    <tr><td colspan="8" class="text-right text-sm font-medium text-slate-700">Total</td><td class="text-right font-semibold tabular-nums text-slate-900">{{ Format::number($quotation->grand_total, 2) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </section>

    @if ($quotation->isLatestRevision() && ($conversion['hard'] !== [] || $conversion['soft'] !== []) && $quotation->state !== QuotationState::Draft)
        <section class="card" aria-labelledby="s-order">
            <div class="card-header"><h2 id="s-order" class="text-sm font-semibold text-slate-900">Ordering from this quotation</h2></div>
            <ul class="card-body list-disc space-y-1 pl-9 text-sm text-slate-700">
                @foreach ($conversion['hard'] as $why)<li>{{ $why }}</li>@endforeach
                @foreach ($conversion['soft'] as $why)<li>{{ $why }} <span class="text-slate-500">Someone with the right to make an exception can still order, giving a reason.</span></li>@endforeach
            </ul>
        </section>
    @endif

    @if ($orders->isNotEmpty())
        <section class="card" aria-labelledby="s-orders">
            <div class="card-header"><h2 id="s-orders" class="text-sm font-semibold text-slate-900">Sales orders made from this quotation</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($orders as $so)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2.5" wire:key="so-{{ $so->id }}">
                        <span>
                            <a href="{{ route('sales.orders.show', $so) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $so->so_number }}</a>
                            <span class="badge ml-2 {{ $so->state->badge() }}">{{ $so->state->label() }}</span>
                            <span class="badge ml-1 {{ $so->approval_status->badge() }}">{{ $so->approval_status->label() }}</span>
                        </span>
                        <span class="text-xs tabular-nums text-slate-500">₹ {{ Format::number($so->grand_total, 2) }} · {{ $so->order_date->format('j M Y') }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <livewire:shared.approval-panel type="quotation" :id="$quotation->id" :key="'ap-'.$quotation->id" />

    @if ($revisions->count() > 1)
        <section class="card" aria-labelledby="revisions" id="revisions">
            <div class="card-header"><h2 id="revisions" class="text-sm font-semibold text-slate-900">Revisions</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($revisions as $rev)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2.5" wire:key="rev-{{ $rev->id }}">
                        <span>
                            @if ($rev->id === $quotation->id)<span class="font-medium text-slate-900">Revision {{ $rev->revision_no }} (this one)</span>@else<a href="{{ route('sales.quotations.show', $rev->id) }}" wire:navigate class="font-medium text-brand-700 hover:underline">Revision {{ $rev->revision_no }}</a>@endif
                            <span class="badge ml-2 {{ $rev->state->badge() }}">{{ $rev->state->label() }}</span>
                        </span>
                        <span class="text-xs tabular-nums text-slate-500">{{ $rev->quotation_date->format('j M Y') }} · ₹ {{ Format::number($rev->grand_total, 2) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($dispatches->isNotEmpty())
        <section class="card" aria-labelledby="s-sent">
            <div class="card-header"><h2 id="s-sent" class="text-sm font-semibold text-slate-900">Sent to the customer</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($dispatches as $d)
                    <li class="px-5 py-2.5" wire:key="dp-{{ $d->id }}">
                        <p class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-medium text-slate-900">{{ $d->channel === 'email' ? 'E-mailed to '.$d->to_email : 'Recorded as handed over' }}@if ($d->cc_email) <span class="font-normal text-slate-500">(cc {{ $d->cc_email }})</span>@endif</span>
                            <span class="text-xs text-slate-500">{{ $d->actor?->name ?? 'System' }} · {{ Format::dateTime($d->created_at) }}</span>
                        </p>
                        <p class="text-xs"><span class="badge {{ $d->status === 'sent' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}">{{ $d->status === 'sent' ? 'Delivered to the mail server' : 'Failed' }}</span> @if ($d->error)<span class="text-rose-700">{{ $d->error }}</span>@endif</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

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

    <livewire:shared.comments type="quotation" :id="$quotation->id" :key="'cm-'.$quotation->id" />

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
                'send' => ['Send this quotation to the customer', 'Send', 'sendQuotation'],
                'accept' => ['The customer accepted', 'Record acceptance', 'recordAccepted'],
                'decline' => ['The customer declined', 'Record refusal', 'recordDeclined'],
                'revise' => ['Make a new revision', 'Make revision '.($quotation->revision_no + 1), 'reviseQuotation'],
                'cancel' => ['Withdraw this quotation', 'Withdraw', 'cancelQuotation'],
                default => ['Re-open this quotation for editing', 'Re-open', 'reopenApproval'],
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

                    @if ($dialog === 'send')
                        <div class="flex gap-4 text-sm">
                            <label class="flex items-center gap-2"><input type="radio" wire:model.live="channel" value="email" class="border-slate-300"> E-mail it with the PDF</label>
                            <label class="flex items-center gap-2"><input type="radio" wire:model.live="channel" value="manual" class="border-slate-300"> I have handed it over myself</label>
                        </div>
                        @if ($channel === 'email')
                            <div>
                                <label for="m-to" class="label">To</label>
                                <input id="m-to" type="email" wire:model="to" class="input" maxlength="150">
                                @error('to') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="m-cc" class="label">Cc <span class="font-normal text-slate-500">(optional)</span></label>
                                <input id="m-cc" type="email" wire:model="cc" class="input" maxlength="150">
                                @error('cc') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="m-subject" class="label">Subject</label>
                                <input id="m-subject" type="text" wire:model="subject" class="input" maxlength="200">
                                @error('subject') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="m-message" class="label">Message <span class="font-normal text-slate-500">(optional — a standard note is used if you leave it empty)</span></label>
                                <textarea id="m-message" rows="3" wire:model="message" class="input" maxlength="2000"></textarea>
                                @error('message') <p class="error">{{ $message }}</p> @enderror
                            </div>
                        @else
                            <p class="text-sm text-slate-600">The quotation is marked as sent and its follow-up is planned; nothing is e-mailed. Use this when you gave the customer the PDF in person or by another channel.</p>
                        @endif
                        <p class="text-xs text-slate-500">Once sent, this revision cannot be changed: a change is a new revision.</p>
                    @endif

                    @if ($dialog === 'accept')
                        <div>
                            <label for="a-note" class="label">Note <span class="font-normal text-slate-500">(optional — for example the customer’s purchase order number)</span></label>
                            <textarea id="a-note" rows="2" wire:model="note" class="input" maxlength="500"></textarea>
                            @error('reason') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($dialog === 'decline')
                        <div>
                            <label for="d-reason" class="label">Why did the customer decline?</label>
                            <textarea id="d-reason" rows="2" wire:model="reason" class="input" maxlength="500"></textarea>
                            @error('reason') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($dialog === 'revise')
                        <p class="text-sm text-slate-600">Revision {{ $quotation->revision_no + 1 }} starts as a copy of this one, with the same prices. This revision stays exactly as the customer saw it and is marked superseded. The new one has to be approved before it is sent.</p>
                        <div>
                            <label for="v-reason" class="label">What is changing? <span class="font-normal text-slate-500">(optional)</span></label>
                            <textarea id="v-reason" rows="2" wire:model="reason" class="input" maxlength="1000"></textarea>
                        </div>
                    @endif

                    @if ($dialog === 'cancel')
                        <p class="text-sm text-slate-600">The quotation is withdrawn and cannot be used for an order. The deal goes back to Negotiation if no other quotation of it is standing.</p>
                        <div>
                            <label for="c-reason" class="label">Why?</label>
                            <textarea id="c-reason" rows="2" wire:model="reason" class="input" maxlength="500"></textarea>
                            @error('reason') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($dialog === 'reopen-approval')
                        <p class="text-sm text-slate-600">The quotation goes back to a draft so it can be corrected. It cannot be sent until it is approved again.</p>
                        <div>
                            <label for="o-reason" class="label">Why is it being re-opened?</label>
                            <textarea id="o-reason" rows="2" wire:model="reason" class="input" maxlength="1000"></textarea>
                            @error('reason') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeDialog" class="btn-secondary">Cancel</button>
                    <button type="submit" @class(['btn-danger' => in_array($dialog, ['cancel', 'decline'], true), 'btn-primary' => ! in_array($dialog, ['cancel', 'decline'], true)]) wire:loading.attr="disabled">{{ $submit }}</button>
                </div>
            </form>
        </div>
    @endif
</div>

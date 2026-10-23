<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $editing ? 'Edit deal' : 'Convert to a deal' }}@if ($dealNumber) <span class="font-mono text-base font-normal text-slate-500">{{ $dealNumber }}</span>@endif</h1>
            <p class="mt-1 text-sm text-slate-500">
                @if ($editing)
                    The customer and the lines are what an approver signs off on. They can be changed only while the deal is a draft or was returned.
                @else
                    Every item the lead listed comes across; adjust the quantities and rates, and add what else was agreed. The value of the deal is worked out from the lines.
                @endif
            </p>
        </div>
        <a href="{{ $editing ? route('crm.deals.show', $dealId) : route('crm.leads.show', $leadId) }}" wire:navigate class="btn-secondary">Cancel</a>
    </div>

    @if ($errors->any())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">
            @error('form'){{ $message }}@else Some details need attention. Please check the fields marked below.@enderror
        </div>
    @endif

    <section class="card" aria-labelledby="sec-source">
        <div class="card-header"><h2 id="sec-source" class="text-sm font-semibold text-slate-900">From the lead</h2></div>
        <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-4">
            <div><dt class="text-xs text-slate-500">Lead</dt><dd class="text-sm text-slate-900"><a href="{{ route('crm.leads.show', $lead) }}" wire:navigate class="font-mono text-xs text-brand-700 hover:underline">{{ $lead->lead_number }}</a> · {{ $lead->name }}</dd></div>
            <div><dt class="text-xs text-slate-500">Kind</dt><dd class="text-sm text-slate-900">{{ $lead->lead_type->label() }}</dd></div>
            <div><dt class="text-xs text-slate-500">Branch</dt><dd class="text-sm text-slate-900">{{ $lead->location?->name }}</dd></div>
            <div><dt class="text-xs text-slate-500">Owner</dt><dd class="text-sm text-slate-900">{{ $lead->assignee?->name ?? 'Nobody yet' }}</dd></div>
        </dl>
    </section>

    <form wire:submit="save" class="space-y-5" novalidate>
        <section class="card" aria-labelledby="sec-deal">
            <div class="card-header"><h2 id="sec-deal" class="text-sm font-semibold text-slate-900">The deal</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                    <span class="label">Customer</span>
                    @if (($deal['customer_ledger_id'] ?? '') !== '')
                        <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                            <span class="truncate text-slate-900">{{ $customerLabel !== '' ? $customerLabel : 'Customer #'.$deal['customer_ledger_id'] }}</span>
                            @if (! $customerFixed)<button type="button" wire:click="clearCustomer" class="shrink-0 text-xs text-brand-700 hover:underline">Change</button>@endif
                        </div>
                        @if ($customerFixed)<p class="help">An inquiry’s deal is for the inquiry’s customer.</p>@endif
                    @elseif ($customerFixed)
                        <p class="input bg-slate-50 text-slate-500">—</p>
                    @else
                        <label for="d-cust" class="sr-only">Find a customer</label>
                        <input id="d-cust" type="search" wire:model.live.debounce.300ms="customerSearch" class="input" autocomplete="off" placeholder="{{ $lead->name }} — not a customer yet? Leave this empty">
                        @if (! empty($customers))
                            <ul class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                                @foreach ($customers as $hit)
                                    <li><button type="button" wire:click="pickCustomer({{ $hit['id'] }})" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">{{ $hit['label'] }}</button></li>
                                @endforeach
                            </ul>
                        @elseif (trim($customerSearch) !== '')
                            <p class="help">Nothing found: only approved, active customers can be chosen.</p>
                        @else
                            <p class="help">Link the customer ledger if there is one. A deal for a prospect needs one before an order is taken.</p>
                        @endif
                    @endif
                    @error('deal.customer_ledger_id') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="d-close" class="label">Expected to close on</label>
                    <input id="d-close" type="date" wire:model="deal.expected_close_date" min="{{ $today }}" class="input" required>
                    @error('deal.expected_close_date') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-1">
                    <label for="d-prob" class="label">Probability %</label>
                    <input id="d-prob" type="number" min="1" max="99" step="1" wire:model="deal.probability" class="input tabular-nums">
                    @error('deal.probability') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-6">
                    <label for="d-remarks" class="label">Remarks <span class="font-normal text-slate-500">(optional)</span></label>
                    <textarea id="d-remarks" rows="2" wire:model="deal.remarks" class="input" maxlength="2000"></textarea>
                    @error('deal.remarks') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        @include('crm.partials.item-rows', [
            'prop' => 'items', 'rows' => $items, 'itemUnits' => $itemUnits, 'results' => $results, 'max' => $maxLines,
            'title' => 'Item lines',
            'emptyText' => 'No lines yet. Add at least one item.',
        ])

        @if ($preview !== null)
            <p class="text-right text-sm text-slate-600">The lines come to <strong class="tabular-nums text-slate-900">₹ {{ \App\Modules\Shared\Support\Format::number($preview, 2) }}</strong> <span class="text-xs text-slate-500">(worked out again when you save)</span></p>
        @endif

        <div class="flex justify-end gap-3">
            <a href="{{ $editing ? route('crm.deals.show', $dealId) : route('crm.leads.show', $leadId) }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $editing ? 'Save deal' : 'Make the deal' }}</button>
        </div>
    </form>
</div>

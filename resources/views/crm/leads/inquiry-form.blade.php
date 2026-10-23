<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $editing ? 'Edit inquiry' : 'Inquiry from an existing customer' }}@if ($leadNumber) <span class="font-mono text-base font-normal text-slate-500">{{ $leadNumber }}</span>@endif</h1>
            <p class="mt-1 text-sm text-slate-500">Choose the customer, then list what they asked for with the rate they expect. The customer’s name, contact and address are taken from their ledger. An inquiry is sent for approval before a quotation is made from it.</p>
        </div>
        <a href="{{ $editing ? route('crm.leads.show', $leadId) : route('crm.leads.index') }}" wire:navigate class="btn-secondary">Cancel</a>
    </div>

    @if ($errors->any())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">
            @error('form'){{ $message }}@else Some details need attention. Please check the fields marked below.@enderror
        </div>
    @endif

    <form wire:submit="save" class="space-y-5" novalidate>
        <section class="card" aria-labelledby="sec-customer">
            <div class="card-header"><h2 id="sec-customer" class="text-sm font-semibold text-slate-900">Customer</h2></div>
            <div class="card-body space-y-3">
                @if (($lead['customer_ledger_id'] ?? '') !== '')
                    <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                        <span class="truncate text-slate-900">{{ $customerLabel !== '' ? $customerLabel : 'Customer #'.$lead['customer_ledger_id'] }}</span>
                        @if (! $editing)<button type="button" wire:click="clearCustomer" class="shrink-0 text-xs text-brand-700 hover:underline">Change</button>@endif
                    </div>
                    @if ($editing)<p class="help">The customer is fixed once the inquiry is entered. For a different customer, enter a new inquiry.</p>@endif
                @else
                    <div>
                        <label for="c-search" class="label">Find a customer</label>
                        <input id="c-search" type="search" wire:model.live.debounce.300ms="customerSearch" class="input" autocomplete="off" placeholder="Account code, name or GSTIN…">
                        @if (! empty($customers))
                            <ul class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                                @foreach ($customers as $hit)
                                    <li><button type="button" wire:click="pickCustomer({{ $hit['id'] }})" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">{{ $hit['label'] }}</button></li>
                                @endforeach
                            </ul>
                        @elseif (trim($customerSearch) !== '')
                            <p class="help">Nothing found: only approved, active customers can be chosen.</p>
                        @endif
                    </div>
                @endif
                @error('lead.customer_ledger_id') <p class="error">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="card" aria-labelledby="sec-inquiry">
            <div class="card-header"><h2 id="sec-inquiry" class="text-sm font-semibold text-slate-900">The inquiry</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-2">
                    <label for="q-date" class="label">Inquiry date</label>
                    <input id="q-date" type="date" wire:model="lead.lead_date" max="{{ $today }}" class="input" required>
                    @error('lead.lead_date') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="q-branch" class="label">Branch</label>
                    <select id="q-branch" wire:model.live="lead.location_id" class="input" @disabled($editing) required>
                        <option value="">Select…</option>
                        @foreach ($branches as $bid => $name)<option value="{{ $bid }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('lead.location_id') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="q-source" class="label">Where it came from</label>
                    <select id="q-source" wire:model="lead.lead_source_id" class="input" required>
                        <option value="">Select…</option>
                        @foreach ($sources as $sid => $name)<option value="{{ $sid }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('lead.lead_source_id') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="q-owner" class="label">Owner</label>
                    @if ($canAssign)
                        <select id="q-owner" wire:model="lead.assigned_to" class="input">
                            <option value="">Leave in the pool</option>
                            @foreach ($owners as $uid => $name)<option value="{{ $uid }}">{{ $name }}</option>@endforeach
                        </select>
                    @else
                        <p class="input bg-slate-50 text-slate-700">{{ $ownerName ?? 'You' }}</p>
                        <p class="help">{{ $editing ? 'An inquiry changes hands only through a transfer.' : 'You own the inquiries you enter.' }}</p>
                    @endif
                    @error('lead.assigned_to') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="q-req" class="label">What they are looking for <span class="font-normal text-slate-500">(optional)</span></label>
                    <textarea id="q-req" rows="2" wire:model="lead.requirement" class="input" maxlength="2000"></textarea>
                    @error('lead.requirement') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        @include('crm.partials.item-rows', [
            'prop' => 'interests', 'rows' => $interests, 'itemUnits' => $itemUnits, 'results' => $results, 'max' => $maxInterests,
            'title' => 'Items the customer asked for',
            'emptyText' => 'Nothing listed yet. Add the items the customer asked for.',
        ])

        @if ($preview !== null)
            <p class="text-right text-sm text-slate-600">The lines come to <strong class="tabular-nums text-slate-900">₹ {{ \App\Modules\Shared\Support\Format::number($preview, 2) }}</strong> <span class="text-xs text-slate-500">(worked out again when you save)</span></p>
        @endif

        <section class="card" aria-labelledby="sec-notes">
            <div class="card-header"><h2 id="sec-notes" class="text-sm font-semibold text-slate-900">Remarks</h2></div>
            <div class="card-body">
                <textarea id="q-remarks" rows="2" wire:model="lead.remarks" class="input" maxlength="1000" aria-label="Remarks"></textarea>
                @error('lead.remarks') <p class="error">{{ $message }}</p> @enderror
            </div>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ $editing ? route('crm.leads.show', $leadId) : route('crm.leads.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $editing ? 'Save inquiry' : 'Add inquiry' }}</button>
        </div>
    </form>
</div>

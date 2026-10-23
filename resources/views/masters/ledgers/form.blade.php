@php
    $isCustomer = $kind === \App\Modules\Masters\Enums\LedgerType::Customer;
    $isSupplier = $kind === \App\Modules\Masters\Enums\LedgerType::Supplier;
    $isParty = $kind->isParty();
    $isB2b = $isCustomer && ($ledger['customer_kind'] ?? '') === 'b2b';
@endphp

<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $editing ? 'Edit' : 'New' }} {{ strtolower($kind->label()) }} ledger</h1>
            <p class="mt-1 text-sm text-slate-500">Saved as a draft. Only after it is submitted and approved can it be used in transactions.</p>
        </div>
        <a href="{{ $editing ? route('masters.ledgers.show', $ledgerId) : route('masters.ledgers.index') }}" wire:navigate class="btn-secondary">Cancel</a>
    </div>

    @if ($errors->any())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">
            Some details need attention. Please check the fields marked below.
        </div>
    @endif

    <form wire:submit="save" class="space-y-5" novalidate>
        {{-- General --}}
        <section class="card" aria-labelledby="sec-general">
            <div class="card-header"><h2 id="sec-general" class="text-sm font-semibold text-slate-900">General</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="l-code" class="label">Account code</label>
                    <input id="l-code" type="text" wire:model="ledger.code" class="input uppercase" maxlength="30" autocomplete="off" placeholder="{{ $suggestedCode ?? '' }}">
                    <p class="help">@if ($editing) Leave as it is, or change it while the ledger is a draft. @else Leave empty to use {{ $suggestedCode }}. @endif</p>
                    @error('ledger.code') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="l-name" class="label">Name</label>
                    <input id="l-name" type="text" wire:model="ledger.name" class="input" maxlength="200" required>
                    @error('ledger.name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="l-legal" class="label">Legal name</label>
                    <input id="l-legal" type="text" wire:model="ledger.legal_name" class="input" maxlength="200">
                    <p class="help">As on the GST certificate, if different from the name above.</p>
                    @error('ledger.legal_name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="l-group" class="label">Account group</label>
                    <select id="l-group" wire:model="ledger.account_group_id" class="input" required>
                        <option value="">Select…</option>
                        @foreach ($groups as $id => $path)<option value="{{ $id }}">{{ $path }}</option>@endforeach
                    </select>
                    <p class="help">Where this ledger is filed in the chart of accounts.</p>
                    @error('ledger.account_group_id') <p class="error">{{ $message }}</p> @enderror
                </div>

                @if ($isCustomer)
                    <fieldset class="sm:col-span-2">
                        <legend class="label">Customer kind</legend>
                        <div class="flex gap-6 text-sm text-slate-700">
                            <label class="inline-flex items-center gap-2"><input type="radio" wire:model.live="ledger.customer_kind" value="b2b" class="size-4"> B2B (business, GST registered)</label>
                            <label class="inline-flex items-center gap-2"><input type="radio" wire:model.live="ledger.customer_kind" value="b2c" class="size-4"> B2C (consumer)</label>
                        </div>
                        @error('ledger.customer_kind') <p class="error">{{ $message }}</p> @enderror
                    </fieldset>
                @endif

                <div>
                    <label for="l-gstin" class="label">GSTIN @if ($isB2b)<span class="text-rose-600">(required)</span>@endif</label>
                    <input id="l-gstin" type="text" wire:model="ledger.gstin" class="input uppercase font-mono" maxlength="15" autocomplete="off">
                    @error('ledger.gstin') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="l-pan" class="label">PAN</label>
                    <input id="l-pan" type="text" wire:model="ledger.pan" class="input uppercase font-mono" maxlength="10" autocomplete="off">
                    @error('ledger.pan') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="l-email" class="label">E-mail</label>
                    <input id="l-email" type="email" wire:model="ledger.email" class="input" maxlength="200">
                    @error('ledger.email') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="l-phone" class="label">Phone</label>
                    <input id="l-phone" type="tel" wire:model="ledger.phone" class="input" maxlength="20">
                    @error('ledger.phone') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="l-status" class="label">Status</label>
                    <select id="l-status" wire:model="ledger.status" class="input">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    @error('ledger.status') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="l-remarks" class="label">Remarks</label>
                    <textarea id="l-remarks" rows="2" wire:model="ledger.remarks" class="input" maxlength="1000"></textarea>
                    @error('ledger.remarks') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- Commercial terms --}}
        @if ($isParty)
            <section class="card" aria-labelledby="sec-terms">
                <div class="card-header"><h2 id="sec-terms" class="text-sm font-semibold text-slate-900">{{ $isCustomer ? 'Credit and payment' : 'Terms and payment' }}</h2></div>
                <div class="card-body grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="l-credit-days" class="label">Credit days</label>
                        <input id="l-credit-days" type="number" min="0" max="365" wire:model="ledger.credit_days" class="input" inputmode="numeric">
                        @error('ledger.credit_days') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    @if ($isCustomer)
                        <div>
                            <label for="l-credit-limit" class="label">Credit limit (₹)</label>
                            <input id="l-credit-limit" type="number" min="0" step="0.01" wire:model="ledger.credit_limit" class="input" inputmode="decimal">
                            @error('ledger.credit_limit') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    @if ($isSupplier)
                        <div>
                            <label for="l-lead" class="label">Lead time (days)</label>
                            <input id="l-lead" type="number" min="0" max="365" wire:model="ledger.lead_time_days" class="input" inputmode="numeric">
                            @error('ledger.lead_time_days') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    <fieldset class="sm:col-span-3">
                        <legend class="label">Payment preferences</legend>
                        <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-700">
                            @forelse ($paymentMethods as $code => $label)
                                <label class="inline-flex items-center gap-2" wire:key="pm-{{ $code }}">
                                    <input type="checkbox" wire:model="ledger.payment_preferences" value="{{ $code }}" class="size-4 rounded"> {{ $label }}
                                </label>
                            @empty
                                <span class="text-slate-500">No payment methods are set up yet.</span>
                            @endforelse
                        </div>
                        @error('ledger.payment_preferences') <p class="error">{{ $message }}</p> @enderror
                        @error('ledger.payment_preferences.*') <p class="error">{{ $message }}</p> @enderror
                    </fieldset>
                </div>
            </section>
        @endif

        {{-- Addresses --}}
        <section class="card" aria-labelledby="sec-addresses">
            <div class="card-header">
                <h2 id="sec-addresses" class="text-sm font-semibold text-slate-900">Addresses @if ($isParty)<span class="font-normal text-slate-500">(at least one billing address before approval)</span>@endif</h2>
                @if (count($addresses) < $maxRows)
                    <button type="button" wire:click="addAddress" class="btn-secondary btn-sm">Add address</button>
                @endif
            </div>
            <div class="card-body space-y-5">
                @error('addresses') <p class="error">{{ $message }}</p> @enderror
                @forelse ($addresses as $i => $address)
                    <fieldset class="rounded-lg border border-slate-200 p-4" wire:key="addr-{{ $address['key'] ?? $i }}">
                        <legend class="px-1 text-sm font-medium text-slate-700">Address {{ $i + 1 }}</legend>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label for="a{{ $i }}-label" class="label">Label</label>
                                <input id="a{{ $i }}-label" type="text" wire:model="addresses.{{ $i }}.label" class="input" maxlength="60" placeholder="Head office, Plant 2…">
                                @error("addresses.$i.label") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2 flex flex-wrap items-end gap-x-6 gap-y-2 pb-2 text-sm text-slate-700">
                                <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="addresses.{{ $i }}.is_billing" class="size-4 rounded"> Billing</label>
                                <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="addresses.{{ $i }}.is_shipping" class="size-4 rounded"> Shipping</label>
                                @if (! empty($address['is_default_billing']) && ! empty($address['is_billing']))
                                    <span class="badge bg-sky-100 text-sky-800">Default billing</span>
                                @elseif (! empty($address['is_billing']))
                                    <button type="button" wire:click="setDefault({{ $i }}, 'billing')" class="text-xs font-medium text-brand-700 hover:underline">Make default billing</button>
                                @endif
                                @if (! empty($address['is_default_shipping']) && ! empty($address['is_shipping']))
                                    <span class="badge bg-sky-100 text-sky-800">Default shipping</span>
                                @elseif (! empty($address['is_shipping']))
                                    <button type="button" wire:click="setDefault({{ $i }}, 'shipping')" class="text-xs font-medium text-brand-700 hover:underline">Make default shipping</button>
                                @endif
                                @error("addresses.$i.is_billing") <span class="error w-full">{{ $message }}</span> @enderror
                                @error("addresses.$i.is_default_billing") <span class="error w-full">{{ $message }}</span> @enderror
                                @error("addresses.$i.is_default_shipping") <span class="error w-full">{{ $message }}</span> @enderror
                            </div>
                            <div class="sm:col-span-3">
                                <label for="a{{ $i }}-line1" class="label">Address line 1</label>
                                <input id="a{{ $i }}-line1" type="text" wire:model="addresses.{{ $i }}.line1" class="input" maxlength="255">
                                @error("addresses.$i.line1") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-3">
                                <label for="a{{ $i }}-line2" class="label">Address line 2</label>
                                <input id="a{{ $i }}-line2" type="text" wire:model="addresses.{{ $i }}.line2" class="input" maxlength="255">
                                @error("addresses.$i.line2") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="a{{ $i }}-pin" class="label">PIN code</label>
                                <input id="a{{ $i }}-pin" type="text" wire:model.blur="addresses.{{ $i }}.pin_code" class="input" maxlength="6" inputmode="numeric" autocomplete="off">
                                @if (! empty($pinHints[$i]))<p class="help" role="status">{{ $pinHints[$i] }}</p>@endif
                                @error("addresses.$i.pin_code") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="a{{ $i }}-state" class="label">State</label>
                                <select id="a{{ $i }}-state" wire:model.live="addresses.{{ $i }}.state_id" class="input">
                                    <option value="">Select…</option>
                                    @foreach ($states as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                                </select>
                                @error("addresses.$i.state_id") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="a{{ $i }}-district" class="label">District</label>
                                <select id="a{{ $i }}-district" wire:model="addresses.{{ $i }}.district_id" class="input">
                                    <option value="">Select…</option>
                                    @foreach (($districts[(int) ($address['state_id'] ?: 0)] ?? collect()) as $district)<option value="{{ $district->id }}">{{ $district->name }}</option>@endforeach
                                </select>
                                @error("addresses.$i.district_id") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="a{{ $i }}-city" class="label">City</label>
                                <input id="a{{ $i }}-city" type="text" wire:model="addresses.{{ $i }}.city" class="input" maxlength="100">
                                @error("addresses.$i.city") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="a{{ $i }}-gstin" class="label">GSTIN of this address</label>
                                <input id="a{{ $i }}-gstin" type="text" wire:model="addresses.{{ $i }}.gstin" class="input uppercase font-mono" maxlength="15" autocomplete="off">
                                @error("addresses.$i.gstin") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="a{{ $i }}-phone" class="label">Phone</label>
                                <input id="a{{ $i }}-phone" type="tel" wire:model="addresses.{{ $i }}.phone" class="input" maxlength="20">
                                @error("addresses.$i.phone") <p class="error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="mt-3 text-right">
                            <button type="button" wire:click="removeAddress({{ $i }})" wire:confirm="Remove this address?" class="btn-ghost btn-sm text-rose-600">Remove address</button>
                        </div>
                    </fieldset>
                @empty
                    <p class="text-sm text-slate-500">No addresses yet.</p>
                @endforelse
            </div>
        </section>

        {{-- Contacts --}}
        <section class="card" aria-labelledby="sec-contacts">
            <div class="card-header">
                <h2 id="sec-contacts" class="text-sm font-semibold text-slate-900">Contacts @if ($isParty)<span class="font-normal text-slate-500">(at least one before approval)</span>@endif</h2>
                @if (count($contacts) < $maxRows)
                    <button type="button" wire:click="addContact" class="btn-secondary btn-sm">Add contact</button>
                @endif
            </div>
            <div class="card-body space-y-5">
                @error('contacts') <p class="error">{{ $message }}</p> @enderror
                @forelse ($contacts as $i => $contact)
                    <fieldset class="rounded-lg border border-slate-200 p-4" wire:key="contact-{{ $contact['key'] ?? $i }}">
                        <legend class="px-1 text-sm font-medium text-slate-700">Contact {{ $i + 1 }} @if (! empty($contact['is_primary']))<span class="badge bg-sky-100 text-sky-800">Primary</span>@endif</legend>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label for="c{{ $i }}-name" class="label">Name</label>
                                <input id="c{{ $i }}-name" type="text" wire:model="contacts.{{ $i }}.name" class="input" maxlength="150">
                                @error("contacts.$i.name") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="c{{ $i }}-designation" class="label">Designation</label>
                                <input id="c{{ $i }}-designation" type="text" wire:model="contacts.{{ $i }}.designation" class="input" maxlength="100">
                                @error("contacts.$i.designation") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="c{{ $i }}-mobile" class="label">Mobile</label>
                                <input id="c{{ $i }}-mobile" type="tel" wire:model="contacts.{{ $i }}.mobile" class="input" maxlength="20" inputmode="tel">
                                @error("contacts.$i.mobile") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="c{{ $i }}-phone" class="label">Phone</label>
                                <input id="c{{ $i }}-phone" type="tel" wire:model="contacts.{{ $i }}.phone" class="input" maxlength="20">
                                @error("contacts.$i.phone") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label for="c{{ $i }}-email" class="label">E-mail</label>
                                <input id="c{{ $i }}-email" type="email" wire:model="contacts.{{ $i }}.email" class="input" maxlength="200">
                                @error("contacts.$i.email") <p class="error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="mt-3 flex justify-between">
                            @if (empty($contact['is_primary']))
                                <button type="button" wire:click="setPrimary({{ $i }})" class="text-xs font-medium text-brand-700 hover:underline">Make primary</button>
                            @else
                                <span></span>
                            @endif
                            <button type="button" wire:click="removeContact({{ $i }})" wire:confirm="Remove this contact?" class="btn-ghost btn-sm text-rose-600">Remove contact</button>
                        </div>
                    </fieldset>
                @empty
                    <p class="text-sm text-slate-500">No contacts yet.</p>
                @endforelse
            </div>
        </section>

        {{-- Bank details --}}
        @if ($kind->allowsBankDetails())
            <section class="card" aria-labelledby="sec-bank">
                <div class="card-header">
                    <h2 id="sec-bank" class="text-sm font-semibold text-slate-900">Bank account <span class="font-normal text-slate-500">{{ $kind->requiresBankDetails() ? '(required before approval)' : '(optional)' }}</span></h2>
                    @if ($canSensitive && ! $kind->requiresBankDetails())
                        <button type="button" wire:click="toggleBank" class="btn-secondary btn-sm">{{ $hasBank ? 'Remove bank account' : 'Add bank account' }}</button>
                    @endif
                </div>
                <div class="card-body">
                    @if (! $canSensitive)
                        <p class="text-sm text-slate-600">
                            Bank account details are restricted. @if ($bankSummary) On file: <span class="font-mono">{{ $bankSummary }}</span>. @endif
                            People with access to sensitive account data add and change them.
                        </p>
                    @elseif ($hasBank)
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="b-name" class="label">Bank name</label>
                                <input id="b-name" type="text" wire:model="bank.bank_name" class="input" maxlength="120">
                                @error('bank.bank_name') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="b-branch" class="label">Branch</label>
                                <input id="b-branch" type="text" wire:model="bank.branch" class="input" maxlength="120">
                                @error('bank.branch') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="b-holder" class="label">Account holder</label>
                                <input id="b-holder" type="text" wire:model="bank.account_holder" class="input" maxlength="150">
                                @error('bank.account_holder') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="b-type" class="label">Account type</label>
                                <select id="b-type" wire:model="bank.account_type" class="input">
                                    <option value="">Select…</option>
                                    @foreach ($accountTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                </select>
                                @error('bank.account_type') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="b-number" class="label">Account number</label>
                                <input id="b-number" type="text" wire:model="bank.account_number" class="input font-mono" inputmode="numeric" autocomplete="off" maxlength="18">
                                <p class="help">9–18 digits. Stored encrypted; others see only the last four digits.</p>
                                @error('bank.account_number') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="b-ifsc" class="label">IFSC</label>
                                <input id="b-ifsc" type="text" wire:model="bank.ifsc" class="input uppercase font-mono" maxlength="11" autocomplete="off">
                                @error('bank.ifsc') <p class="error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @else
                        <p class="text-sm text-slate-500">No bank account recorded.</p>
                    @endif
                </div>
            </section>
        @endif

        <div class="flex justify-end gap-3">
            <a href="{{ $editing ? route('masters.ledgers.show', $ledgerId) : route('masters.ledgers.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save ledger</button>
        </div>
    </form>
</div>

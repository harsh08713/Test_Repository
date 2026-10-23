@php use App\Modules\Shared\Support\Format; @endphp
<div class="mx-auto max-w-6xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $editing ? 'Edit quotation' : 'Make a quotation' }}@if ($quotationNumber) <span class="font-mono text-base font-normal text-slate-500">{{ $quotationNumber }}</span>@endif</h1>
            <p class="mt-1 text-sm text-slate-500">
                @if ($editing)
                    A quotation can be changed only while it is a draft. Once it has been approved and sent, make a new revision instead.
                @else
                    Prices come from the rate master, and GST is worked out from the place of supply. Adjust the quantities, add a discount, and check the customer’s details.
                @endif
            </p>
        </div>
        <a href="{{ $backUrl }}" wire:navigate class="btn-secondary">Cancel</a>
    </div>

    @if ($errors->any())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">
            @error('form'){{ $message }}@else Some details need attention. Please check the fields marked below.@enderror
        </div>
    @endif

    <section class="card" aria-labelledby="sec-source">
        <div class="card-header"><h2 id="sec-source" class="text-sm font-semibold text-slate-900">Made from</h2></div>
        <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-4">
            @if ($deal)
                <div><dt class="text-xs text-slate-500">Deal</dt><dd class="text-sm text-slate-900"><a href="{{ route('crm.deals.show', $deal) }}" wire:navigate class="font-mono text-xs text-brand-700 hover:underline">{{ $deal->deal_number }}</a> · {{ $deal->party_name }}</dd></div>
            @else
                <div><dt class="text-xs text-slate-500">Inquiry</dt><dd class="text-sm text-slate-900"><a href="{{ route('crm.leads.show', $lead) }}" wire:navigate class="font-mono text-xs text-brand-700 hover:underline">{{ $lead->lead_number }}</a> · {{ $lead->name }}</dd></div>
            @endif
            <div><dt class="text-xs text-slate-500">Branch</dt><dd class="text-sm text-slate-900">{{ $lead->location?->name }}</dd></div>
            <div><dt class="text-xs text-slate-500">Owner</dt><dd class="text-sm text-slate-900">{{ $lead->assignee?->name ?? 'Nobody yet' }}</dd></div>
        </dl>
    </section>

    <form wire:submit="save" class="space-y-5" novalidate>
        <section class="card" aria-labelledby="sec-to">
            <div class="card-header"><h2 id="sec-to" class="text-sm font-semibold text-slate-900">To</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                    <label for="q-party" class="label">Customer name</label>
                    <input id="q-party" type="text" wire:model="q.party_name" class="input" maxlength="200" required>
                    @error('quotation.party_name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="q-contact" class="label">Contact person</label>
                    <input id="q-contact" type="text" wire:model="q.contact_person" class="input" maxlength="150">
                    @error('quotation.contact_person') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="q-mobile" class="label">Mobile</label>
                    <input id="q-mobile" type="text" inputmode="numeric" wire:model="q.mobile" class="input tabular-nums" maxlength="14">
                    @error('quotation.mobile') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-4">
                    <label for="q-email" class="label">E-mail <span class="font-normal text-slate-500">(the quotation is sent here)</span></label>
                    <input id="q-email" type="email" wire:model="q.email" class="input" maxlength="150">
                    @error('quotation.email') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="q-line1" class="label">Billing address</label>
                    <input id="q-line1" type="text" wire:model="q.bill_line1" class="input" maxlength="200" required>
                    @error('quotation.bill_line1') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="q-line2" class="label">Address, line 2</label>
                    <input id="q-line2" type="text" wire:model="q.bill_line2" class="input" maxlength="200">
                </div>
                <div class="sm:col-span-2">
                    <label for="q-city" class="label">City</label>
                    <input id="q-city" type="text" wire:model="q.bill_city" class="input" maxlength="100">
                </div>
                <div class="sm:col-span-2">
                    <label for="q-pin" class="label">PIN code</label>
                    <input id="q-pin" type="text" inputmode="numeric" wire:model="q.bill_pin_code" class="input tabular-nums" maxlength="6">
                    @error('quotation.bill_pin_code') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="q-gstin" class="label">Customer GSTIN</label>
                    <input id="q-gstin" type="text" wire:model="q.customer_gstin" class="input font-mono uppercase" maxlength="15" autocomplete="off">
                    @error('quotation.customer_gstin') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="sec-terms">
            <div class="card-header"><h2 id="sec-terms" class="text-sm font-semibold text-slate-900">Supply, prices and validity</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-2">
                    <label for="q-pos" class="label">Place of supply (state)</label>
                    <select id="q-pos" wire:model.live="q.place_of_supply_state_id" class="input" required>
                        <option value="">Select…</option>
                        @foreach ($states as $state)<option value="{{ $state->id }}">{{ $state->name }} ({{ $state->gst_code }})</option>@endforeach
                    </select>
                    <p class="help">The state the goods go to decides CGST + SGST or IGST.</p>
                    @error('quotation.place_of_supply_state_id') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="q-list" class="label">Price list</label>
                    <select id="q-list" wire:model.live="q.price_list_id" class="input">
                        <option value="">Standard rates</option>
                        @foreach ($priceLists as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    <p class="help">An item with no rate in the list is priced at the standard rate.</p>
                    @error('quotation.price_list_id') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="q-valid" class="label">Valid until</label>
                    <input id="q-valid" type="date" wire:model="q.valid_until" min="{{ $today }}" class="input" required>
                    @error('quotation.valid_until') <p class="error">{{ $message }}</p> @enderror
                </div>
                @if ($editing)
                    <div class="sm:col-span-6">
                        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model.live="q.reprice" value="1" class="rounded border-slate-300"> Refresh every line to today’s rates from the rate master (otherwise lines you have not changed keep the price they were saved with)</label>
                    </div>
                @endif
                <div class="sm:col-span-6">
                    <label for="q-terms" class="label">Terms and conditions</label>
                    <textarea id="q-terms" rows="3" wire:model="q.terms" class="input" maxlength="4000"></textarea>
                    @error('quotation.terms') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-6">
                    <label for="q-remarks" class="label">Internal remarks <span class="font-normal text-slate-500">(not printed)</span></label>
                    <textarea id="q-remarks" rows="2" wire:model="q.remarks" class="input" maxlength="2000"></textarea>
                    @error('quotation.remarks') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="sec-items">
            <div class="card-header">
                <h2 id="sec-items" class="text-sm font-semibold text-slate-900">Item lines</h2>
                @if (count($items) < $maxLines)<button type="button" wire:click="addRow" class="btn-secondary btn-sm">Add an item</button>@endif
            </div>
            <div class="card-body space-y-3">
                @error('items') <p class="error">{{ $message }}</p> @enderror

                @forelse ($items as $i => $row)
                    @php $priced = $preview['lines'][$i] ?? null; @endphp
                    <div class="grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-12" wire:key="row-{{ $row['key'] ?? $i }}">
                        <div class="sm:col-span-4">
                            @if (($row['item_id'] ?? '') !== '')
                                <span class="label">Item</span>
                                <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                    <span class="truncate text-slate-900">{{ ($row['item_label'] ?? '') !== '' ? $row['item_label'] : 'Item #'.$row['item_id'] }}</span>
                                    <button type="button" wire:click="clearItem({{ $i }})" class="shrink-0 text-xs text-brand-700 hover:underline">Change</button>
                                </div>
                            @else
                                <label for="r{{ $i }}-search" class="label">Find an item</label>
                                <input id="r{{ $i }}-search" type="search" wire:model.live.debounce.300ms="items.{{ $i }}.search" class="input" autocomplete="off" placeholder="Code, name or HSN…">
                                @if (! empty($results[$i]))
                                    <ul class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                                        @foreach ($results[$i] as $hit)
                                            <li><button type="button" wire:click="pickItem({{ $i }}, {{ $hit['id'] }})" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">{{ $hit['label'] }}</button></li>
                                        @endforeach
                                    </ul>
                                @elseif (trim((string) ($row['search'] ?? '')) !== '')
                                    <p class="help">Nothing found: only approved, active items can be chosen.</p>
                                @endif
                            @endif
                            @error("items.$i.item_id") <p class="error">{{ $message }}</p> @else @if (isset($preview['notes'][$i])) <p class="help text-amber-700">{{ $preview['notes'][$i] }}</p> @endif @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="r{{ $i }}-qty" class="label">Quantity</label>
                            <input id="r{{ $i }}-qty" type="text" inputmode="decimal" wire:model.live.debounce.600ms="items.{{ $i }}.quantity" class="input tabular-nums" autocomplete="off">
                            @error("items.$i.quantity") <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-3">
                            <label for="r{{ $i }}-unit" class="label">Unit</label>
                            <select id="r{{ $i }}-unit" wire:model.live="items.{{ $i }}.unit_id" class="input">
                                <option value="">—</option>
                                @foreach ($itemUnits[$i] ?? [] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                            @error("items.$i.unit_id") <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="r{{ $i }}-disc" class="label">Discount %</label>
                            <input id="r{{ $i }}-disc" type="text" inputmode="decimal" wire:model.live.debounce.600ms="items.{{ $i }}.discount_percent" class="input tabular-nums" autocomplete="off">
                            @error("items.$i.discount_percent") <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex items-end justify-end sm:col-span-1">
                            <button type="button" wire:click="removeRow({{ $i }})" class="btn-ghost btn-sm text-rose-600" aria-label="Remove item {{ $i + 1 }}">Remove</button>
                        </div>

                        <div class="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600 sm:col-span-12">
                            @if ($priced)
                                <span>Rate from the master: <strong class="tabular-nums text-slate-900">₹ {{ Format::number($priced['list_rate'], 2) }}</strong> ({{ $priced['rate_source'] === 'price_list' ? 'price list' : 'standard' }})</span>
                                @if ($priced['rate_overridden'])<span class="ml-3">Quoted at <strong class="tabular-nums text-slate-900">₹ {{ Format::number($priced['rate'], 2) }}</strong></span>@endif
                                <span class="ml-3">GST {{ rtrim(rtrim($priced['gst_rate'], '0'), '.') }}%</span>
                                <span class="ml-3">Line total <strong class="tabular-nums text-slate-900">₹ {{ Format::number($priced['figures']['total'], 2) }}</strong></span>
                            @else
                                <span>The rate and the tax appear here once the item and quantity are filled in.</span>
                            @endif
                        </div>

                        @if ($canOverride || ($row['rate_override'] ?? '') !== '')
                            <div class="sm:col-span-3">
                                <label for="r{{ $i }}-ovr" class="label">Quote at a different rate (₹) <span class="font-normal text-slate-500">(optional)</span></label>
                                <input id="r{{ $i }}-ovr" type="text" inputmode="decimal" wire:model.live.debounce.600ms="items.{{ $i }}.rate_override" class="input tabular-nums" autocomplete="off" @disabled(! $canOverride)>
                                @error("items.$i.rate_override") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-9">
                                <label for="r{{ $i }}-why" class="label">Why is the rate different?</label>
                                <input id="r{{ $i }}-why" type="text" wire:model="items.{{ $i }}.override_reason" class="input" maxlength="1000" @disabled(! $canOverride)>
                                <p class="help">Recorded against the rate in the audit log.</p>
                                @error("items.$i.override_reason") <p class="error">{{ $message }}</p> @enderror
                            </div>
                        @endif
                        @error("items.$i.id") <p class="error sm:col-span-12">{{ $message }}</p> @enderror
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No lines yet. Add at least one item.</p>
                @endforelse
            </div>
        </section>

        @if ($preview['totals'])
            @php $t = $preview['totals']; $inter = $preview['supply'] === 'inter'; @endphp
            <section class="card" aria-labelledby="sec-totals">
                <div class="card-header"><h2 id="sec-totals" class="text-sm font-semibold text-slate-900">Totals <span class="font-normal text-slate-500">(worked out again when you save)</span></h2></div>
                <dl class="card-body ml-auto grid max-w-sm grid-cols-2 gap-y-1 text-sm">
                    <dt class="text-slate-600">Before discount</dt><dd class="text-right tabular-nums">₹ {{ Format::number($t['gross'], 2) }}</dd>
                    @if (bccomp($t['discount'], '0', 2) > 0)<dt class="text-slate-600">Discount</dt><dd class="text-right tabular-nums">– ₹ {{ Format::number($t['discount'], 2) }}</dd>@endif
                    <dt class="text-slate-600">Taxable value</dt><dd class="text-right tabular-nums">₹ {{ Format::number($t['taxable'], 2) }}</dd>
                    @if ($inter)
                        <dt class="text-slate-600">IGST</dt><dd class="text-right tabular-nums">₹ {{ Format::number($t['igst'], 2) }}</dd>
                    @else
                        <dt class="text-slate-600">CGST</dt><dd class="text-right tabular-nums">₹ {{ Format::number($t['cgst'], 2) }}</dd>
                        <dt class="text-slate-600">SGST</dt><dd class="text-right tabular-nums">₹ {{ Format::number($t['sgst'], 2) }}</dd>
                    @endif
                    @if (bccomp($t['round_off'], '0', 2) !== 0)<dt class="text-slate-600">Round off</dt><dd class="text-right tabular-nums">{{ $t['round_off'] }}</dd>@endif
                    <dt class="border-t border-slate-200 pt-1 font-semibold text-slate-900">Total</dt><dd class="border-t border-slate-200 pt-1 text-right font-semibold tabular-nums text-slate-900">₹ {{ Format::number($t['grand_total'], 2) }}</dd>
                </dl>
            </section>
        @endif

        <div class="flex justify-end gap-3">
            <a href="{{ $backUrl }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $editing ? 'Save quotation' : 'Make the quotation' }}</button>
        </div>
    </form>
</div>

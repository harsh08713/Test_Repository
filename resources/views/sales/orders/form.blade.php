@php use App\Modules\Shared\Support\Format; @endphp
<div class="mx-auto max-w-6xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $editing ? 'Edit sales order' : 'Make a sales order' }}@if ($orderNumber) <span class="font-mono text-base font-normal text-slate-500">{{ $orderNumber }}</span>@endif</h1>
            <p class="mt-1 text-sm text-slate-500">
                @if ($editing)
                    An order can be changed only while it is a draft or was returned. Once it is approved its invoice is made.
                @elseif ($sourceType === 'quotation')
                    Each line keeps the price it was quoted at (unless the company prices orders from the rate master again). Add the customer’s order number, check the addresses and the delivery date.
                @elseif ($sourceType === 'deal')
                    The deal’s lines are priced from the rate master. Add the customer’s order number, check the addresses and the delivery date.
                @else
                    A direct order is for a regular customer, with no deal or quotation. Choose the customer and the branch first; every line is priced from the rate master.
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
            @if ($quotation)
                <div><dt class="text-xs text-slate-500">Quotation</dt><dd class="text-sm text-slate-900"><a href="{{ route('sales.quotations.show', $quotation) }}" wire:navigate class="font-mono text-xs text-brand-700 hover:underline">{{ $quotation->displayNumber() }}</a></dd></div>
            @elseif ($deal)
                <div><dt class="text-xs text-slate-500">Deal</dt><dd class="text-sm text-slate-900"><a href="{{ route('crm.deals.show', $deal) }}" wire:navigate class="font-mono text-xs text-brand-700 hover:underline">{{ $deal->deal_number }}</a> · {{ $deal->party_name }}</dd></div>
            @else
                <div><dt class="text-xs text-slate-500">Source</dt><dd class="text-sm text-slate-900">{{ $sourceType === 'direct' ? 'Direct order' : '—' }}</dd></div>
            @endif
            <div class="sm:col-span-2">
                <span class="label">Customer</span>
                @if ($customerId !== null)
                    <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                        <span class="truncate text-slate-900">{{ $customerLabel }}</span>
                        @if (! $editing && ($sourceType === 'direct'))<button type="button" wire:click="clearCustomer" class="shrink-0 text-xs text-brand-700 hover:underline">Change</button>@endif
                    </div>
                @else
                    <label for="so-cust" class="sr-only">Find a customer</label>
                    <input id="so-cust" type="search" wire:model.live.debounce.300ms="customerSearch" class="input" autocomplete="off" placeholder="Find an approved customer by name or code…">
                    @if (! empty($customers))
                        <ul class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                            @foreach ($customers as $hit)
                                <li><button type="button" wire:click="pickCustomer({{ $hit['id'] }})" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">{{ $hit['label'] }}</button></li>
                            @endforeach
                        </ul>
                    @elseif (trim($customerSearch) !== '')
                        <p class="help">Nothing found: only approved, active customers can be chosen.</p>
                    @else
                        <p class="help">An order is for an approved customer ledger.</p>
                    @endif
                @endif
            </div>
            @if ($sourceType === 'direct' && ! $editing)
                <div>
                    <label for="so-branch" class="label">Branch</label>
                    <select id="so-branch" wire:model.live="locationId" class="input">
                        <option value="">Select…</option>
                        @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </div>
            @endif
        </dl>
    </section>

    @if (count($softBlocks) > 0)
        <section class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" aria-labelledby="sec-exception">
            <h2 id="sec-exception" class="font-semibold">This quotation is not in the usual state to order from</h2>
            <ul class="mt-1 list-disc pl-5">@foreach ($softBlocks as $why)<li>{{ $why }}</li>@endforeach</ul>
            <p class="mt-2">You are allowed to order anyway. Say why — it is kept on the order and in the audit log.</p>
            <label for="so-exception" class="label mt-2">Reason</label>
            <input id="so-exception" type="text" wire:model="exceptionReason" class="input" maxlength="500">
        </section>
    @endif

    @if ($customerId !== null)
    <form wire:submit="save" class="space-y-5" novalidate>
        <section class="card" aria-labelledby="sec-order">
            <div class="card-header"><h2 id="sec-order" class="text-sm font-semibold text-slate-900">Delivery, the customer’s order and prices</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-2">
                    <label for="o-delivery" class="label">Delivery by</label>
                    <input id="o-delivery" type="date" wire:model="o.delivery_date" min="{{ $today }}" class="input" required>
                    @error('order.delivery_date') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="o-po" class="label">Customer’s order number <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="o-po" type="text" wire:model="o.customer_po_number" class="input" maxlength="60">
                    @error('order.customer_po_number') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="o-podate" class="label">Customer’s order date</label>
                    <input id="o-podate" type="date" wire:model="o.customer_po_date" max="{{ $today }}" class="input">
                    @error('order.customer_po_date') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="o-pos" class="label">Place of supply (state)</label>
                    <select id="o-pos" wire:model.live="o.place_of_supply_state_id" class="input" required>
                        <option value="">Select…</option>
                        @foreach ($states as $state)<option value="{{ $state->id }}">{{ $state->name }} ({{ $state->gst_code }})</option>@endforeach
                    </select>
                    <p class="help">The state the goods go to decides CGST + SGST or IGST.</p>
                    @error('order.place_of_supply_state_id') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="o-list" class="label">Price list</label>
                    <select id="o-list" wire:model.live="o.price_list_id" class="input">
                        <option value="">Standard rates</option>
                        @foreach ($priceLists as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('order.price_list_id') <p class="error">{{ $message }}</p> @enderror
                </div>
                @if ($editing)
                    <div class="sm:col-span-2 flex items-end">
                        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model.live="o.reprice" value="1" class="rounded border-slate-300"> Refresh every line to today’s rates</label>
                    </div>
                @endif
            </div>
        </section>

        <section class="card" aria-labelledby="sec-to">
            <div class="card-header"><h2 id="sec-to" class="text-sm font-semibold text-slate-900">Bill to and ship to</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                    <label for="o-party" class="label">Customer name</label>
                    <input id="o-party" type="text" wire:model="o.party_name" class="input" maxlength="200" required>
                    @error('order.party_name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="o-contact" class="label">Contact person</label>
                    <input id="o-contact" type="text" wire:model="o.contact_person" class="input" maxlength="150">
                </div>
                <div class="sm:col-span-2">
                    <label for="o-mobile" class="label">Mobile</label>
                    <input id="o-mobile" type="text" inputmode="numeric" wire:model="o.mobile" class="input tabular-nums" maxlength="14">
                    @error('order.mobile') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-4">
                    <label for="o-email" class="label">E-mail</label>
                    <input id="o-email" type="email" wire:model="o.email" class="input" maxlength="150">
                    @error('order.email') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="o-bill1" class="label">Billing address</label>
                    <input id="o-bill1" type="text" wire:model="o.bill_line1" class="input" maxlength="200" required>
                    @error('order.bill_line1') <p class="error">{{ $message }}</p> @enderror
                    <input type="text" wire:model="o.bill_line2" class="input mt-2" maxlength="200" aria-label="Billing address, line 2">
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <input type="text" wire:model="o.bill_city" class="input" maxlength="100" placeholder="City" aria-label="Billing city">
                        <input type="text" inputmode="numeric" wire:model="o.bill_pin_code" class="input tabular-nums" maxlength="6" placeholder="PIN code" aria-label="Billing PIN code">
                    </div>
                    @error('order.bill_pin_code') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="o-ship1" class="label">Shipping address <span class="font-normal text-slate-500">(blank = same as billing)</span></label>
                    <input id="o-ship1" type="text" wire:model="o.ship_line1" class="input" maxlength="200">
                    <input type="text" wire:model="o.ship_line2" class="input mt-2" maxlength="200" aria-label="Shipping address, line 2">
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <input type="text" wire:model="o.ship_city" class="input" maxlength="100" placeholder="City" aria-label="Shipping city">
                        <input type="text" inputmode="numeric" wire:model="o.ship_pin_code" class="input tabular-nums" maxlength="6" placeholder="PIN code" aria-label="Shipping PIN code">
                    </div>
                    @error('order.ship_pin_code') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="o-gstin" class="label">Customer GSTIN</label>
                    <input id="o-gstin" type="text" wire:model="o.customer_gstin" class="input font-mono uppercase" maxlength="15" autocomplete="off">
                    @error('order.customer_gstin') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-6">
                    <label for="o-terms" class="label">Terms and conditions</label>
                    <textarea id="o-terms" rows="3" wire:model="o.terms" class="input" maxlength="4000"></textarea>
                    @error('order.terms') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-6">
                    <label for="o-remarks" class="label">Internal remarks <span class="font-normal text-slate-500">(not printed)</span></label>
                    <textarea id="o-remarks" rows="2" wire:model="o.remarks" class="input" maxlength="2000"></textarea>
                    @error('order.remarks') <p class="error">{{ $message }}</p> @enderror
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
                                <span>Rate: <strong class="tabular-nums text-slate-900">₹ {{ Format::number($priced['rate'], 2) }}</strong> ({{ $priced['rate_source'] === 'price_list' ? 'price list' : 'standard' }}@if ($priced['rate_overridden']), changed from ₹ {{ Format::number($priced['list_rate'], 2) }}@endif)</span>
                                <span class="ml-3">GST {{ rtrim(rtrim($priced['gst_rate'], '0'), '.') }}%</span>
                                <span class="ml-3">Line total <strong class="tabular-nums text-slate-900">₹ {{ Format::number($priced['figures']['total'], 2) }}</strong></span>
                            @else
                                <span>The rate and the tax appear here once the item and quantity are filled in.</span>
                            @endif
                        </div>

                        @if ($canOverride || ($row['rate_override'] ?? '') !== '')
                            <div class="sm:col-span-3">
                                <label for="r{{ $i }}-ovr" class="label">Order at a different rate (₹) <span class="font-normal text-slate-500">(optional)</span></label>
                                <input id="r{{ $i }}-ovr" type="text" inputmode="decimal" wire:model.live.debounce.600ms="items.{{ $i }}.rate_override" class="input tabular-nums" autocomplete="off" @disabled(! $canOverride)>
                                @error("items.$i.rate_override") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-9">
                                <label for="r{{ $i }}-why" class="label">Why is the rate different?</label>
                                <input id="r{{ $i }}-why" type="text" wire:model="items.{{ $i }}.override_reason" class="input" maxlength="1000" @disabled(! $canOverride)>
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
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $editing ? 'Save order' : 'Make the order' }}</button>
        </div>
    </form>
    @endif
</div>

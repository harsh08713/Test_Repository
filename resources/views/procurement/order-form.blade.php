@php use App\Modules\Shared\Support\Format; @endphp
<div class="mx-auto max-w-5xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('procurement.orders.index') }}" wire:navigate class="hover:text-brand-700">Purchase orders</a></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $editing ? 'Change' : 'New' }} purchase order</h1>
        <p class="mt-1 text-sm text-slate-500">Only an approved, active supplier and approved, active items can be chosen. Goods are received against the order once it is approved.</p>
    </div>

    @error('form')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <form wire:submit="save" class="space-y-5" novalidate>
        <section class="card" aria-labelledby="s-head">
            <div class="card-header"><h2 id="s-head" class="text-sm font-semibold text-slate-900">Supplier and delivery</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    @if ($supplierId !== '')
                        <span class="label">Supplier</span>
                        <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                            <span class="truncate text-slate-900">{{ $supplierLabel }}</span>
                            <button type="button" wire:click="clearSupplier" class="shrink-0 text-xs text-brand-700 hover:underline">Change</button>
                        </div>
                    @else
                        <label for="po-sup" class="label">Find the supplier</label>
                        <input id="po-sup" type="search" wire:model.live.debounce.300ms="supplierSearch" class="input" autocomplete="off" placeholder="Code or name…">
                        @if ($supplierResults->isNotEmpty())
                            <ul class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                                @foreach ($supplierResults as $hit)
                                    <li><button type="button" wire:click="pickSupplier({{ $hit->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">{{ trim($hit->code.' · '.$hit->name) }}</button></li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                    @error('supplier_ledger_id')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="po-loc" class="label">Receive at branch</label>
                    <select id="po-loc" wire:model="locationId" class="input">
                        <option value="">Select…</option>
                        @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('location_id')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="po-date" class="label">Order date</label>
                        <input id="po-date" type="date" wire:model="poDate" max="{{ now()->toDateString() }}" class="input">
                        @error('po_date')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="po-exp" class="label">Expected on</label>
                        <input id="po-exp" type="date" wire:model="expectedDate" class="input">
                        <p class="help">Blank: today plus the supplier’s lead time.</p>
                        @error('expected_date')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="s-items">
            <div class="card-header">
                <h2 id="s-items" class="text-sm font-semibold text-slate-900">Items</h2>
                @if (count($rows) < $max)<button type="button" wire:click="addRow" class="btn-secondary btn-sm">Add an item</button>@endif
            </div>
            <div class="card-body space-y-3">
                @error('items')<p class="error">{{ $message }}</p>@enderror
                @foreach ($rows as $i => $row)
                    <div class="grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-12" wire:key="pr-{{ $row['key'] }}">
                        <div class="sm:col-span-5">
                            @if (($row['item_id'] ?? '') !== '')
                                <span class="label">Item</span>
                                <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                    <span class="truncate text-slate-900">{{ $row['item_label'] }}</span>
                                    <button type="button" wire:click="clearItem({{ $i }})" class="shrink-0 text-xs text-brand-700 hover:underline">Change</button>
                                </div>
                            @else
                                <label for="pr{{ $i }}-s" class="label">Find an item</label>
                                <input id="pr{{ $i }}-s" type="search" wire:model.live.debounce.300ms="rows.{{ $i }}.search" class="input" autocomplete="off" placeholder="Code, name or HSN…">
                                @if (! empty($results[$i]))
                                    <ul class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                                        @foreach ($results[$i] as $hit)
                                            <li><button type="button" wire:click="pickItem({{ $i }}, {{ $hit['id'] }})" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">{{ $hit['label'] }}</button></li>
                                        @endforeach
                                    </ul>
                                @endif
                            @endif
                            @error("items.$i.item_id")<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="pr{{ $i }}-q" class="label">Quantity</label>
                            <input id="pr{{ $i }}-q" type="text" inputmode="decimal" wire:model.live.debounce.600ms="rows.{{ $i }}.quantity" class="input tabular-nums" autocomplete="off">
                            @error("items.$i.quantity")<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="pr{{ $i }}-u" class="label">Unit</label>
                            <select id="pr{{ $i }}-u" wire:model="rows.{{ $i }}.unit_id" class="input">
                                <option value="">—</option>
                                @foreach ($itemUnits[$i] ?? [] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                            @error("items.$i.unit_id")<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="pr{{ $i }}-r" class="label">Rate (₹)</label>
                            <input id="pr{{ $i }}-r" type="text" inputmode="decimal" wire:model.live.debounce.600ms="rows.{{ $i }}.rate" class="input tabular-nums" autocomplete="off">
                            @error("items.$i.rate")<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div class="flex items-end sm:col-span-1">
                            @if (count($rows) > 1)<button type="button" wire:click="removeRow({{ $i }})" class="btn-ghost btn-sm text-rose-600">Remove</button>@endif
                        </div>
                    </div>
                @endforeach
                <p class="text-right text-sm text-slate-600">Total before tax: <span class="font-semibold tabular-nums text-slate-900">₹ {{ Format::number($total, 2) }}</span> <span class="text-xs text-slate-500">(GST is not worked out on purchase orders yet)</span></p>
            </div>
        </section>

        <section class="card" aria-labelledby="s-notes">
            <div class="card-header"><h2 id="s-notes" class="text-sm font-semibold text-slate-900">Terms and notes</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="po-terms" class="label">Terms</label>
                    <textarea id="po-terms" rows="3" wire:model="terms" class="input" maxlength="2000"></textarea>
                    @error('terms')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="po-rem" class="label">Remarks</label>
                    <textarea id="po-rem" rows="3" wire:model="remarks" class="input" maxlength="1000"></textarea>
                    @error('remarks')<p class="error">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ route('procurement.orders.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save as draft</button>
        </div>
    </form>
</div>

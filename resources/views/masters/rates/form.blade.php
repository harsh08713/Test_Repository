<div class="mx-auto max-w-3xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $editing ? 'Edit rate' : 'New rate' }}</h1>
            <p class="mt-1 text-sm text-slate-500">A rate is for one item, in one unit, at one branch, for a period. It must be approved before a quotation or order can use it. Rates are exclusive of GST.</p>
        </div>
        <a href="{{ $editing ? route('masters.rates.show', $rateId) : route('masters.rates.index') }}" wire:navigate class="btn-secondary">Cancel</a>
    </div>

    @if ($errors->any())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">
            @error('form'){{ $message }}@else Some details need attention. Please check the fields marked below.@enderror
        </div>
    @endif

    @if ($approvedBefore)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            This rate has been approved before, so the item, branch, unit and price list can no longer be changed. You can correct the rate and its dates; add a new rate for anything else.
        </div>
    @endif

    <form wire:submit="save" class="space-y-5" novalidate>
        <section class="card" aria-labelledby="sec-what">
            <div class="card-header"><h2 id="sec-what" class="text-sm font-semibold text-slate-900">What and where</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="r-item-search" class="label">Item</label>
                    @unless ($approvedBefore)
                        <input id="r-item-search" type="search" wire:model.live.debounce.300ms="itemSearch" placeholder="Type to narrow the list: code, name or alias…" class="input mb-2" autocomplete="off">
                    @endunless
                    <select id="r-item" wire:model.live="rate.item_id" class="input" aria-label="Item" @disabled($approvedBefore) required>
                        <option value="">Select an item…</option>
                        @foreach ($items as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                    </select>
                    <p class="help">Only approved, active items can be given a rate.</p>
                    @error('rate.item_id') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="r-unit" class="label">Unit</label>
                    <select id="r-unit" wire:model="rate.unit_id" class="input" @disabled($approvedBefore || $selectedItem === null) required>
                        <option value="">{{ $selectedItem === null ? 'Choose the item first' : 'Select…' }}</option>
                        @foreach ($units as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                    </select>
                    <p class="help">The rate is per one of this unit. A rate in Box is separate from a rate in Nos.</p>
                    @error('rate.unit_id') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="r-branch" class="label">Branch</label>
                    <select id="r-branch" wire:model="rate.location_id" class="input" @disabled($approvedBefore) required>
                        <option value="">Select…</option>
                        @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('rate.location_id') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="r-list" class="label">Price list <span class="font-normal text-slate-500">(optional)</span></label>
                    <select id="r-list" wire:model="rate.price_list_id" class="input" @disabled($approvedBefore)>
                        <option value="">Standard rate (no price list)</option>
                        @foreach ($priceLists as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    <p class="help">Leave blank for the standard rate. A price list's own rate is used for its customers; where it has none, the standard rate applies.</p>
                    @error('rate.price_list_id') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="sec-price">
            <div class="card-header"><h2 id="sec-price" class="text-sm font-semibold text-slate-900">Price and period</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="r-rate" class="label">Rate (₹)</label>
                    <input id="r-rate" type="text" inputmode="decimal" wire:model="rate.rate" class="input tabular-nums" autocomplete="off" placeholder="e.g. 125.50" required>
                    <p class="help">Up to 4 decimals; more than zero.</p>
                    @error('rate.rate') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="r-from" class="label">Effective from</label>
                    <input id="r-from" type="date" wire:model="rate.effective_from" class="input" required>
                    @error('rate.effective_from') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="r-to" class="label">Effective to <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="r-to" type="date" wire:model="rate.effective_to" class="input">
                    <p class="help">The last day it applies. Blank means until further notice.</p>
                    @error('rate.effective_to') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="r-remarks" class="label">Remarks <span class="font-normal text-slate-500">(optional)</span></label>
                    <textarea id="r-remarks" rows="2" wire:model="rate.remarks" class="input" maxlength="1000"></textarea>
                    @error('rate.remarks') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ $editing ? route('masters.rates.show', $rateId) : route('masters.rates.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $editing ? 'Save rate' : 'Add rate' }}</button>
        </div>
    </form>
</div>

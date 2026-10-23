<div class="mx-auto max-w-4xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('production.boms.index') }}" wire:navigate class="hover:text-brand-700">Bills of materials</a></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $editing ? 'Change' : 'New' }} bill of materials</h1>
        <p class="mt-1 text-sm text-slate-500">All quantities are in each item’s base unit. Only approved, active items can be chosen. Wastage is added to what an order requires.</p>
    </div>

    @error('form')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <form wire:submit="save" class="space-y-5" novalidate>
        <section class="card" aria-labelledby="s-head">
            <div class="card-header"><h2 id="s-head" class="text-sm font-semibold text-slate-900">What it makes</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="bm-code" class="label">Code</label>
                    <input id="bm-code" type="text" wire:model="code" class="input uppercase" maxlength="30" placeholder="BOM-BOLT-01">
                    @error('code')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    @if ($itemId !== '')
                        <span class="label">Finished item</span>
                        <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                            <span class="truncate text-slate-900">{{ $itemLabel }}</span>
                            <button type="button" wire:click="clearItem" class="shrink-0 text-xs text-brand-700 hover:underline">Change</button>
                        </div>
                    @else
                        <label for="bm-item" class="label">Find the item it makes</label>
                        <input id="bm-item" type="search" wire:model.live.debounce.300ms="itemSearch" class="input" autocomplete="off" placeholder="Code or name…">
                        @if ($itemResults->isNotEmpty())
                            <ul class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                                @foreach ($itemResults as $hit)<li><button type="button" wire:click="pickItem({{ $hit->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">{{ trim($hit->code.' · '.$hit->name) }}</button></li>@endforeach
                            </ul>
                        @endif
                    @endif
                    @error('item_id')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="bm-out" class="label">Makes (base units)</label>
                    <input id="bm-out" type="text" inputmode="decimal" wire:model="outputQuantity" class="input tabular-nums">
                    <p class="help">The components below make this much.</p>
                    @error('output_quantity')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="bm-rem" class="label">Remarks</label>
                    <input id="bm-rem" type="text" wire:model="remarks" class="input" maxlength="1000">
                    @error('remarks')<p class="error">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="s-items">
            <div class="card-header">
                <h2 id="s-items" class="text-sm font-semibold text-slate-900">Components</h2>
                @if (count($rows) < $max)<button type="button" wire:click="addRow" class="btn-secondary btn-sm">Add a component</button>@endif
            </div>
            <div class="card-body space-y-3">
                @error('items')<p class="error">{{ $message }}</p>@enderror
                @foreach ($rows as $i => $row)
                    <div class="grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-12" wire:key="br-{{ $row['key'] }}">
                        <div class="sm:col-span-6">
                            @if (($row['item_id'] ?? '') !== '')
                                <span class="label">Component</span>
                                <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                    <span class="truncate text-slate-900">{{ $row['item_label'] }}</span>
                                    <button type="button" wire:click="clearComponent({{ $i }})" class="shrink-0 text-xs text-brand-700 hover:underline">Change</button>
                                </div>
                            @else
                                <label for="br{{ $i }}-s" class="label">Find a component</label>
                                <input id="br{{ $i }}-s" type="search" wire:model.live.debounce.300ms="rows.{{ $i }}.search" class="input" autocomplete="off" placeholder="Code or name…">
                                @if (! empty($results[$i]))
                                    <ul class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                                        @foreach ($results[$i] as $hit)<li><button type="button" wire:click="pickComponent({{ $i }}, {{ $hit['id'] }})" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">{{ $hit['label'] }}</button></li>@endforeach
                                    </ul>
                                @endif
                            @endif
                            @error("items.$i.item_id")<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-3">
                            <label for="br{{ $i }}-q" class="label">Quantity</label>
                            <input id="br{{ $i }}-q" type="text" inputmode="decimal" wire:model="rows.{{ $i }}.quantity" class="input tabular-nums" autocomplete="off">
                            @error("items.$i.quantity")<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="br{{ $i }}-w" class="label">Wastage %</label>
                            <input id="br{{ $i }}-w" type="text" inputmode="decimal" wire:model="rows.{{ $i }}.scrap_percent" class="input tabular-nums" autocomplete="off">
                            @error("items.$i.scrap_percent")<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div class="flex items-end sm:col-span-1">
                            @if (count($rows) > 1)<button type="button" wire:click="removeRow({{ $i }})" class="btn-ghost btn-sm text-rose-600">Remove</button>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ route('production.boms.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save as draft</button>
        </div>
    </form>
</div>

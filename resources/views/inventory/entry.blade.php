@php $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
<div class="mx-auto max-w-3xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('inventory.stock') }}" wire:navigate class="hover:text-brand-700">Stock on hand</a></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">Stock entry</h1>
        <p class="mt-1 text-sm text-slate-500">For a count difference, scrap, or a move between branches. Goods that arrive from a supplier come in through a goods receipt. Every entry needs a reason and stays in the stock ledger for good.</p>
    </div>

    @error('form')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <form wire:submit="save" class="card" novalidate>
        <div class="card-body grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="e-type" class="label">What happened</label>
                <select id="e-type" wire:model.live="type" class="input">
                    @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </div>

            <div class="sm:col-span-2">
                @if ($item)
                    <span class="label">Item</span>
                    <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                        <span class="truncate text-slate-900">{{ $itemLabel }}</span>
                        <button type="button" wire:click="clearItem" class="shrink-0 text-xs text-brand-700 hover:underline">Change</button>
                    </div>
                @else
                    <label for="e-search" class="label">Find the item</label>
                    <input id="e-search" type="search" wire:model.live.debounce.300ms="search" class="input" autocomplete="off" placeholder="Code, name or HSN…">
                    @if ($results->isNotEmpty())
                        <ul class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                            @foreach ($results as $hit)
                                <li><button type="button" wire:click="pickItem({{ $hit->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">{{ trim($hit->code.' · '.$hit->name) }}</button></li>
                            @endforeach
                        </ul>
                    @endif
                @endif
                @error('itemId')<p class="error">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="e-from" class="label">{{ $type === 'transfer' ? 'From branch' : 'Branch' }}</label>
                <select id="e-from" wire:model.live="locationId" class="input">
                    <option value="">Select…</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                @if ($onHand !== null)<p class="help">On hand there: {{ $qty($onHand) }} {{ $item?->baseUnit?->code }}</p>@endif
                @error('locationId')<p class="error">{{ $message }}</p>@enderror
            </div>

            @if ($type === 'transfer')
                <div>
                    <label for="e-to" class="label">To branch</label>
                    <select id="e-to" wire:model="toLocationId" class="input">
                        <option value="">Select…</option>
                        @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </div>
            @endif

            @if ($item && $item->tracking === 'batch')
                <div>
                    <label for="e-batch" class="label">Batch</label>
                    <select id="e-batch" wire:model="batchId" class="input">
                        <option value="">Select…</option>
                        @foreach ($batches as $b)<option value="{{ $b->id }}">{{ $b->batch_no }}{{ $b->expiry_date ? ' · exp '.$b->expiry_date->format('d-m-Y') : '' }}</option>@endforeach
                    </select>
                    <p class="help">New batches arrive through a goods receipt.</p>
                    @error('batchId')<p class="error">{{ $message }}</p>@enderror
                </div>
            @endif

            <div>
                <label for="e-qty" class="label">Quantity{{ $item ? ' ('.$item->baseUnit?->code.')' : '' }}</label>
                <input id="e-qty" type="text" inputmode="decimal" wire:model="quantity" class="input tabular-nums" autocomplete="off">
            </div>

            @if ($type === 'adjust_in')
                <div>
                    <label for="e-cost" class="label">Cost per unit (₹, optional)</label>
                    <input id="e-cost" type="text" inputmode="decimal" wire:model="unitCost" class="input tabular-nums" autocomplete="off">
                    <p class="help">Left blank, the current average (or the item’s standard cost) is used.</p>
                </div>
            @endif

            <div class="sm:col-span-2">
                <label for="e-reason" class="label">Reason</label>
                <textarea id="e-reason" rows="2" wire:model="reason" class="input" maxlength="500"></textarea>
            </div>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
            <a href="{{ route('inventory.stock') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Record the entry</button>
        </div>
    </form>
</div>

<div class="mx-auto max-w-3xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('quality.ncrs.index') }}" wire:navigate class="hover:text-brand-700">Non-conformances</a></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">Raise a non-conformance report</h1>
        <p class="mt-1 text-sm text-slate-500">For something found outside an inspection: a complaint, a find on the shop floor, a count. A rejected inspection raises its own report.</p>
    </div>

    <form wire:submit="save" class="card" novalidate>
        <div class="card-body grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                @if ($itemId !== '')
                    <span class="label">Item</span>
                    <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                        <span class="truncate text-slate-900">{{ $itemLabel }}</span>
                        <button type="button" wire:click="clearItem" class="shrink-0 text-xs text-brand-700 hover:underline">Change</button>
                    </div>
                @else
                    <label for="nc-item" class="label">Find the item</label>
                    <input id="nc-item" type="search" wire:model.live.debounce.300ms="itemSearch" class="input" autocomplete="off" placeholder="Code or name…">
                    @if ($itemResults->isNotEmpty())
                        <ul class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                            @foreach ($itemResults as $hit)<li><button type="button" wire:click="pickItem({{ $hit->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">{{ trim($hit->code.' · '.$hit->name) }}</button></li>@endforeach
                        </ul>
                    @endif
                @endif
                @error('item_id')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="nc-loc" class="label">Branch</label>
                <select id="nc-loc" wire:model="locationId" class="input"><option value="">Select…</option>@foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                @error('location_id')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="nc-sev" class="label">How serious</label>
                <select id="nc-sev" wire:model="severity" class="input">@foreach ($severities as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>
                @error('severity')<p class="error">{{ $message }}</p>@enderror
            </div>
            @if ($batches !== [])
                <div>
                    <label for="nc-batch" class="label">Batch (if known)</label>
                    <select id="nc-batch" wire:model="batchId" class="input"><option value="">None</option>@foreach ($batches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    @error('batch_id')<p class="error">{{ $message }}</p>@enderror
                </div>
            @endif
            <div>
                <label for="nc-qty" class="label">Quantity affected</label>
                <input id="nc-qty" type="text" inputmode="decimal" wire:model="quantity" class="input tabular-nums" autocomplete="off">
                @error('quantity_affected')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div class="sm:col-span-2">
                <label for="nc-desc" class="label">What went wrong</label>
                <textarea id="nc-desc" rows="4" wire:model="description" class="input" maxlength="2000"></textarea>
                @error('description')<p class="error">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
            <a href="{{ route('quality.ncrs.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Raise it</button>
        </div>
    </form>
</div>

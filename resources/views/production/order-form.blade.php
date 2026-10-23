<div class="mx-auto max-w-3xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('production.orders.index') }}" wire:navigate class="hover:text-brand-700">Production orders</a></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $editing ? 'Change' : 'New' }} production order</h1>
        <p class="mt-1 text-sm text-slate-500">Only an approved, active bill of materials can be used. What the order requires is copied from the bill when you save, scaled to the quantity.</p>
    </div>

    @error('form')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <form wire:submit="save" class="card" novalidate>
        <div class="card-body grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="mo-bom" class="label">Bill of materials</label>
                <select id="mo-bom" wire:model="bomId" class="input"><option value="">Select…</option>@foreach ($boms as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                @error('bom_id')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="mo-loc" class="label">Make at branch</label>
                <select id="mo-loc" wire:model="locationId" class="input"><option value="">Select…</option>@foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                @error('location_id')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="mo-qty" class="label">Quantity to make (base units)</label>
                <input id="mo-qty" type="text" inputmode="decimal" wire:model="plannedQuantity" class="input tabular-nums" autocomplete="off">
                @error('planned_quantity')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="mo-from" class="label">Start</label>
                <input id="mo-from" type="date" wire:model="plannedStart" class="input">
                @error('planned_start')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="mo-to" class="label">Finish by</label>
                <input id="mo-to" type="date" wire:model="plannedEnd" class="input">
                <p class="help">Blank: the start date.</p>
                @error('planned_end')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div class="sm:col-span-2">
                <label for="mo-rem" class="label">Remarks</label>
                <textarea id="mo-rem" rows="2" wire:model="remarks" class="input" maxlength="1000"></textarea>
                @error('remarks')<p class="error">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
            <a href="{{ route('production.orders.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save as draft</button>
        </div>
    </form>
</div>

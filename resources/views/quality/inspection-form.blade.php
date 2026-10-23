<div class="mx-auto max-w-3xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('quality.inspections.index') }}" wire:navigate class="hover:text-brand-700">Inspections</a></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">Start an inspection</h1>
        <p class="mt-1 text-sm text-slate-500">Only an approved, active plan can be used. An item tracked by batch is inspected batch by batch, and the batch is held from now until the inspection is decided.</p>
    </div>

    @error('form')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <form wire:submit="save" class="card" novalidate>
        <div class="card-body grid gap-4 sm:grid-cols-2">
            @if ($sourceLabel !== '')<p class="sm:col-span-2 text-sm text-slate-600">For {{ $sourceType === 'grn' ? 'goods receipt' : 'production order' }} <span class="font-mono">{{ $sourceLabel }}</span>.</p>@endif
            <div class="sm:col-span-2">
                <label for="qi-plan" class="label">Inspection plan</label>
                <select id="qi-plan" wire:model.live="planId" class="input"><option value="">Select…</option>@foreach ($plans as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                @error('inspection_plan_id')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="qi-loc" class="label">Branch</label>
                <select id="qi-loc" wire:model="locationId" class="input"><option value="">Select…</option>@foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                @error('location_id')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="qi-lot" class="label">Size of the lot</label>
                <input id="qi-lot" type="text" inputmode="decimal" wire:model="lotQuantity" class="input tabular-nums" autocomplete="off">
                @error('lot_quantity')<p class="error">{{ $message }}</p>@enderror
            </div>
            @if ($batches !== [])
                <div class="sm:col-span-2">
                    <label for="qi-batch" class="label">Batch</label>
                    <select id="qi-batch" wire:model="batchId" class="input"><option value="">Select…</option>@foreach ($batches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    @error('batch_id')<p class="error">{{ $message }}</p>@enderror
                </div>
            @endif
            @if ($sourceType === 'manual')
                <div class="sm:col-span-2">
                    <label for="qi-src" class="label">Where from (optional)</label>
                    <input id="qi-src" type="text" wire:model="sourceLabel" class="input" maxlength="60" placeholder="A supplier delivery, a line, a customer complaint…">
                </div>
            @endif
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
            <a href="{{ route('quality.inspections.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Start</button>
        </div>
    </form>
</div>

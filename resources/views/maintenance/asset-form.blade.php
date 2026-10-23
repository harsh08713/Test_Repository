<div class="mx-auto max-w-3xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('maintenance.assets.index') }}" wire:navigate class="hover:text-brand-700">Assets</a></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $editing ? 'Change' : 'New' }} asset</h1>
    </div>

    <form wire:submit="save" class="card" novalidate>
        <div class="card-body grid gap-4 sm:grid-cols-2">
            <div><label for="as-code" class="label">Code</label><input id="as-code" type="text" wire:model="f.code" class="input uppercase" maxlength="30" placeholder="PRESS-01">@error('code')<p class="error">{{ $message }}</p>@enderror</div>
            <div><label for="as-name" class="label">Name</label><input id="as-name" type="text" wire:model="f.name" class="input" maxlength="150">@error('name')<p class="error">{{ $message }}</p>@enderror</div>
            <div><label for="as-loc" class="label">Branch</label><select id="as-loc" wire:model="f.location_id" class="input"><option value="">Select…</option>@foreach ($branches as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('location_id')<p class="error">{{ $message }}</p>@enderror</div>
            <div><label for="as-cat" class="label">Kind</label><select id="as-cat" wire:model="f.category" class="input">@foreach ($categories as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>@error('category')<p class="error">{{ $message }}</p>@enderror</div>
            <div><label for="as-mm" class="label">Make and model</label><input id="as-mm" type="text" wire:model="f.make_model" class="input" maxlength="120">@error('make_model')<p class="error">{{ $message }}</p>@enderror</div>
            <div><label for="as-sn" class="label">Serial number</label><input id="as-sn" type="text" wire:model="f.serial_no" class="input" maxlength="60"></div>
            <div><label for="as-inst" class="label">Installed on</label><input id="as-inst" type="date" wire:model="f.installed_on" class="input" max="{{ now()->toDateString() }}">@error('installed_on')<p class="error">{{ $message }}</p>@enderror</div>
            <div><label for="as-crit" class="label">How critical</label><select id="as-crit" wire:model="f.criticality" class="input">@foreach ($criticality as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>@error('criticality')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="sm:col-span-2"><label for="as-cc" class="label">Cost centre (optional)</label><select id="as-cc" wire:model="f.cost_center_id" class="input"><option value="">None</option>@foreach ($centres as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('cost_center_id')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="sm:col-span-2"><label for="as-rem" class="label">Remarks</label><textarea id="as-rem" rows="2" wire:model="f.remarks" class="input" maxlength="1000"></textarea>@error('remarks')<p class="error">{{ $message }}</p>@enderror</div>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><a href="{{ route('maintenance.assets.index') }}" wire:navigate class="btn-secondary">Cancel</a><button type="submit" class="btn-primary" wire:loading.attr="disabled">Save</button></div>
    </form>
</div>

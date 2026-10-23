<div class="mx-auto max-w-4xl space-y-5">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-slate-900">{{ $locationId ? 'Edit branch' : 'Add branch' }}</h1>
        <a href="{{ route('masters.locations.index') }}" wire:navigate class="btn-secondary">Back</a>
    </div>

    <form wire:submit="save" class="space-y-5" novalidate>
        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Branch</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="code" class="label">Branch code</label>
                    <input id="code" type="text" wire:model="code" @disabled($codeLocked) class="input uppercase" maxlength="10" autocomplete="off" required>
                    @if ($codeLocked)
                        <p class="help">Fixed, because document numbers have already been issued for this branch.</p>
                    @else
                        <p class="help">2–10 letters or digits, e.g. AHD. It appears in document numbers (QT/AHD/26-27/00001).</p>
                    @endif
                    @error('code') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="name" class="label">Branch name</label>
                    <input id="name" type="text" wire:model="name" class="input" required>
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="address_line1" class="label">Address line 1</label>
                    <input id="address_line1" type="text" wire:model="address_line1" class="input">
                    @error('address_line1') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="address_line2" class="label">Address line 2</label>
                    <input id="address_line2" type="text" wire:model="address_line2" class="input">
                </div>
                <div>
                    <label for="pin_code" class="label">PIN code</label>
                    <input id="pin_code" type="text" wire:model.blur="pin_code" class="input" maxlength="6" inputmode="numeric" autocomplete="off">
                    @if ($pinHint)<p class="help" role="status">{{ $pinHint }}</p>@endif
                    @error('pin_code') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="city" class="label">City</label>
                    <input id="city" type="text" wire:model="city" class="input">
                    @error('city') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="state_gst_code" class="label">State</label>
                    <select id="state_gst_code" wire:model="state_gst_code" class="input">
                        <option value="">Select…</option>
                        @foreach ($states as $gst => $label)<option value="{{ $gst }}">{{ $label }}</option>@endforeach
                    </select>
                    @error('state_gst_code') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="status" class="label">Status</label>
                    <select id="status" wire:model="status" class="input">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    @error('status') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="phone" class="label">Phone</label>
                    <input id="phone" type="tel" wire:model="phone" class="input">
                    @error('phone') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="email" class="label">Email</label>
                    <input id="email" type="email" wire:model="email" class="input">
                    @error('email') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Location on the map</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="latitude" class="label">Latitude</label>
                    <input id="latitude" type="text" inputmode="decimal" wire:model="latitude" class="input font-mono" placeholder="23.0225000">
                    @error('latitude') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="longitude" class="label">Longitude</label>
                    <input id="longitude" type="text" inputmode="decimal" wire:model="longitude" class="input font-mono" placeholder="72.5714000">
                    @error('longitude') <p class="error">{{ $message }}</p> @enderror
                </div>
                <p class="help sm:col-span-2">Used as the branch context for route plans and geofencing. Latitude −90…90, longitude −180…180.</p>
                <div class="sm:col-span-2">
                    <label for="remarks" class="label">Remarks</label>
                    <textarea id="remarks" rows="2" wire:model="remarks" class="input"></textarea>
                    @error('remarks') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('masters.locations.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save">{{ $locationId ? 'Save changes' : 'Add branch' }}</button>
        </div>
    </form>
</div>

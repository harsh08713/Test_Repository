<div class="mx-auto max-w-4xl space-y-5">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $companyId ? 'Edit company' : 'Add company' }}</h1>
            @unless ($companyId)
                <p class="mt-1 text-sm text-slate-500">A new company gets the standard states, units of measure and chart of accounts automatically, and you get access to it.</p>
            @endunless
        </div>
        <a href="{{ route('masters.companies.index') }}" wire:navigate class="btn-secondary">Back</a>
    </div>

    <form wire:submit="save" class="space-y-5" novalidate>
        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Identity</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="code" class="label">Company code</label>
                    <input id="code" type="text" wire:model="code" class="input uppercase" autocomplete="off" required>
                    <p class="help">Short unique code, e.g. FEZ. Letters, digits, dot, dash.</p>
                    @error('code') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="name" class="label">Company name</label>
                    <input id="name" type="text" wire:model="name" class="input" required>
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="legal_name" class="label">Legal name</label>
                    <input id="legal_name" type="text" wire:model="legal_name" class="input">
                    @error('legal_name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="gstin" class="label">GSTIN</label>
                    <input id="gstin" type="text" wire:model.blur="gstin" class="input font-mono uppercase" maxlength="15" autocomplete="off" placeholder="27AAAAA0000A1Z5">
                    @error('gstin') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="pan" class="label">PAN</label>
                    <input id="pan" type="text" wire:model="pan" class="input font-mono uppercase" maxlength="10" autocomplete="off" placeholder="ABCDE1234F">
                    @error('pan') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="cin" class="label">CIN</label>
                    <input id="cin" type="text" wire:model="cin" class="input font-mono uppercase" maxlength="21" autocomplete="off" placeholder="U12345GJ2020PTC123456">
                    @error('cin') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Address and contact</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
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
                    <label for="pin_code" class="label">PIN code</label>
                    <input id="pin_code" type="text" wire:model="pin_code" class="input" maxlength="6" inputmode="numeric" autocomplete="off">
                    @error('pin_code') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="phone" class="label">Phone</label>
                    <input id="phone" type="tel" wire:model="phone" class="input">
                    @error('phone') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="email" class="label">Email</label>
                    <input id="email" type="email" wire:model="email" class="input">
                    @error('email') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Books and branding</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="fy" class="label">Financial year starts in</label>
                    <select id="fy" wire:model="financial_year_start_month" @disabled($fyLocked) class="input">
                        @foreach ($months as $number => $label)<option value="{{ $number }}">{{ $label }}</option>@endforeach
                    </select>
                    @if ($fyLocked)
                        <p class="help">Fixed, because document numbers have already been issued for this company.</p>
                    @else
                        <p class="help">April for most Indian companies. Drives the financial-year part of document numbers.</p>
                    @endif
                    @error('financial_year_start_month') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="base_currency" class="label">Base currency</label>
                    <select id="base_currency" wire:model="base_currency" class="input">
                        @foreach ($currencies as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                    </select>
                    @error('base_currency') <p class="error">{{ $message }}</p> @enderror
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
                    <label for="logo" class="label">Logo</label>
                    <input id="logo" type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium">
                    <p class="help">PNG, JPG or WebP, up to 1 MB and 2000×2000 px. SVG is not accepted.</p>
                    <div wire:loading wire:target="logo" class="help">Uploading…</div>
                    @error('logo') <p class="error">{{ $message }}</p> @enderror
                    @if ($currentLogoUrl && ! $logo)
                        <div class="mt-2 flex items-center gap-3">
                            <img src="{{ $currentLogoUrl }}" alt="Current logo" class="h-10 rounded border border-slate-200 object-contain p-1">
                            <label class="flex items-center gap-2 text-xs text-slate-600"><input type="checkbox" wire:model="removeLogo" class="rounded border-slate-300"> Remove</label>
                        </div>
                    @endif
                </div>
                <div class="sm:col-span-2">
                    <label for="remarks" class="label">Remarks</label>
                    <textarea id="remarks" rows="2" wire:model="remarks" class="input"></textarea>
                    @error('remarks') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('masters.companies.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save">{{ $companyId ? 'Save changes' : 'Create company' }}</button>
        </div>
    </form>
</div>

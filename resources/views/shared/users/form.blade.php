<div class="mx-auto max-w-4xl space-y-5">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $userId ? 'Edit user' : 'Add user' }}</h1>
            <p class="mt-1 text-sm text-slate-500">Access is limited to the companies and branches you tick below.</p>
        </div>
        <a href="{{ route('admin.users.index') }}" wire:navigate class="btn-secondary">Back</a>
    </div>

    <form wire:submit="save" class="space-y-5" novalidate>
        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Profile</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="name" class="label">Full name</label>
                    <input id="name" type="text" wire:model="name" class="input" required>
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="email" class="label">Email</label>
                    <input id="email" type="email" wire:model="email" class="input" required>
                    @error('email') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="phone" class="label">Phone</label>
                    <input id="phone" type="tel" wire:model="phone" class="input">
                    @error('phone') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="is_active" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        Account is active
                    </label>
                    @error('is_active') <p class="error ml-3">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="password" class="label">Password @if ($userId)<span class="font-normal text-slate-500">(leave blank to keep)</span>@endif</label>
                    <input id="password" type="password" wire:model="password" class="input" autocomplete="new-password">
                    @error('password') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="password_confirmation" class="label">Confirm password</label>
                    <input id="password_confirmation" type="password" wire:model="password_confirmation" class="input" autocomplete="new-password">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Companies &amp; branches</h2></div>
            <div class="card-body space-y-4">
                @error('company_ids') <p class="error">{{ $message }}</p> @enderror
                @error('location_ids') <p class="error">{{ $message }}</p> @enderror

                @foreach ($companies as $company)
                    <fieldset class="rounded-lg border border-slate-200 p-4" wire:key="company-{{ $company->id }}">
                        <legend class="px-1">
                            <label class="flex items-center gap-2 text-sm font-medium text-slate-900">
                                <input type="checkbox" wire:model.live="company_ids" value="{{ $company->id }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                {{ $company->name }}
                            </label>
                        </legend>

                        @if (in_array((string) $company->id, array_map('strval', $company_ids), true))
                            <label class="mt-1 flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" wire:model.live="all_location_company_ids" value="{{ $company->id }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                Can see every branch of this company
                            </label>

                            @unless (in_array((string) $company->id, array_map('strval', $all_location_company_ids), true))
                                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                    @forelse ($locations->get($company->id, collect()) as $location)
                                        <label class="flex items-center gap-2 text-sm text-slate-700" wire:key="loc-{{ $location->id }}">
                                            <input type="checkbox" wire:model="location_ids" value="{{ $location->id }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                            <span class="font-mono text-xs text-slate-500">{{ $location->code }}</span> {{ $location->name }}
                                        </label>
                                    @empty
                                        <p class="text-sm text-slate-500">This company has no branches yet.</p>
                                    @endforelse
                                </div>
                            @endunless
                        @endif
                    </fieldset>
                @endforeach

                <div class="max-w-sm">
                    <label for="default_company_id" class="label">Default company</label>
                    <select id="default_company_id" wire:model="default_company_id" class="input">
                        <option value="">—</option>
                        @foreach ($companies->whereIn('id', array_map('intval', $company_ids)) as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </select>
                    @error('default_company_id') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Roles</h2></div>
            <div class="card-body">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($roles as $role)
                        <label class="flex items-center gap-2 text-sm text-slate-700" wire:key="role-{{ $role->id }}">
                            <input type="checkbox" wire:model="role_names" value="{{ $role->name }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            {{ $role->name }}
                        </label>
                    @endforeach
                </div>
                <p class="help">You can only assign roles whose permissions you hold yourself.</p>
                @error('role_names') <p class="error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.users.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $userId ? 'Save changes' : 'Create user' }}</button>
        </div>
    </form>
</div>

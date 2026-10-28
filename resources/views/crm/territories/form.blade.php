<div class="mx-auto max-w-2xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $editing ? 'Edit territory' : 'New territory' }}</h1>
            <p class="mt-1 text-sm text-slate-500">Name it and choose who is responsible. You say what it covers on the next page.</p>
        </div>
        <a href="{{ $editing ? route('field.territories.show', $territoryId) : route('field.territories.index') }}" wire:navigate class="btn-secondary">Cancel</a>
    </div>

    @if ($errors->any())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">Some details need attention. Please check the fields marked below.</div>
    @endif

    <form wire:submit="save" class="card" novalidate>
        <div class="card-body grid gap-4 sm:grid-cols-2">
            <div>
                <label for="tf-code" class="label">Code</label>
                <input id="tf-code" type="text" wire:model="territory.code" class="input font-mono uppercase" maxlength="30" autocomplete="off" placeholder="e.g. GJ-NORTH" required>
                @error('territory.code') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="tf-name" class="label">Name</label>
                <input id="tf-name" type="text" wire:model="territory.name" class="input" maxlength="150" required>
                @error('territory.name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="tf-branch" class="label">Branch <span class="font-normal text-slate-500">(optional)</span></label>
                <select id="tf-branch" wire:model.live="territory.location_id" class="input">
                    <option value="">Company-wide</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <p class="help">The branch it is worked from. Leave it company-wide for a territory that crosses branches.</p>
                @error('territory.location_id') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="tf-owner" class="label">Owner <span class="font-normal text-slate-500">(optional)</span></label>
                <select id="tf-owner" wire:model="territory.owner_user_id" class="input">
                    <option value="">Nobody yet</option>
                    @foreach ($owners as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <p class="help">The person who should work the leads and customers here.</p>
                @error('territory.owner_user_id') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="tf-status" class="label">Status</label>
                <select id="tf-status" wire:model="territory.status" class="input">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <p class="help">A territory that is switched off is not looked up (its coverage stays, and stays taken).</p>
                @error('territory.status') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label for="tf-remarks" class="label">Remarks <span class="font-normal text-slate-500">(optional)</span></label>
                <textarea id="tf-remarks" rows="2" wire:model="territory.remarks" class="input" maxlength="1000"></textarea>
                @error('territory.remarks') <p class="error">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
            <a href="{{ $editing ? route('field.territories.show', $territoryId) : route('field.territories.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $editing ? 'Save territory' : 'Add territory' }}</button>
        </div>
    </form>
</div>

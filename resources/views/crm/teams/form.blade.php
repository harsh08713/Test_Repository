<div class="mx-auto max-w-2xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $editing ? 'Edit team' : 'New team' }}</h1>
            <p class="mt-1 text-sm text-slate-500">Name the team and its manager. You add the people on the next page.</p>
        </div>
        <a href="{{ $editing ? route('field.teams.show', $teamId) : route('field.teams.index') }}" wire:navigate class="btn-secondary">Cancel</a>
    </div>

    @if ($errors->any())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">Some details need attention. Please check the fields marked below.</div>
    @endif

    <form wire:submit="save" class="card" novalidate>
        <div class="card-body grid gap-4 sm:grid-cols-2">
            <div>
                <label for="t-code" class="label">Code</label>
                <input id="t-code" type="text" wire:model="team.code" class="input font-mono uppercase" maxlength="30" autocomplete="off" placeholder="e.g. GUJ-FIELD" required>
                @error('team.code') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="t-name" class="label">Name</label>
                <input id="t-name" type="text" wire:model="team.name" class="input" maxlength="150" required>
                @error('team.name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="t-manager" class="label">Manager</label>
                <select id="t-manager" wire:model="team.manager_user_id" class="input" required>
                    <option value="">Select…</option>
                    @foreach ($managers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <p class="help">Plans the days and sets the targets of the people on this team.</p>
                @error('team.manager_user_id') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="t-status" class="label">Status</label>
                <select id="t-status" wire:model="team.status" class="input">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <p class="help">A team that is switched off no longer gives its manager any reach.</p>
                @error('team.status') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label for="t-remarks" class="label">Remarks <span class="font-normal text-slate-500">(optional)</span></label>
                <textarea id="t-remarks" rows="2" wire:model="team.remarks" class="input" maxlength="1000"></textarea>
                @error('team.remarks') <p class="error">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
            <a href="{{ $editing ? route('field.teams.show', $teamId) : route('field.teams.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $editing ? 'Save team' : 'Add team' }}</button>
        </div>
    </form>
</div>
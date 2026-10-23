<fieldset class="space-y-3 rounded-lg border border-slate-200 p-3">
    <legend class="px-1 text-sm font-medium text-slate-700">For rows that do not say</legend>
    <div>
        <label for="imp-branch" class="label">Branch</label>
        <select id="imp-branch" wire:model="defaultBranch" class="input">
            <option value="">None — every row must name its branch</option>
            @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </select>
        @error('defaultBranch') <p class="error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="imp-source" class="label">Source</label>
        <select id="imp-source" wire:model="defaultSource" class="input">
            <option value="">None — every row must name its source</option>
            @foreach ($sources as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </select>
        @error('defaultSource') <p class="error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="imp-owner" class="label">Owner</label>
        <select id="imp-owner" wire:model="defaultOwner" class="input" @disabled(! $canAssign)>
            @foreach ($owners as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </select>
        <p class="help">{{ $canAssign ? 'Yourself or someone on a team you manage. A row can name another owner in its “Assigned To” column.' : 'Leads you import are yours.' }}</p>
        @error('defaultOwner') <p class="error">{{ $message }}</p> @enderror
    </div>
</fieldset>

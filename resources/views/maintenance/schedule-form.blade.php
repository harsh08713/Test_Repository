<div class="mx-auto max-w-3xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('maintenance.schedules.index') }}" wire:navigate class="hover:text-brand-700">Preventive schedules</a></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $editing ? 'Change' : 'New' }} preventive schedule</h1>
        <p class="mt-1 text-sm text-slate-500">A work order is made this many days before the due date. When it is completed, the next due date counts from the day the job was done.</p>
    </div>

    <form wire:submit="save" class="card" novalidate>
        <div class="card-body grid gap-4 sm:grid-cols-2">
            <div><label for="sc-code" class="label">Code</label><input id="sc-code" type="text" wire:model="f.code" class="input uppercase" maxlength="30" placeholder="PM-PRESS-01">@error('code')<p class="error">{{ $message }}</p>@enderror</div>
            <div><label for="sc-asset" class="label">Asset</label><select id="sc-asset" wire:model="f.asset_id" class="input"><option value="">Select…</option>@foreach ($assets as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('asset_id')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="sm:col-span-2"><label for="sc-name" class="label">The job</label><input id="sc-name" type="text" wire:model="f.name" class="input" maxlength="150" placeholder="Grease and inspect the hydraulics">@error('name')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="grid grid-cols-2 gap-3">
                <div><label for="sc-n" class="label">Every</label><input id="sc-n" type="text" inputmode="numeric" wire:model="f.every_n" class="input tabular-nums">@error('every_n')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="sc-u" class="label">&nbsp;</label><select id="sc-u" wire:model="f.unit" class="input">@foreach ($units as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>@error('unit')<p class="error">{{ $message }}</p>@enderror</div>
            </div>
            <div><label for="sc-due" class="label">Next due</label><input id="sc-due" type="date" wire:model="f.next_due_on" class="input">@error('next_due_on')<p class="error">{{ $message }}</p>@enderror</div>
            <div><label for="sc-lead" class="label">Make the work order this many days ahead</label><input id="sc-lead" type="text" inputmode="numeric" wire:model="f.lead_days" class="input tabular-nums">@error('lead_days')<p class="error">{{ $message }}</p>@enderror</div>
            <div><label for="sc-hrs" class="label">Hours it takes (optional)</label><input id="sc-hrs" type="text" inputmode="decimal" wire:model="f.estimated_hours" class="input tabular-nums">@error('estimated_hours')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="sm:col-span-2"><label for="sc-list" class="label">Checklist (one task per line)</label><textarea id="sc-list" rows="5" wire:model="f.checklist" class="input" maxlength="9000"></textarea>@error('checklist')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="sm:col-span-2"><label for="sc-rem" class="label">Remarks</label><input id="sc-rem" type="text" wire:model="f.remarks" class="input" maxlength="1000"></div>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><a href="{{ route('maintenance.schedules.index') }}" wire:navigate class="btn-secondary">Cancel</a><button type="submit" class="btn-primary" wire:loading.attr="disabled">Save</button></div>
    </form>
</div>

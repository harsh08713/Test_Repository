<div class="mx-auto max-w-3xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('maintenance.orders.index') }}" wire:navigate class="hover:text-brand-700">Work orders</a></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">Report or plan work</h1>
        <p class="mt-1 text-sm text-slate-500">For a breakdown, say when the asset stopped: downtime is counted from then until it is running again.</p>
    </div>

    <form wire:submit="save" class="card" novalidate>
        <div class="card-body grid gap-4 sm:grid-cols-2">
            <div><label for="wf-type" class="label">What kind</label><select id="wf-type" wire:model.live="type" class="input">@foreach ($types as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>@error('type')<p class="error">{{ $message }}</p>@enderror</div>
            <div><label for="wf-asset" class="label">Asset</label><select id="wf-asset" wire:model="assetId" class="input"><option value="">Select…</option>@foreach ($assets as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('asset_id')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="sm:col-span-2"><label for="wf-title" class="label">What needs doing</label><input id="wf-title" type="text" wire:model="title" class="input" maxlength="150" placeholder="Hydraulic oil leaking at the ram">@error('title')<p class="error">{{ $message }}</p>@enderror</div>
            <div><label for="wf-prio" class="label">Priority</label><select id="wf-prio" wire:model="priority" class="input">@foreach ($priorities as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>@error('priority')<p class="error">{{ $message }}</p>@enderror</div>
            @if ($type === 'breakdown')
                <div><label for="wf-stop" class="label">The asset stopped at</label><input id="wf-stop" type="datetime-local" wire:model="stoppedAt" class="input">@error('stopped_at')<p class="error">{{ $message }}</p>@enderror</div>
            @else
                <div><label for="wf-due" class="label">Due on</label><input id="wf-due" type="date" wire:model="dueOn" class="input">@error('due_on')<p class="error">{{ $message }}</p>@enderror</div>
            @endif
            @if ($technicians !== [])
                <div class="sm:col-span-2"><label for="wf-who" class="label">Assign to (optional)</label><select id="wf-who" wire:model="assignedTo" class="input"><option value="">Nobody yet</option>@foreach ($technicians as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('assigned_to')<p class="error">{{ $message }}</p>@enderror</div>
            @endif
            <div class="sm:col-span-2"><label for="wf-desc" class="label">Details</label><textarea id="wf-desc" rows="3" wire:model="description" class="input" maxlength="2000"></textarea>@error('description')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="sm:col-span-2"><label for="wf-list" class="label">Checklist (one task per line, optional)</label><textarea id="wf-list" rows="3" wire:model="checklist" class="input" maxlength="9000"></textarea>@error('checklist')<p class="error">{{ $message }}</p>@enderror</div>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><a href="{{ route('maintenance.orders.index') }}" wire:navigate class="btn-secondary">Cancel</a><button type="submit" class="btn-primary" wire:loading.attr="disabled">Make the work order</button></div>
    </form>
</div>

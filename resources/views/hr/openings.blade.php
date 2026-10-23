<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Job openings</h1>
            <p class="mt-1 text-sm text-slate-500">The positions the company is hiring for. People apply only to an open one; when the last position is filled the opening closes itself.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('hr.candidates') }}" wire:navigate class="btn-secondary">Candidates</a>
            @if ($canCreate)<button type="button" wire:click="create" class="btn-primary">New opening</button>@endif
        </div>
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if ($formOpen)
        <form wire:submit="save" class="card" novalidate>
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">{{ $editingId ? 'Change the opening' : 'New opening' }}</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div><label for="jo-code" class="label">Reference</label><input id="jo-code" type="text" wire:model="f.code" class="input font-mono" maxlength="30">@error('code')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="sm:col-span-2"><label for="jo-title" class="label">Job title</label><input id="jo-title" type="text" wire:model="f.title" class="input" maxlength="150">@error('title')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="jo-branch" class="label">Branch</label><select id="jo-branch" wire:model="f.location_id" class="input" @disabled($editingId)><option value="">Select…</option>@foreach ($branches as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('location_id')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="jo-dept" class="label">Department</label><select id="jo-dept" wire:model="f.department_id" class="input"><option value="">—</option>@foreach ($departments as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('department_id')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="jo-desig" class="label">Designation</label><select id="jo-desig" wire:model="f.designation_id" class="input"><option value="">—</option>@foreach ($designations as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('designation_id')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="sm:col-span-2"><label for="jo-mgr" class="label">Hiring manager (the new person will report to them)</label><select id="jo-mgr" wire:model="f.hiring_manager_id" class="input"><option value="">—</option>@foreach ($managers as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('hiring_manager_id')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="jo-pos" class="label">Positions</label><input id="jo-pos" type="number" min="1" max="50" wire:model="f.positions" class="input">@error('positions')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="sm:col-span-3"><label for="jo-desc" class="label">Description</label><textarea id="jo-desc" rows="3" wire:model="f.description" class="input" maxlength="4000"></textarea></div>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><button type="button" wire:click="cancel" class="btn-secondary">Cancel</button><button type="submit" class="btn-primary">Save</button></div>
        </form>
    @endif

    <div class="card">
        <div class="card-header"><label for="jo-state" class="sr-only">State</label><select id="jo-state" wire:model.live="state" class="input w-40"><option value="">All</option>@foreach ($states as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Reference</th><th>Title</th><th>Department</th><th class="text-right">Positions</th><th class="text-right">Applied</th><th class="text-right">Hired</th><th>State</th><th></th></tr></thead>
                <tbody>
                    @forelse ($openings as $o)
                        <tr wire:key="op-{{ $o->id }}">
                            <td class="font-mono text-xs">{{ $o->code }}</td>
                            <td><a href="{{ route('hr.openings.show', $o) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $o->title }}</a></td>
                            <td>{{ $o->department?->name ?? '—' }}</td>
                            <td class="text-right tabular-nums">{{ $o->positions }}</td>
                            <td class="text-right tabular-nums">{{ $applied[$o->id] ?? 0 }}</td>
                            <td class="text-right tabular-nums">{{ $hired[$o->id] ?? 0 }}</td>
                            <td><span @class(['badge', 'bg-emerald-100 text-emerald-800' => $o->state === 'open', 'bg-amber-100 text-amber-800' => $o->state === 'draft', 'bg-slate-100 text-slate-600' => $o->state === 'closed'])>{{ $states[$o->state] }}</span></td>
                            <td class="text-right">@can('recruitment.edit')<button type="button" wire:click="edit({{ $o->id }})" class="text-xs text-brand-700 hover:underline">Edit</button>@endcan</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-10 text-center text-slate-500">No openings yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

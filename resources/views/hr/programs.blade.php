<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Training programs</h1>
            <p class="mt-1 text-sm text-slate-500">Courses and sessions, their dates and seats. People are enrolled by HR, or by their manager; the outcome is recorded from the first day.</p>
        </div>
        @if ($canCreate)<button type="button" wire:click="create" class="btn-primary">New program</button>@endif
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('state')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if ($formOpen)
        <form wire:submit="save" class="card" novalidate>
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">{{ $editingId ? 'Change the program' : 'New program' }}</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-4">
                <div><label for="tp-code" class="label">Code</label><input id="tp-code" type="text" wire:model="f.code" class="input font-mono" maxlength="30">@error('code')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="sm:col-span-3"><label for="tp-title" class="label">Title</label><input id="tp-title" type="text" wire:model="f.title" class="input" maxlength="200">@error('title')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="tp-from" class="label">From</label><input id="tp-from" type="date" wire:model="f.starts_on" class="input"></div>
                <div><label for="tp-to" class="label">To</label><input id="tp-to" type="date" wire:model="f.ends_on" class="input">@error('ends_on')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="tp-mode" class="label">How</label><select id="tp-mode" wire:model="f.mode" class="input">@foreach ($modes as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>@error('mode')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="tp-cap" class="label">Seats</label><input id="tp-cap" type="number" min="1" max="500" wire:model="f.capacity" class="input">@error('capacity')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="sm:col-span-2"><label for="tp-trainer" class="label">Trainer</label><input id="tp-trainer" type="text" wire:model="f.trainer" class="input" maxlength="150"></div>
                <div class="sm:col-span-2"><label for="tp-venue" class="label">Venue or link</label><input id="tp-venue" type="text" wire:model="f.venue" class="input" maxlength="200"></div>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><button type="button" wire:click="cancel" class="btn-secondary">Cancel</button><button type="submit" class="btn-primary">Save</button></div>
        </form>
    @endif

    <div class="card">
        <div class="card-header"><label for="tp-state" class="sr-only">State</label><select id="tp-state" wire:model.live="state" class="input w-40"><option value="">All</option>@foreach ($states as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Program</th><th>Dates</th><th>How</th><th class="text-right">Seats</th><th>State</th><th></th></tr></thead>
                <tbody>
                    @forelse ($programs as $p)
                        <tr wire:key="pg-{{ $p->id }}">
                            <td><a href="{{ route('hr.programs.show', $p) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $p->title }}</a> <span class="font-mono text-xs text-slate-400">{{ $p->code }}</span>@if ($p->trainer)<div class="text-xs text-slate-500">{{ $p->trainer }}</div>@endif</td>
                            <td>{{ $p->starts_on->format('d M Y') }}{{ $p->starts_on->equalTo($p->ends_on) ? '' : ' – '.$p->ends_on->format('d M Y') }}</td>
                            <td>{{ $modes[$p->mode] ?? $p->mode }}</td>
                            <td class="text-right tabular-nums">{{ $seats[$p->id] ?? 0 }} / {{ $p->capacity }}</td>
                            <td><span @class(['badge', 'bg-amber-100 text-amber-800' => $p->state === 'planned', 'bg-emerald-100 text-emerald-800' => $p->state === 'completed', 'bg-slate-100 text-slate-600' => $p->state === 'cancelled'])>{{ $states[$p->state] }}</span></td>
                            <td class="text-right">@if ($canEdit && $p->state === 'planned')<button type="button" wire:click="edit({{ $p->id }})" class="text-xs text-brand-700 hover:underline">Edit</button>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-slate-500">No training programs yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

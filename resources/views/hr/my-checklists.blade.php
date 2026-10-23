<div class="mx-auto max-w-4xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Onboarding and exit</h1>
            <p class="mt-1 text-sm text-slate-500">Things to do when someone joins or leaves. You can mark done the items that are yours: your own, and your reports’ items that are the manager’s.</p>
        </div>
        <select wire:model.live="show" class="input w-40" aria-label="Show"><option value="open">Open</option><option value="late">Late</option><option value="done">Done</option><option value="all">All</option></select>
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('item')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if ($canStart)
        <form wire:submit="startList" class="card card-body flex flex-wrap items-end gap-3" novalidate>
            <div class="min-w-48 flex-1"><label for="cl-person" class="label">Start a list for</label><select id="cl-person" wire:model="startFor" class="input"><option value="">Select a person…</option>@foreach ($people as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->code }})</option>@endforeach</select></div>
            <div><label for="cl-kind" class="label">List</label><select id="cl-kind" wire:model="startKind" class="input"><option value="onboarding">Onboarding</option><option value="exit">Exit</option></select></div>
            <button type="submit" class="btn-secondary">Start</button>
        </form>
    @endif

    @forelse ($rows as $employeeId => $items)
        <div class="card" wire:key="cl-emp-{{ $employeeId }}">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">{{ $items->first()->employee?->name }} <span class="font-normal text-slate-500">· {{ $items->first()->employee?->code }}</span></h2></div>
            <ul class="divide-y divide-slate-100">
                @foreach ($items as $i)
                    <li class="px-5 py-3" wire:key="cl-{{ $i->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="font-medium {{ $i->isDone() ? 'text-slate-500 line-through' : 'text-slate-900' }}">{{ $i->title }}</p>
                                <p class="text-sm text-slate-500">{{ ['onboarding' => 'Onboarding', 'exit' => 'Exit'][$i->kind] }} · {{ ['hr' => 'HR', 'manager' => 'Manager', 'employee' => 'Employee'][$i->responsible] }} · due {{ $i->due_on->format('d M Y') }}
                                    @if ($i->isDone()) · done {{ $i->done_at->format('d M Y') }}{{ $i->doneBy ? ' by '.$i->doneBy->name : '' }}{{ $i->note ? ' — '.$i->note : '' }}
                                    @elseif ($i->due_on->lt(now()->startOfDay())) · <span class="font-medium text-rose-700">late</span>@endif</p>
                            </div>
                            @if (! $i->isDone() && $can($i))<button type="button" wire:click="startNoting({{ $i->id }})" class="btn-secondary btn-sm">Mark done</button>@endif
                        </div>
                        @if ($noting === $i->id)
                            <form wire:submit="done({{ $i->id }})" class="mt-3 flex flex-wrap items-end gap-3" novalidate>
                                <div class="min-w-48 flex-1"><label for="cl-note-{{ $i->id }}" class="label">Note (optional)</label><input id="cl-note-{{ $i->id }}" type="text" wire:model="note" class="input" maxlength="300">@error('note')<p class="error">{{ $message }}</p>@enderror</div>
                                <button type="submit" class="btn-primary btn-sm">Save</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @empty
        <div class="card card-body text-sm text-slate-500">Nothing here{{ $show === 'open' ? ' to do' : '' }}. Lists start by themselves when someone joins or gives notice, once the company has an onboarding or exit list set up.</div>
    @endforelse
</div>

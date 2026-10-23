<div class="mx-auto max-w-4xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">My interviews</h1>
            <p class="mt-1 text-sm text-slate-500">Interviews you are to take. You write the feedback yourself, from the day of the interview.</p>
        </div>
        <select wire:model.live="show" class="input w-40" aria-label="Show"><option value="planned">Planned</option><option value="done">Done</option><option value="all">All</option></select>
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('state')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <div class="space-y-3">
        @forelse ($rows as $i)
            <div class="card" wire:key="mi-{{ $i->id }}">
                <div class="card-body flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="font-medium text-slate-900">{{ $i->application?->candidate?->name }} <span class="font-normal text-slate-500">· {{ $i->application?->opening?->title }} · round {{ $i->round }}</span></p>
                        <p class="text-sm text-slate-500">{{ $i->scheduled_at->format('l d M Y, H:i') }} · {{ $modes[$i->mode] }}{{ $i->application?->candidate?->mobile ? ' · '.$i->application->candidate->mobile : '' }}</p>
                        @if ($i->state === 'done')<p class="mt-1 text-sm text-slate-600">Rated {{ $i->rating }} of 5 · {{ $i->recommendation }} — {{ $i->feedback }}</p>@endif
                    </div>
                    <div class="flex items-center gap-3">
                        <span @class(['badge', 'bg-amber-100 text-amber-800' => $i->state === 'scheduled', 'bg-emerald-100 text-emerald-800' => $i->state === 'done', 'bg-slate-100 text-slate-600' => in_array($i->state, ['cancelled', 'no_show'], true)])>{{ ['scheduled' => 'Planned', 'done' => 'Done', 'cancelled' => 'Cancelled', 'no_show' => 'No-show'][$i->state] }}</span>
                        @if ($i->state === 'scheduled')<button type="button" wire:click="startFeedback({{ $i->id }})" class="btn-secondary btn-sm">Write feedback</button>@endif
                    </div>
                </div>
                @if ($giving === $i->id)
                    <form wire:submit="sendFeedback" class="grid gap-3 border-t border-slate-200 p-5 sm:grid-cols-3" novalidate>
                        <div><label for="fb-r-{{ $i->id }}" class="label">Rating (1–5)</label><select id="fb-r-{{ $i->id }}" wire:model="form.rating" class="input"><option value="">Select…</option>@foreach ([1, 2, 3, 4, 5] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach</select>@error('rating')<p class="error">{{ $message }}</p>@enderror</div>
                        <div><label for="fb-c-{{ $i->id }}" class="label">Recommend</label><select id="fb-c-{{ $i->id }}" wire:model="form.recommendation" class="input"><option value="">Select…</option><option value="hire">Hire</option><option value="hold">Hold</option><option value="reject">Reject</option></select>@error('recommendation')<p class="error">{{ $message }}</p>@enderror</div>
                        <div class="sm:col-span-3"><label for="fb-t-{{ $i->id }}" class="label">What you found</label><textarea id="fb-t-{{ $i->id }}" rows="3" wire:model="form.feedback" class="input" maxlength="2000"></textarea>@error('feedback')<p class="error">{{ $message }}</p>@enderror</div>
                        <div class="sm:col-span-3 flex justify-end"><button type="submit" class="btn-primary">Send feedback</button></div>
                    </form>
                @endif
            </div>
        @empty
            <p class="card card-body text-center text-sm text-slate-500">Nothing here.</p>
        @endforelse
    </div>
</div>

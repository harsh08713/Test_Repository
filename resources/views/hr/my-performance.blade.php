<div class="mx-auto max-w-4xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">My performance</h1>
            <p class="mt-1 text-sm text-slate-500">Your goals and your review. Your manager sets the goals; you keep their progress up to date and rate yourself; your manager then rates you and HR makes it final.</p>
        </div>
        @if ($cycles->isNotEmpty())<select wire:model.live="cycle" class="input w-56" aria-label="Cycle"><option value="">Latest</option>@foreach ($cycles as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>@endif
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('state')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if (! $employee)
        <p class="card card-body text-sm text-slate-600">Your login is not linked to an employee file yet. Ask HR to link it.</p>
    @elseif (! $active)
        <p class="card card-body text-sm text-slate-600">There is no review cycle open yet.</p>
    @else
        <div class="card card-body flex flex-wrap items-center justify-between gap-3">
            <div><p class="text-sm font-medium text-slate-900">{{ $active->name }}</p><p class="text-xs text-slate-500">{{ $active->period_start->format('d M Y') }} – {{ $active->period_end->format('d M Y') }} · {{ $active->state === 'open' ? 'open' : 'closed' }}</p></div>
            <div class="text-right"><p class="text-xs text-slate-500">Goals done (weighted)</p><p class="text-2xl font-semibold text-slate-900" data-testid="goal-score">{{ $score === null ? '—' : $score.'%' }}</p></div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">My goals</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($goals as $g)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3" wire:key="g-{{ $g->id }}">
                        <div class="min-w-64 flex-1"><p class="font-medium text-slate-900">{{ $g->title }} <span class="text-xs font-normal text-slate-400">weight {{ $g->weight }}%</span></p>@if ($g->description)<p class="text-slate-500">{{ $g->description }}</p>@endif</div>
                        <div class="flex items-center gap-2">
                            <label for="pg-{{ $g->id }}" class="sr-only">Progress</label>
                            <input id="pg-{{ $g->id }}" type="number" min="0" max="100" wire:model="progress.{{ $g->id }}" class="input w-20 tabular-nums" @disabled(! $editable)> %
                            @if ($editable)<button type="button" wire:click="saveProgress({{ $g->id }})" class="btn-secondary btn-sm">Save</button>@endif
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-8 text-center text-slate-500">No goals have been set for you yet. Your manager sets them.</li>
                @endforelse
            </ul>
            @error('progress')<p class="error px-5 pb-3">{{ $message }}</p>@enderror
        </div>

        @if ($review)
            <div class="card">
                <div class="card-header flex items-center justify-between"><h2 class="text-sm font-semibold text-slate-900">My review</h2><span class="badge bg-slate-100 text-slate-700">{{ $states[$review->state] }}</span></div>
                @if ($editable)
                    <form wire:submit="saveSelfReview" class="grid gap-3 p-5 sm:grid-cols-4" novalidate>
                        <div><label for="sr-r" class="label">My rating</label><select id="sr-r" wire:model="self.rating" class="input"><option value="">Select…</option>@foreach ($ratings as $n => $name)<option value="{{ $n }}">{{ $n }} · {{ $name }}</option>@endforeach</select>@error('rating')<p class="error">{{ $message }}</p>@enderror</div>
                        <div class="sm:col-span-3"><label for="sr-c" class="label">How the period went</label><textarea id="sr-c" rows="3" wire:model="self.comments" class="input" maxlength="2000"></textarea>@error('comments')<p class="error">{{ $message }}</p>@enderror</div>
                        <div class="sm:col-span-4 flex justify-end"><button type="submit" class="btn-primary">{{ $review->state === 'self_done' ? 'Update my self-review' : 'Send to my manager' }}</button></div>
                    </form>
                @else
                    <dl class="grid gap-4 p-5 text-sm sm:grid-cols-2">
                        <div><dt class="text-xs text-slate-500">My rating</dt><dd class="text-slate-900">{{ $review->self_rating ? $review->self_rating.' · '.$ratings[$review->self_rating] : '—' }}</dd>@if ($review->self_comments)<dd class="text-slate-600">{{ $review->self_comments }}</dd>@endif</div>
                        @if ($review->state === 'finalised')
                            <div><dt class="text-xs text-slate-500">My manager’s rating</dt><dd class="text-slate-900">{{ $review->manager_rating ? $review->manager_rating.' · '.$ratings[$review->manager_rating] : '—' }}</dd>@if ($review->manager_comments)<dd class="text-slate-600">{{ $review->manager_comments }}</dd>@endif</div>
                            <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Final rating</dt><dd class="text-lg font-semibold text-slate-900">{{ $review->final_rating }} of 5 · {{ $ratings[$review->final_rating] }}</dd></div>
                        @endif
                    </dl>
                @endif
            </div>
        @endif
    @endif
</div>

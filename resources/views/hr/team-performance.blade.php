<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Team performance</h1>
            <p class="mt-1 text-sm text-slate-500">Goals and reviews of the people you manage (everyone, for HR). Set their goals, rate their review after they have rated themselves, and HR makes it final. You cannot rate yourself.</p>
        </div>
        @if ($cycles->isNotEmpty())<select wire:model.live="cycle" class="input w-56" aria-label="Cycle"><option value="">Latest</option>@foreach ($cycles as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>@endif
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('state')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if (! $active)
        <p class="card card-body text-sm text-slate-600">There is no review cycle open yet.</p>
    @else
        <div class="card">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Person</th><th class="text-right">Goals</th><th class="text-right">Done</th><th>Review</th><th class="text-right">Self</th><th class="text-right">Manager</th><th class="text-right">Final</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($people as $p)
                            @php $r = $reviews->get($p->id); @endphp
                            <tr wire:key="tp-{{ $p->id }}" @class(['bg-brand-50/40' => $chosen && $chosen->id === $p->id])>
                                <td class="font-medium text-slate-900">{{ $p->name }} <span class="text-xs font-normal text-slate-400">{{ $p->code }}</span></td>
                                <td class="text-right tabular-nums">{{ $goalRows->get($p->id, collect())->count() }}</td>
                                <td class="text-right tabular-nums">{{ $scores[$p->id] === null ? '—' : $scores[$p->id].'%' }}</td>
                                <td>@if ($r)<span class="badge bg-slate-100 text-slate-700">{{ $states[$r->state] }}</span>@else — @endif</td>
                                <td class="text-right tabular-nums">{{ $r?->self_rating ?? '—' }}</td>
                                <td class="text-right tabular-nums">{{ $r?->manager_rating ?? '—' }}</td>
                                <td class="text-right tabular-nums font-semibold">{{ $r?->final_rating ?? '—' }}</td>
                                <td class="text-right"><button type="button" wire:click="pick({{ $p->id }})" class="btn-secondary btn-sm">{{ $chosen && $chosen->id === $p->id ? 'Close' : 'Open' }}</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-10 text-center text-slate-500">Nobody to show.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($chosen)
            <div class="grid gap-5 lg:grid-cols-2">
                <div class="card">
                    <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Goals of {{ $chosen->name }}</h2></div>
                    <ul class="divide-y divide-slate-100 text-sm">
                        @forelse ($goals as $g)
                            <li class="flex items-start justify-between gap-3 px-5 py-3" wire:key="tg-{{ $g->id }}">
                                <div><p class="font-medium text-slate-900">{{ $g->title }} <span class="text-xs font-normal text-slate-400">weight {{ $g->weight }}% · {{ $g->progress }}% done</span></p>@if ($g->description)<p class="text-slate-500">{{ $g->description }}</p>@endif</div>
                                @if ($canManage && $workable)<span class="flex gap-3 whitespace-nowrap text-xs"><button type="button" wire:click="editGoal({{ $g->id }})" class="text-brand-700 hover:underline">Edit</button><button type="button" wire:click="removeGoal({{ $g->id }})" wire:confirm="Remove this goal?" class="text-rose-600 hover:underline">Remove</button></span>@endif
                            </li>
                        @empty
                            <li class="px-5 py-6 text-center text-slate-500">No goals yet.</li>
                        @endforelse
                    </ul>
                    @if ($canManage && $workable)
                        <form wire:submit="saveGoal" class="grid gap-3 border-t border-slate-200 p-5 sm:grid-cols-4" novalidate>
                            <div class="sm:col-span-3"><label for="tg-title" class="label">{{ $goal['id'] === '' ? 'New goal' : 'Change the goal' }}</label><input id="tg-title" type="text" wire:model="goal.title" class="input" maxlength="200">@error('title')<p class="error">{{ $message }}</p>@enderror</div>
                            <div><label for="tg-w" class="label">Weight %</label><input id="tg-w" type="number" min="1" max="100" wire:model="goal.weight" class="input">@error('weight')<p class="error">{{ $message }}</p>@enderror</div>
                            <div class="sm:col-span-4"><label for="tg-d" class="label">Detail (optional)</label><input id="tg-d" type="text" wire:model="goal.description" class="input" maxlength="1000"></div>
                            <div class="sm:col-span-4 flex justify-end"><button type="submit" class="btn-primary">Save goal</button></div>
                        </form>
                    @endif
                </div>

                <div class="card">
                    <div class="card-header flex items-center justify-between"><h2 class="text-sm font-semibold text-slate-900">Review</h2>@if ($review)<span class="badge bg-slate-100 text-slate-700">{{ $states[$review->state] }}</span>@endif</div>
                    @if (! $review)
                        <p class="p-5 text-sm text-slate-500">No review has been made for this person in this cycle.</p>
                    @else
                        <dl class="space-y-3 p-5 text-sm">
                            <div><dt class="text-xs text-slate-500">Their own rating</dt><dd class="text-slate-900">{{ $review->self_rating ? $review->self_rating.' · '.$ratings[$review->self_rating] : 'not done yet' }}</dd>@if ($review->self_comments)<dd class="text-slate-600">{{ $review->self_comments }}</dd>@endif</div>
                            @if ($review->state === 'finalised')
                                <div><dt class="text-xs text-slate-500">Manager’s rating</dt><dd class="text-slate-900">{{ $review->manager_rating }} · {{ $ratings[$review->manager_rating] }}</dd><dd class="text-slate-600">{{ $review->manager_comments }}</dd></div>
                                <div><dt class="text-xs text-slate-500">Final</dt><dd class="text-lg font-semibold text-slate-900">{{ $review->final_rating }} of 5 · {{ $ratings[$review->final_rating] }}</dd></div>
                            @endif
                        </dl>
                        @if ($canManage && $workable && in_array($review->state, ['self_done', 'manager_done'], true))
                            <form wire:submit="saveManagerReview" class="grid gap-3 border-t border-slate-200 p-5 sm:grid-cols-4" novalidate>
                                <div><label for="mr-r" class="label">My rating</label><select id="mr-r" wire:model="rate.rating" class="input"><option value="">Select…</option>@foreach ($ratings as $n => $name)<option value="{{ $n }}">{{ $n }} · {{ $name }}</option>@endforeach</select>@error('rating')<p class="error">{{ $message }}</p>@enderror</div>
                                <div class="sm:col-span-3"><label for="mr-c" class="label">Why</label><textarea id="mr-c" rows="3" wire:model="rate.comments" class="input" maxlength="2000"></textarea>@error('comments')<p class="error">{{ $message }}</p>@enderror</div>
                                <div class="sm:col-span-4 flex justify-end"><button type="submit" class="btn-primary">Save my rating</button></div>
                            </form>
                        @endif
                        @if ($canFinalise && $review->state === 'manager_done')
                            <form wire:submit="makeFinal" class="flex flex-wrap items-end gap-3 border-t border-slate-200 p-5" novalidate>
                                <div><label for="fr-r" class="label">Final rating (the manager’s by default)</label><select id="fr-r" wire:model="finalRating" class="input"><option value="">Manager’s: {{ $review->manager_rating }}</option>@foreach ($ratings as $n => $name)<option value="{{ $n }}">{{ $n }} · {{ $name }}</option>@endforeach</select>@error('final_rating')<p class="error">{{ $message }}</p>@enderror</div>
                                <button type="submit" class="btn-primary">Make final</button>
                            </form>
                        @endif
                    @endif
                </div>
            </div>
        @endif
    @endif
</div>

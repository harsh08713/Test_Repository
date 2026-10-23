<div class="space-y-5" x-data="followupCalendar({ offsetMinutes: {{ $offsetMinutes }} })" x-on:followups-filter-changed.window="refetch()">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Follow-up calendar</h1>
            <p class="mt-1 text-sm text-slate-500">Planned and completed follow-ups by day, week or month. Click an entry to open its lead{{ $canMove ? '; drag a planned one to move it to another time' : '' }}.</p>
        </div>
        <a href="{{ route('crm.followups.index') }}" wire:navigate class="btn-secondary"><x-icon name="clock" class="size-4" /> As a list</a>
    </div>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="c-person" class="sr-only">Person</label>
                <select id="c-person" wire:model.live="person" class="input w-52">
                    <option value="">Everyone I can see</option>
                    @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                @if ($teams !== [])
                    <label for="c-team" class="sr-only">Team</label>
                    <select id="c-team" wire:model.live="team" class="input w-48">
                        <option value="">All teams</option>
                        @foreach ($teams as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                @endif
            </div>
            <ul class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-600" aria-label="Legend">
                <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-rose-500" aria-hidden="true"></span> Overdue</li>
                <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-sky-500" aria-hidden="true"></span> Planned</li>
                <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-emerald-500" aria-hidden="true"></span> Done</li>
            </ul>
        </div>

        <p class="border-b border-slate-200 px-5 py-2 text-sm" x-show="message !== ''" x-cloak x-text="message" role="status" aria-live="polite"
           x-bind:class="failed ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-800'"></p>

        <div class="card-body">
            <div wire:ignore>
                <div x-ref="calendar" class="followup-calendar" aria-label="Follow-up calendar"></div>
            </div>
            <noscript><p class="text-sm text-slate-600">The calendar needs JavaScript. The same follow-ups are on the <a href="{{ route('crm.followups.index') }}" class="text-brand-700 underline">list</a>.</p></noscript>
        </div>
    </div>
</div>

<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Route plans</h1>
            <p class="mt-1 text-sm text-slate-500">One plan per salesperson per day: the stops they will cover, with a place on the map. <strong>No published plan, no attendance</strong> — a person can mark attendance only against a published plan, from inside its geofence.</p>
        </div>
        @if ($canCreate)
            <a href="{{ route('field.plans.create') }}" wire:navigate class="btn-primary">Plan a day</a>
        @endif
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="p-from" class="text-sm text-slate-600">From</label>
                <input id="p-from" type="date" wire:model.live="from" class="input w-40">
                <label for="p-to" class="text-sm text-slate-600">to</label>
                <input id="p-to" type="date" wire:model.live="to" class="input w-40">

                <label for="p-person" class="sr-only">Person</label>
                <select id="p-person" wire:model.live="person" class="input w-52">
                    <option value="">Everyone</option>
                    @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                <label for="p-branch" class="sr-only">Branch</label>
                <select id="p-branch" wire:model.live="branch" class="input w-44">
                    <option value="">All branches</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                <label for="p-state" class="sr-only">State</label>
                <select id="p-state" wire:model.live="state" class="input w-40">
                    <option value="">Any state</option>
                    @foreach ($states as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Day</th><th>Person</th><th>Branch</th><th>Plan</th><th class="text-right">Stops</th><th>State</th><th>Attendance</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($plans as $plan)
                        <tr wire:key="plan-{{ $plan->id }}">
                            <td class="whitespace-nowrap font-medium text-slate-900"><a href="{{ route('field.plans.show', $plan) }}" wire:navigate class="hover:text-brand-700">{{ $plan->plan_date->format('D, j M Y') }}</a></td>
                            <td>{{ $plan->user?->name }}</td>
                            <td class="text-slate-600">{{ $plan->location?->name }}</td>
                            <td class="text-slate-600">{{ $plan->title ?: '—' }}</td>
                            <td class="text-right tabular-nums">{{ $plan->stops_count }}</td>
                            <td><span class="badge {{ $plan->status->badge() }}">{{ $plan->status->label() }}</span></td>
                            <td>
                                @if ($plan->attendance)
                                    <span class="badge {{ $plan->attendance->decision->badge() }}">Marked {{ $plan->attendance->marked_at->format('H:i') }}</span>
                                @else<span class="text-slate-400">—</span>@endif
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <a href="{{ route('field.plans.show', $plan) }}" wire:navigate class="btn-ghost btn-sm">View</a>
                                @can('update', $plan)
                                    <a href="{{ route('field.plans.edit', $plan) }}" wire:navigate class="btn-ghost btn-sm">Edit</a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-10 text-center text-slate-500">No plans match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($plans->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $plans->links() }}</div>
        @endif
    </div>
</div>
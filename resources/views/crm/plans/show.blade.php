<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('field.plans.index') }}" wire:navigate class="hover:text-brand-700">Route plans</a> › {{ $plan->location?->name }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $plan->user?->name }} <span class="font-normal text-slate-500">· {{ $plan->plan_date->format('l, j F Y') }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="badge {{ $plan->status->badge() }}">{{ $plan->status->label() }}</span>
                @if ($plan->title)<span class="text-sm text-slate-600">{{ $plan->title }}</span>@endif
                @if ($plan->attendance)
                    <span class="badge {{ $plan->attendance->decision->badge() }}">Attendance marked {{ $plan->attendance->marked_at->format('H:i') }}</span>
                @elseif ($plan->status->value === 'published')
                    <span class="text-xs text-slate-500">Attendance not marked yet</span>
                @else
                    <span class="text-xs text-slate-500">Attendance cannot be marked until the plan is published</span>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canPublish)<button type="button" wire:click="publish" class="btn-primary">Publish</button>@endif
            @if ($canEdit)<a href="{{ route('field.plans.edit', $plan) }}" wire:navigate class="btn-secondary">Edit</a>@endif
            @if ($canCancel)<button type="button" wire:click="openCancel" class="btn-secondary">Cancel the plan…</button>@endif
            @if ($canReinstate)<button type="button" wire:click="reinstate" class="btn-secondary">Reinstate</button>@endif
            @if ($canDelete)<button type="button" wire:click="delete" wire:confirm="Delete this draft plan?" class="btn-ghost text-rose-600">Delete</button>@endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif
    @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <section class="card" aria-labelledby="s-stops">
        <div class="card-header">
            <h2 id="s-stops" class="text-sm font-semibold text-slate-900">Stops</h2>
            <span class="text-xs text-slate-500">Default radius {{ $plan->geofence_radius_m ?? config('formezy.settings.geofence.radius_m.default') }} m {{ $plan->geofence_radius_m === null ? '(company setting)' : '' }}</span>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th class="w-12">#</th><th>Stop</th><th>Kind</th><th>Place on the map</th><th class="text-right">Radius</th><th>Visit window</th></tr></thead>
                <tbody>
                    @forelse ($plan->stops as $stop)
                        <tr wire:key="stop-{{ $stop->id }}" @class(['bg-brand-50/40' => $plan->attendance?->route_stop_id === $stop->id])>
                            <td class="tabular-nums text-slate-500">{{ $stop->sequence }}</td>
                            <td class="font-medium text-slate-900">{{ $stop->label }}@if ($plan->attendance?->route_stop_id === $stop->id)<span class="badge ml-2 bg-emerald-100 text-emerald-800">Attendance here</span>@endif</td>
                            <td class="text-slate-600">{{ $stop->stop_type->label() }}</td>
                            <td class="font-mono text-xs">
                                @if ($stop->hasCoordinates())
                                    <a href="https://www.google.com/maps?q={{ $stop->latitude }},{{ $stop->longitude }}" target="_blank" rel="noopener noreferrer" class="text-brand-700 hover:underline">{{ $stop->latitude }}, {{ $stop->longitude }}</a>
                                @else<span class="font-sans text-slate-400">reminder only</span>@endif
                            </td>
                            <td class="text-right tabular-nums">{{ $stop->hasCoordinates() ? ($stop->radius_m ?? $plan->geofence_radius_m ?? config('formezy.settings.geofence.radius_m.default')).' m' : '—' }}</td>
                            <td class="tabular-nums text-slate-600">{{ $stop->visit_from ? substr($stop->visit_from, 0, 5).($stop->visit_to ? ' – '.substr($stop->visit_to, 0, 5) : '') : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-slate-500">No stops.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($plan->attendance)
        @php $a = $plan->attendance; @endphp
        <section class="card" aria-labelledby="s-att">
            <div class="card-header"><h2 id="s-att" class="text-sm font-semibold text-slate-900">Attendance</h2><span class="badge {{ $a->decision->badge() }}">{{ $a->decision->label() }}</span></div>
            <dl class="card-body grid gap-x-8 gap-y-3 text-sm sm:grid-cols-3">
                <div><dt class="text-xs text-slate-500">Marked</dt><dd>{{ $a->marked_at->format('j M Y, H:i') }} (server time)</dd></div>
                <div><dt class="text-xs text-slate-500">Position</dt><dd class="font-mono text-xs">{{ $a->latitude }}, {{ $a->longitude }}</dd></div>
                <div><dt class="text-xs text-slate-500">GPS accuracy</dt><dd>± {{ number_format((float) $a->accuracy_m, 0) }} m</dd></div>
                <div><dt class="text-xs text-slate-500">Distance from {{ $a->stop?->label ?? 'the nearest stop' }}</dt><dd>{{ number_format((float) $a->distance_m, 0) }} m of {{ $a->radius_m }} m allowed</dd></div>
                <div><dt class="text-xs text-slate-500">From</dt><dd>{{ ucfirst($a->source) }}</dd></div>
                @if ($a->override_by)
                    <div class="sm:col-span-3"><dt class="text-xs text-slate-500">Accepted by {{ $a->overrider?->name }}</dt><dd>“{{ $a->override_reason }}”</dd></div>
                @endif
            </dl>
        </section>
    @endif

    @if ($attempts->isNotEmpty())
        <section class="card" aria-labelledby="s-attempts">
            <div class="card-header"><h2 id="s-attempts" class="text-sm font-semibold text-slate-900">Refused attempts against this plan</h2><a href="{{ route('field.violations.index') }}" wire:navigate class="text-xs text-brand-700 hover:underline">Open the full log</a></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($attempts as $attempt)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-2.5" wire:key="att-{{ $attempt->id }}">
                        <span><span class="badge {{ $attempt->reason->badge() }}">{{ $attempt->reason->label() }}</span> <span class="text-slate-600">{{ $attempt->message }}</span></span>
                        <span class="text-xs text-slate-500">{{ $attempt->attempted_at->format('j M, H:i') }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($plan->remarks)
        <p class="text-sm text-slate-600">{{ $plan->remarks }}</p>
    @endif

    <livewire:shared.comments type="route_plan" :id="$plan->id" :key="'cm-'.$plan->id" />

    @if ($cancelOpen)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="cancel-title">
            <form wire:submit="cancel" class="card w-full max-w-lg" novalidate>
                <div class="card-header">
                    <h2 id="cancel-title" class="text-sm font-semibold text-slate-900">Cancel this plan</h2>
                    <button type="button" wire:click="closeCancel" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="card-body space-y-3">
                    <p class="text-sm text-slate-600">{{ $plan->user?->name }} will have <strong>no plan</strong> for the day, so they cannot mark attendance. You can reinstate it later.</p>
                    <div>
                        <label for="cancel-reason" class="label">Why?</label>
                        <textarea id="cancel-reason" rows="3" wire:model="reason" class="input" maxlength="1000"></textarea>
                        @error('reason') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeCancel" class="btn-secondary">Keep the plan</button>
                    <button type="submit" class="btn-danger" wire:loading.attr="disabled">Cancel the plan</button>
                </div>
            </form>
        </div>
    @endif
</div>
<div class="mx-auto max-w-2xl space-y-5" x-data="{
    busy: false,
    mark() {
        this.busy = true;
        if (! navigator.geolocation) { $wire.locationUnavailable('this browser cannot share its location').then(() => this.busy = false); return; }
        navigator.geolocation.getCurrentPosition(
            (p) => $wire.markAttendance(p.coords.latitude, p.coords.longitude, p.coords.accuracy).then(() => this.busy = false),
            (e) => $wire.locationUnavailable(e.message || '').then(() => this.busy = false),
            { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }
        );
    }
}">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">My day</h1>
        <p class="mt-1 text-sm text-slate-500">{{ \Illuminate\Support\Carbon::parse($today)->format('l, j F Y') }}</p>
    </div>

    @if ($outcome !== '')
        <div @class([
            'rounded-lg border px-4 py-3 text-sm',
            'border-emerald-200 bg-emerald-50 text-emerald-800' => $outcomeKind === 'success',
            'border-rose-200 bg-rose-50 text-rose-700' => $outcomeKind === 'error',
            'border-sky-200 bg-sky-50 text-sky-800' => $outcomeKind === 'info',
        ]) role="{{ $outcomeKind === 'error' ? 'alert' : 'status' }}">{{ $outcome }}</div>
    @endif

    @if ($plan === null)
        <section class="card">
            <div class="card-body">
                <p class="font-medium text-slate-900">You have no route plan for today.</p>
                <p class="mt-1 text-sm text-slate-600">Attendance can be marked only against a published route plan. Ask your manager to plan your day.</p>
            </div>
        </section>
    @else
        <section class="card" aria-labelledby="s-plan">
            <div class="card-header">
                <div>
                    <h2 id="s-plan" class="text-sm font-semibold text-slate-900">{{ $plan->title ?: 'Today’s plan' }} <span class="font-normal text-slate-500">· {{ $plan->location?->name }}</span></h2>
                </div>
                <span class="badge {{ $plan->status->badge() }}">{{ $plan->status->label() }}</span>
            </div>
            <ol class="divide-y divide-slate-100">
                @foreach ($plan->stops as $stop)
                    <li class="flex items-start gap-3 px-5 py-3" wire:key="s-{{ $stop->id }}">
                        <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">{{ $stop->sequence }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-900">{{ $stop->label }} <span class="font-normal text-slate-500">· {{ $stop->stop_type->label() }}</span></p>
                            <p class="text-xs text-slate-500">
                                @if ($stop->visit_from){{ substr($stop->visit_from, 0, 5) }}@if ($stop->visit_to) – {{ substr($stop->visit_to, 0, 5) }}@endif · @endif
                                @if ($stop->hasCoordinates())<a href="https://www.google.com/maps?q={{ $stop->latitude }},{{ $stop->longitude }}" target="_blank" rel="noopener noreferrer" class="text-brand-700 hover:underline">Open in Maps</a>@else No place on the map @endif
                            </p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="card" aria-labelledby="s-attendance">
            <div class="card-header"><h2 id="s-attendance" class="text-sm font-semibold text-slate-900">Attendance</h2></div>
            <div class="card-body space-y-3">
                @if ($attendance)
                    <p class="text-sm text-emerald-700"><strong>Marked at {{ $attendance->marked_at->format('H:i') }}</strong>, {{ number_format((float) $attendance->distance_m, 0) }} m from {{ $attendance->stop?->label ?? 'your first stop' }}.</p>
                @elseif ($canMark)
                    <p class="text-sm text-slate-600">Stand at your first stop, then tap the button. Your phone shares its position once; the server checks it against today’s plan.</p>
                    <button type="button" x-on:click="mark()" x-bind:disabled="busy" class="btn-primary">
                        <span x-show="! busy">Mark my attendance</span><span x-show="busy" x-cloak>Reading your position…</span>
                    </button>
                @elseif ($plan->status->value === 'draft')
                    <p class="text-sm text-slate-600">Your plan for today has not been published yet, so attendance cannot be marked. Ask your manager to publish it.</p>
                @else
                    <p class="text-sm text-slate-600">Your plan for today has been cancelled, so attendance cannot be marked.</p>
                @endif
            </div>
        </section>
    @endif

    @if ($attempts->isNotEmpty())
        <section class="card" aria-labelledby="s-attempts">
            <div class="card-header"><h2 id="s-attempts" class="text-sm font-semibold text-slate-900">Your attempts today that were refused</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($attempts as $attempt)
                    <li class="px-5 py-2.5" wire:key="a-{{ $attempt->id }}"><span class="text-slate-500">{{ $attempt->attempted_at->format('H:i') }}</span> · {{ $attempt->message }}</li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
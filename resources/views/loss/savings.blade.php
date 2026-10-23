@php
    use App\Modules\Shared\Support\Format;

    $stateClass = ['draft' => 'bg-slate-100 text-slate-700', 'submitted' => 'bg-sky-100 text-sky-800', 'verified' => 'bg-emerald-100 text-emerald-800', 'rejected' => 'bg-rose-100 text-rose-800'];
@endphp
<div class="space-y-5">
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">Verified savings</h1>
        <p class="mt-1 text-sm text-slate-500">Did a corrective action really save money? Each measurement compares the actual, validated loss before and after, scaled for volume, less what the action cost. Only what <strong>Finance has verified</strong> is counted here, and it is never added to an action’s own “verified result”.</p>
    </div>

    <x-status />

    <dl class="grid gap-3 sm:grid-cols-3">
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Finance-verified net saving, {{ $from }} to {{ $to }}</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($verified['net_saving'], 2) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Measurements verified</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format($verified['count']) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Of those, loss has come back</dt><dd @class(['text-xl font-semibold tabular-nums', 'text-rose-700' => $verified['recurred'] > 0, 'text-slate-900' => $verified['recurred'] === 0])>{{ number_format($verified['recurred']) }}</dd></div>
    </dl>

    <div class="flex flex-wrap items-center gap-3">
        <label for="sv-from" class="sr-only">From</label>
        <input id="sv-from" type="date" wire:model.live="from" class="input w-40">
        <label for="sv-to" class="sr-only">To</label>
        <input id="sv-to" type="date" wire:model.live="to" class="input w-40">
    </div>

    <nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Which measurements">
        @foreach ($tabs as $value => $label)
            <button type="button" wire:click="$set('show', '{{ $value }}')" wire:key="tab-{{ $value }}"
                    @class(['whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium', 'border-brand-600 text-brand-700' => $show === $value, 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $show !== $value])
                    @if ($show === $value) aria-current="page" @endif>{{ $label }}@if ($value === 'awaiting' && $awaitingCount > 0) <span class="badge bg-amber-100 text-amber-800">{{ $awaitingCount }}</span>@endif</button>
        @endforeach
    </nav>

    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if ($show === 'awaiting')
        <div class="card">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Action</th><th>Incident</th><th>Verified</th><th class="text-right">Result recorded (₹)</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($awaiting as $a)
                            <tr wire:key="aw-{{ $a->id }}">
                                <td><span class="font-mono text-xs text-slate-500">{{ $a->action_number }}</span> <span class="font-medium text-slate-900">{{ $a->title }}</span></td>
                                <td class="font-mono text-xs">{{ $a->incident?->incident_number }}</td>
                                <td>{{ $a->verified_at?->format('d-m-Y') }}</td>
                                <td class="text-right tabular-nums">{{ $a->verified_result_amount !== null ? Format::number($a->verified_result_amount, 2) : '—' }}</td>
                                <td class="text-right">@if ($canMeasure)<button type="button" wire:click="startMeasurement({{ $a->id }})" class="btn-primary btn-sm">Measure</button>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-10 text-center text-slate-500">Every verified action has been measured.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="card">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Action</th><th>Loss measured</th><th>Window</th><th class="text-right">Scaled baseline (₹)</th><th class="text-right">Loss after (₹)</th><th class="text-right">Net saving (₹)</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($measurements as $m)
                            <tr wire:key="sv-{{ $m->id }}">
                                <td><a href="{{ route('loss.savings.show', $m->id) }}" wire:navigate class="font-medium text-brand-700 hover:underline"><span class="font-mono text-xs">{{ $m->action?->action_number }}</span> {{ $m->action?->title }}</a></td>
                                <td>{{ $m->category?->name }}<p class="text-xs text-slate-500">{{ $m->location?->name }}</p></td>
                                <td class="whitespace-nowrap text-xs">{{ $m->measure_from->format('d-m-Y') }} → {{ $m->measure_to->format('d-m-Y') }}</td>
                                <td class="text-right tabular-nums">{{ Format::number($m->normalised_baseline, 2) }}</td>
                                <td class="text-right tabular-nums">{{ Format::number($m->measure_loss, 2) }}</td>
                                <td class="text-right font-medium tabular-nums">{{ Format::number($m->net_saving, 2) }}</td>
                                <td><span class="badge {{ $stateClass[$m->state] ?? '' }}">{{ $m->stateLabel() }}</span>@if ($m->recurred_at)<span class="badge bg-rose-100 text-rose-800">Recurred</span>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-10 text-center text-slate-500">Nothing here.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($measurements->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $measurements->links() }}</div>@endif
        </div>
    @endif
</div>

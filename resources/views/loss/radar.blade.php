@php
    use App\Modules\Shared\Support\Format;

    $severityClass = ['high' => 'bg-rose-100 text-rose-800', 'medium' => 'bg-amber-100 text-amber-800', 'low' => 'bg-slate-100 text-slate-700'];
    $stateClass = ['open' => 'bg-sky-100 text-sky-800', 'reported' => 'bg-emerald-100 text-emerald-800', 'dismissed' => 'bg-slate-100 text-slate-600', 'resolved' => 'bg-slate-100 text-slate-600'];
@endphp
<div class="space-y-5">
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">Leakage radar</h1>
        <p class="mt-1 text-sm text-slate-500">What the daily sweep noticed in stock, receipts and scrap. These are <strong>estimates and proposals</strong>: none is a loss figure until a person reports it as an incident and its figure is validated.</p>
    </div>

    <x-status />

    <nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Which findings">
        @foreach ($tabs as $value => $label)
            <button type="button" wire:click="$set('show', '{{ $value }}')" wire:key="tab-{{ $value }}"
                    @class(['whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium', 'border-brand-600 text-brand-700' => $show === $value, 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $show !== $value])
                    @if ($show === $value) aria-current="page" @endif>{{ $label }}</button>
        @endforeach
    </nav>

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Finding</th><th>Branch</th><th>Severity</th><th class="text-right">Estimate (₹)</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($findings as $f)
                        <tr wire:key="rf-{{ $f->id }}">
                            <td class="max-w-md">
                                <p class="font-medium text-slate-900">{{ $f->title }}</p>
                                <p class="text-xs text-slate-500">{{ $f->evidence['method'] ?? '' }}</p>
                                <details class="mt-1 text-xs text-slate-600">
                                    <summary class="cursor-pointer text-brand-700">Evidence and calculation</summary>
                                    <dl class="mt-1 space-y-0.5">
                                        <div><dt class="inline font-medium">Source:</dt> <dd class="inline">{{ $f->evidence['source'] ?? '' }}</dd></div>
                                        <div><dt class="inline font-medium">Worked out as:</dt> <dd class="inline">{{ $f->evidence['formula_key'] ?? '' }} from
                                            @foreach (($f->evidence['inputs'] ?? []) as $name => $value){{ $name }} {{ $value }}{{ $loop->last ? '' : ', ' }}@endforeach</dd></div>
                                        <div><dt class="inline font-medium">Assumes:</dt> <dd class="inline">{{ $f->evidence['assumptions'] ?? '' }}</dd></div>
                                        <div><dt class="inline font-medium">Kind of figure:</dt> <dd class="inline">{{ $f->impact_type === 'actual' ? 'Actual loss (stock already lost)' : 'Exposure (may still be recovered)' }}</dd></div>
                                        <div><dt class="inline font-medium">First seen / data as of:</dt> <dd class="inline">{{ $f->first_detected_at->format('d-m-Y') }} / {{ $f->last_seen_at->format('d-m-Y H:i') }}</dd></div>
                                    </dl>
                                </details>
                            </td>
                            <td>{{ $f->location?->name }}</td>
                            <td><span class="badge {{ $severityClass[$f->severity] ?? '' }}">{{ ucfirst($f->severity) }}</span></td>
                            <td class="text-right tabular-nums">{{ Format::number($f->estimated_amount, 2) }}<p class="text-xs text-slate-500">{{ $f->impact_type === 'actual' ? 'actual (estimate)' : 'exposure' }}</p></td>
                            <td>
                                <span class="badge {{ $stateClass[$f->state] ?? '' }}">{{ \App\Modules\Loss\Models\RadarFinding::STATES[$f->state] ?? $f->state }}</span>
                                @if ($f->state === 'reported' && $f->incident)
                                    <p class="mt-1 text-xs">@if ($canSeeIncidents)<a href="{{ route('loss.incidents.show', $f->loss_incident_id) }}" wire:navigate class="font-mono text-brand-700 hover:underline">{{ $f->incident->incident_number }}</a>@else<span class="font-mono">{{ $f->incident->incident_number }}</span>@endif</p>
                                @endif
                                @if ($f->state === 'dismissed')
                                    <p class="mt-1 text-xs text-slate-500">{{ $f->dismissedBy?->name }}: {{ $f->dismiss_reason }}</p>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-right">
                                @if ($f->state === 'open')
                                    @if ($canReport)<a href="{{ $f->reportUrl() }}" wire:navigate class="btn-primary btn-sm">Report as incident</a>@endif
                                    @if ($canDismiss)<button type="button" wire:click="askDismiss({{ $f->id }})" class="btn-secondary btn-sm">Dismiss</button>@endif
                                @endif
                            </td>
                        </tr>
                        @if ($dismissingId === $f->id)
                            <tr wire:key="rf-dismiss-{{ $f->id }}">
                                <td colspan="6" class="bg-slate-50">
                                    <form wire:submit="dismissFinding" class="flex flex-wrap items-start gap-3">
                                        <div class="min-w-64 flex-1">
                                            <label for="reason-{{ $f->id }}" class="text-sm font-medium text-slate-700">Why is this not a loss worth pursuing?</label>
                                            <input id="reason-{{ $f->id }}" type="text" wire:model="reason" maxlength="300" class="input mt-1" autofocus>
                                            @error('reason')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
                                        </div>
                                        <div class="mt-6 flex gap-2">
                                            <button type="submit" class="btn-primary btn-sm">Dismiss finding</button>
                                            <button type="button" wire:click="cancelDismiss" class="btn-secondary btn-sm">Cancel</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-slate-500">Nothing here. The sweep runs daily at 02:30, and only when the scheduler is running.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($findings->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $findings->links() }}</div>@endif
    </div>
</div>

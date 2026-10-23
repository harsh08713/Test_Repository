@php
    use App\Modules\Shared\Support\Format;

    $stateClass = ['draft' => 'bg-slate-100 text-slate-700', 'submitted' => 'bg-sky-100 text-sky-800', 'verified' => 'bg-emerald-100 text-emerald-800', 'rejected' => 'bg-rose-100 text-rose-800'];
    $m = $measurement;
    $shown = $calc ?? [
        'baseline_loss' => $m->baseline_loss, 'measure_loss' => $m->measure_loss, 'method' => $m->method, 'normalised_baseline' => $m->normalised_baseline,
        'gross_saving' => $m->gross_saving, 'implementation_cost' => $m->implementation_cost, 'net_saving' => $m->net_saving,
    ];
@endphp
<div class="mx-auto max-w-4xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('loss.savings') }}" wire:navigate class="hover:text-brand-700">Verified savings</a> › {{ $m->location?->name }}</p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $m->action?->title }} <span class="font-mono text-base font-normal text-slate-500">{{ $m->action?->action_number }}</span></h1>
        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
            <span class="badge {{ $stateClass[$m->state] ?? '' }}">{{ $m->stateLabel() }}</span>
            @if ($m->recurred_at)<span class="badge bg-rose-100 text-rose-800">Loss recurred {{ $m->recurred_at->format('d-m-Y') }}@if ($m->recurrenceIncident) · {{ $m->recurrenceIncident->incident_number }}@endif</span>@endif
            <span class="text-slate-600">{{ $m->category?->name }} · incident {{ $m->action?->incident?->incident_number }} · action owner {{ $m->action?->owner?->name }}</span>
        </div>
    </div>

    <x-status />
    @error('measurement')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <section class="card" aria-labelledby="sv-fig">
        <div class="card-header"><h2 id="sv-fig" class="text-sm font-semibold text-slate-900">The figures{{ $calc !== null ? ' (preview of what you have typed)' : '' }}</h2></div>
        <div class="card-body">
            <dl class="grid gap-3 sm:grid-cols-2">
                <div><dt class="text-xs text-slate-500">Actual loss in the baseline period</dt><dd class="tabular-nums">₹ {{ Format::number($shown['baseline_loss'], 2) }}</dd></div>
                <div><dt class="text-xs text-slate-500">Baseline scaled {{ $shown['method'] === 'volume' ? 'by volume' : 'by days' }}</dt><dd class="tabular-nums">₹ {{ Format::number($shown['normalised_baseline'], 2) }}</dd></div>
                <div><dt class="text-xs text-slate-500">Actual loss in the measurement window</dt><dd class="tabular-nums">₹ {{ Format::number($shown['measure_loss'], 2) }}</dd></div>
                <div><dt class="text-xs text-slate-500">Saving before cost (scaled baseline − loss after)</dt><dd class="tabular-nums">₹ {{ Format::number($shown['gross_saving'], 2) }}</dd></div>
                <div><dt class="text-xs text-slate-500">What the action cost</dt><dd class="tabular-nums">₹ {{ Format::number($shown['implementation_cost'], 2) }}</dd></div>
                <div><dt class="text-xs text-slate-500">Net saving</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">₹ {{ Format::number($shown['net_saving'], 2) }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-slate-500">Losses are the validated <em>actual</em> losses of this category at this branch{{ $m->item_id ? ' and item' : '' }} from the loss records — duplicates, rejected incidents and superseded figures are not counted. Exposure and opportunity are never part of a saving.</p>
        </div>
    </section>

    @if ($can['edit'])
        <form wire:submit="submitForFinance" class="space-y-5" novalidate>
            <section class="card" aria-labelledby="sv-in">
                <div class="card-header"><h2 id="sv-in" class="text-sm font-semibold text-slate-900">What to compare</h2></div>
                <div class="card-body grid gap-4 sm:grid-cols-2">
                    @foreach ([['baseline_from', 'Baseline from'], ['baseline_to', 'Baseline to'], ['measure_from', 'Window from (on or after the action was done)'], ['measure_to', 'Window to']] as [$key, $label])
                        <div>
                            <label for="sv-{{ $key }}" class="label">{{ $label }}</label>
                            <input id="sv-{{ $key }}" type="date" wire:model.live.debounce.600ms="form.{{ $key }}" class="input">
                            @error('form.'.$key)<p class="error">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                    <div>
                        <label for="sv-bv" class="label">Volume in the baseline (optional)</label>
                        <input id="sv-bv" type="text" inputmode="decimal" wire:model.live.debounce.600ms="form.baseline_volume" class="input">
                        @error('form.baseline_volume')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="sv-mv" class="label">Volume in the window (optional)</label>
                        <input id="sv-mv" type="text" inputmode="decimal" wire:model.live.debounce.600ms="form.measure_volume" class="input">
                        @error('form.measure_volume')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="sv-vu" class="label">What the volume counts</label>
                        <input id="sv-vu" type="text" wire:model.live.debounce.600ms="form.volume_unit" class="input" maxlength="30" placeholder="units produced, kg…">
                        @error('form.volume_unit')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="sv-cost" class="label">What the action cost (₹)</label>
                        <input id="sv-cost" type="text" inputmode="decimal" wire:model.live.debounce.600ms="form.implementation_cost" class="input">
                        @error('form.implementation_cost')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="sv-ev" class="label">Evidence — where the volumes, cost and loss records come from</label>
                        <textarea id="sv-ev" rows="3" wire:model="form.evidence" class="input" maxlength="2000"></textarea>
                        @error('form.evidence')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <p class="text-xs text-slate-500 sm:col-span-2">With no volumes the baseline is scaled by the number of days. Give both volumes when output changed, or the saving will be mistaken for a fall in production.</p>
                </div>
            </section>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="saveDraft" class="btn-secondary">Save draft</button>
                <button type="submit" class="btn-primary">Send to Finance</button>
            </div>
        </form>
    @else
        <section class="card" aria-labelledby="sv-rec">
            <div class="card-header"><h2 id="sv-rec" class="text-sm font-semibold text-slate-900">What was compared</h2></div>
            <div class="card-body space-y-1 text-sm">
                <p>Baseline {{ $m->baseline_from->format('d-m-Y') }} to {{ $m->baseline_to->format('d-m-Y') }}; window {{ $m->measure_from->format('d-m-Y') }} to {{ $m->measure_to->format('d-m-Y') }}.</p>
                @if ($m->method === 'volume')<p>Volumes ({{ $m->volume_unit }}): {{ rtrim(rtrim((string) $m->baseline_volume, '0'), '.') }} in the baseline, {{ rtrim(rtrim((string) $m->measure_volume, '0'), '.') }} in the window.</p>@endif
                <p><strong>Evidence:</strong> {{ $m->evidence }}</p>
                <p class="text-slate-500">Measured by {{ $m->maker?->name }}@if ($m->submitted_at) · sent to Finance {{ $m->submitted_at->format('d-m-Y H:i') }}@endif</p>
                @if ($m->decided_at)<p class="text-slate-500">{{ $m->state === 'verified' ? 'Verified' : 'Rejected' }} by {{ $m->decider?->name }} on {{ $m->decided_at->format('d-m-Y H:i') }}: {{ $m->decision_notes }}</p>@endif
            </div>
        </section>
    @endif

    @if ($can['decide'])
        <section class="card" aria-labelledby="sv-dec">
            <div class="card-header"><h2 id="sv-dec" class="text-sm font-semibold text-slate-900">Finance decision</h2></div>
            <div class="card-body space-y-3">
                <div>
                    <label for="sv-notes" class="label">What you checked, or why you reject it</label>
                    <textarea id="sv-notes" rows="2" wire:model="notes" class="input" maxlength="2000"></textarea>
                    @error('notes')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="flex gap-2">
                    <button type="button" wire:click="verify" class="btn-primary">Verify ₹ {{ Format::number($m->net_saving, 2) }}</button>
                    <button type="button" wire:click="rejectMeasurement" class="btn-secondary">Reject</button>
                </div>
            </div>
        </section>
    @endif

    @if ($can['reopen'])
        <button type="button" wire:click="reopenDraft" class="btn-secondary">Re-open as a draft</button>
    @endif
</div>

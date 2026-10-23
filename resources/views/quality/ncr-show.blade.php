@php $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
<div class="mx-auto max-w-4xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('quality.ncrs.index') }}" wire:navigate class="hover:text-brand-700">Non-conformances</a> › {{ $ncr->location?->name }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $ncr->item_name }} <span class="font-mono text-base font-normal text-slate-500">{{ $ncr->ncr_number }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span @class(['badge', 'bg-slate-100 text-slate-700' => $ncr->severity === 'minor', 'bg-amber-100 text-amber-800' => $ncr->severity === 'major', 'bg-rose-100 text-rose-800' => $ncr->severity === 'critical'])>{{ $severities[$ncr->severity] }}</span>
                <span class="badge bg-slate-100 text-slate-700">{{ $states[$ncr->state] }}</span>
                <span class="tabular-nums text-slate-600">{{ $qty($ncr->quantity_affected) }} affected{{ $ncr->batch ? ' · batch '.$ncr->batch->batch_no.' ('.$ncr->batch->status.')' : '' }}@if ($ncr->inspection) · from <a href="{{ route('quality.inspections.show', $ncr->inspection) }}" wire:navigate class="font-mono text-xs text-brand-700 hover:underline">{{ $ncr->inspection->inspection_number }}</a>@endif</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">@if ($canClose)<button type="button" wire:click="openClose" class="btn-primary">Close the report…</button>@endif</div>
    </div>

    <x-status />
    @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <section class="card card-body text-sm text-slate-700"><p class="text-xs text-slate-500">What went wrong</p><p class="mt-1 whitespace-pre-line">{{ $ncr->description }}</p></section>

    @if ($ncr->state === 'closed')<div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">Closed {{ $ncr->closed_at?->format('d-m-Y') }}: {{ $ncr->closing_note }}</div>@endif

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card" aria-labelledby="s-disp">
            <div class="card-header"><h2 id="s-disp" class="text-sm font-semibold text-slate-900">What is to be done with the goods</h2></div>
            @if ($canEdit)
                <form wire:submit="decide" novalidate>
                    <div class="card-body space-y-3">
                        <div>
                            <label for="nd-disp" class="label">Decision</label>
                            <select id="nd-disp" wire:model="disposition" class="input"><option value="">Select…</option>@foreach ($dispositions as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>
                            @error('disposition')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="nd-note" class="label">How, or why</label>
                            <textarea id="nd-note" rows="2" wire:model="dispositionNote" class="input" maxlength="500"></textarea>
                            @error('note')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <p class="text-xs text-slate-500">“Use as is” is a deviation: it needs the right to accept a failed lot, and it releases the batch.</p>
                    </div>
                    <div class="flex justify-end border-t border-slate-200 px-5 py-3"><button type="submit" class="btn-primary" wire:loading.attr="disabled">Record the decision</button></div>
                </form>
            @else
                <div class="card-body text-sm text-slate-700">{{ $ncr->disposition ? $dispositions[$ncr->disposition].' — '.$ncr->disposition_note : 'Not decided yet.' }}</div>
            @endif
        </section>

        <section class="card" aria-labelledby="s-link">
            <div class="card-header"><h2 id="s-link" class="text-sm font-semibold text-slate-900">Loss incident and corrective action</h2></div>
            @if ($canEdit)
                <form wire:submit="linkRecords" novalidate>
                    <div class="card-body space-y-3">
                        <div>
                            <label for="nl-inc" class="label">Loss incident</label>
                            <select id="nl-inc" wire:model.live="incidentId" class="input"><option value="">None</option>@foreach ($incidents as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                        </div>
                        <div>
                            <label for="nl-act" class="label">Corrective action</label>
                            <select id="nl-act" wire:model="actionId" class="input"><option value="">None</option>@foreach ($actions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                        </div>
                        <p class="text-xs text-slate-500">A critical report needs a corrective action before it can be closed.</p>
                    </div>
                    <div class="flex justify-end border-t border-slate-200 px-5 py-3"><button type="submit" class="btn-secondary" wire:loading.attr="disabled">Save the links</button></div>
                </form>
            @else
                <div class="card-body text-sm text-slate-700">{{ $ncr->lossIncident ? $ncr->lossIncident->incident_number.' · '.$ncr->lossIncident->title : 'No loss incident linked.' }}<br>{{ $ncr->correctiveAction ? $ncr->correctiveAction->action_number.' · '.$ncr->correctiveAction->title : 'No corrective action linked.' }}</div>
            @endif
        </section>
    </div>

    @if ($holds->isNotEmpty())
        <section class="card" aria-labelledby="s-holds">
            <div class="card-header"><h2 id="s-holds" class="text-sm font-semibold text-slate-900">Hold and release of batch {{ $ncr->batch?->batch_no }}</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($holds as $h)
                    <li class="flex justify-between gap-3 px-5 py-2" wire:key="bh-{{ $h->id }}"><span>{{ $h->action === 'hold' ? 'Put on hold' : 'Released' }} — {{ $h->reason }}</span><span class="tabular-nums text-xs text-slate-500">{{ $h->at->format('d-m-Y H:i') }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($history->isNotEmpty())
        <section class="card" aria-labelledby="s-hist">
            <div class="card-header"><h2 id="s-hist" class="text-sm font-semibold text-slate-900">Audit trail</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($history as $log)
                    <li class="flex justify-between gap-3 px-5 py-2" wire:key="h-{{ $log->id }}"><span>{{ str_replace('_', ' ', ucfirst((string) ($log->event ?? $log->description))) }} · {{ $log->causer?->name ?? 'System' }}</span><span class="tabular-nums text-xs text-slate-500">{{ $log->created_at?->format('d-m-Y H:i') }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($closing)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="c-title">
            <form wire:submit="closeReport" class="card w-full max-w-lg" novalidate>
                <div class="card-header"><h2 id="c-title" class="text-sm font-semibold text-slate-900">Close {{ $ncr->ncr_number }}</h2></div>
                <div class="card-body">
                    <label for="c-note" class="label">How was it resolved?</label>
                    <textarea id="c-note" rows="3" wire:model="closingNote" class="input" maxlength="500"></textarea>
                    @error('note')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancelClose" class="btn-secondary">Back</button>
                    <button type="submit" class="btn-primary">Close the report</button>
                </div>
            </form>
        </div>
    @endif
</div>

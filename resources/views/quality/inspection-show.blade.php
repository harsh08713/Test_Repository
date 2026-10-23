@php $trim = fn ($v) => $v === null ? '' : (rtrim(rtrim((string) $v, '0'), '.') ?: '0'); @endphp
<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('quality.inspections.index') }}" wire:navigate class="hover:text-brand-700">Inspections</a> › {{ $inspection->location?->name }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $inspection->item_name }} <span class="font-mono text-base font-normal text-slate-500">{{ $inspection->inspection_number }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span @class(['badge', 'bg-amber-100 text-amber-800' => in_array($inspection->state, ['open', 'failed'], true), 'bg-emerald-100 text-emerald-800' => in_array($inspection->state, ['accepted', 'deviation'], true), 'bg-rose-100 text-rose-800' => $inspection->state === 'rejected', 'bg-slate-100 text-slate-700' => $inspection->state === 'cancelled'])>{{ $states[$inspection->state] }}</span>
                <span class="tabular-nums text-slate-600">Plan {{ $inspection->plan?->code }} · lot {{ $trim($inspection->lot_quantity) }}{{ $inspection->batch ? ' · batch '.$inspection->batch->batch_no : '' }}{{ $inspection->source_label ? ' · from '.$inspection->source_label : '' }}</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canDecide)<button type="button" wire:click="openDialog('reject')" class="btn-secondary text-rose-700">Reject the lot…</button>@endif
            @if ($canDeviate)<button type="button" wire:click="openDialog('deviation')" class="btn-secondary">Accept with a deviation…</button>@endif
            @if ($canRecord)<button type="button" wire:click="openDialog('cancel')" class="btn-ghost text-rose-600">Cancel…</button>@endif
        </div>
    </div>

    <x-status />
    @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('results')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @if ($inspection->state === 'failed')<div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">One or more checks are outside their limits. The batch stays on hold until the lot is rejected or accepted with a deviation.</div>@endif
    @if ($inspection->decision_reason)<div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">{{ $inspection->decision_reason }}</div>@endif
    @if ($ncr)<p class="text-sm text-slate-600">Non-conformance report: <a href="{{ route('quality.ncrs.show', $ncr) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $ncr->ncr_number }}</a></p>@endif

    <section class="card" aria-labelledby="s-res">
        <div class="card-header"><h2 id="s-res" class="text-sm font-semibold text-slate-900">Checks</h2></div>
        <form wire:submit="saveResults" novalidate>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>#</th><th>Check</th><th class="text-right">Allowed</th><th>Result</th><th>Note</th><th>Verdict</th></tr></thead>
                    <tbody>
                        @foreach ($inspection->results as $r)
                            <tr wire:key="ir-{{ $r->id }}">
                                <td class="tabular-nums text-slate-500">{{ $r->line_no }}</td>
                                <td class="font-medium text-slate-900">{{ $r->name }}@if ($r->is_critical) <span class="badge bg-rose-100 text-rose-800">critical</span>@endif</td>
                                <td class="text-right tabular-nums">@if ($r->kind === 'measure'){{ $r->min_value !== null ? $trim($r->min_value) : '…' }} to {{ $r->max_value !== null ? $trim($r->max_value) : '…' }} {{ $r->unit_text }}@else pass @endif</td>
                                <td>
                                    @if ($canRecord)
                                        @if ($r->kind === 'measure')
                                            <label class="sr-only" for="ir{{ $r->id }}-v">{{ $r->name }}</label>
                                            <input id="ir{{ $r->id }}-v" type="text" inputmode="decimal" wire:model="entry.{{ $r->id }}.value" class="input w-28 tabular-nums" autocomplete="off">
                                        @else
                                            <label class="sr-only" for="ir{{ $r->id }}-c">{{ $r->name }}</label>
                                            <select id="ir{{ $r->id }}-c" wire:model="entry.{{ $r->id }}.check" class="input w-28"><option value="">—</option><option value="pass">Pass</option><option value="fail">Fail</option></select>
                                        @endif
                                        @error("results.$r->id.value")<p class="error">{{ $message }}</p>@enderror
                                    @else
                                        <span class="tabular-nums">{{ $r->kind === 'measure' ? $trim($r->measured_value) : ($r->passed === null ? '' : ($r->passed ? 'Pass' : 'Fail')) }}</span>
                                    @endif
                                </td>
                                <td>@if ($canRecord)<input type="text" wire:model="entry.{{ $r->id }}.note" class="input" maxlength="200" aria-label="Note for {{ $r->name }}">@else<span class="text-slate-600">{{ $r->note }}</span>@endif</td>
                                <td>@if ($r->passed === true)<span class="badge bg-emerald-100 text-emerald-800">Within limits</span>@elseif ($r->passed === false)<span class="badge bg-rose-100 text-rose-800">Outside</span>@else<span class="text-xs text-slate-400">not recorded</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($canRecord)
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="submit" class="btn-secondary" wire:loading.attr="disabled">Save results</button>
                    <button type="button" wire:click="completeInspection" class="btn-primary" wire:loading.attr="disabled">Complete the inspection</button>
                </div>
            @endif
        </form>
    </section>

    @if ($holds->isNotEmpty())
        <section class="card" aria-labelledby="s-holds">
            <div class="card-header"><h2 id="s-holds" class="text-sm font-semibold text-slate-900">Hold and release of batch {{ $inspection->batch?->batch_no }}</h2></div>
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

    @if ($dialog !== '')
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="d-title">
            <form wire:submit="finishDialog" class="card w-full max-w-lg" novalidate>
                <div class="card-header"><h2 id="d-title" class="text-sm font-semibold text-slate-900">{{ ['reject' => 'Reject the lot', 'deviation' => 'Accept the lot with a deviation', 'cancel' => 'Cancel the inspection'][$dialog] }} — {{ $inspection->inspection_number }}</h2></div>
                <div class="card-body">
                    <label for="d-reason" class="label">{{ $dialog === 'deviation' ? 'Why is the failed lot accepted anyway? (at least 10 characters)' : ($dialog === 'reject' ? 'Note (optional)' : 'Why?') }}</label>
                    <textarea id="d-reason" rows="3" wire:model="reason" class="input" maxlength="500"></textarea>
                    @error('reason')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeDialog" class="btn-secondary">Back</button>
                    <button type="submit" class="btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    @endif
</div>

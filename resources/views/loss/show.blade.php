@php
    use App\Modules\Shared\Support\Format;

    $qty = fn ($v) => $v === null ? '' : (rtrim(rtrim((string) $v, '0'), '.') ?: '0');
    $sevClass = ['low' => 'bg-slate-100 text-slate-700', 'medium' => 'bg-amber-100 text-amber-800', 'high' => 'bg-orange-100 text-orange-800', 'critical' => 'bg-rose-100 text-rose-800'];
    $stateClass = ['open' => 'bg-slate-100 text-slate-700', 'in_progress' => 'bg-sky-100 text-sky-800', 'done' => 'bg-violet-100 text-violet-800', 'verified' => 'bg-emerald-100 text-emerald-800', 'rejected' => 'bg-rose-100 text-rose-800'];
@endphp

<div class="mx-auto max-w-6xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('loss.incidents.index') }}" wire:navigate class="hover:text-brand-700">Loss incidents</a> › {{ $incident->location?->name }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $incident->title }} <span class="font-mono text-base font-normal text-slate-500">{{ $incident->incident_number }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span class="badge bg-brand-50 text-brand-700">{{ $stateName }}</span>
                <span class="badge {{ $sevClass[$incident->severity] ?? '' }}">{{ ucfirst($incident->severity) }}</span>
                @if ($incident->is_safety)<span class="badge bg-rose-100 text-rose-800">Safety</span>@endif
                @if ($incident->is_quality)<span class="badge bg-amber-100 text-amber-800">Quality</span>@endif
                @if ($incident->is_compliance)<span class="badge bg-violet-100 text-violet-800">Compliance</span>@endif
                <span class="text-slate-600">{{ $incident->template?->name }} · {{ $incident->category?->name }} · template version {{ $incident->template_version }}</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($can['edit'])<a href="{{ route('loss.incidents.edit', $incident) }}" wire:navigate class="btn-secondary">Edit</a>@endif
            @foreach ($moves as $move)
                <button type="button" wire:click="openDialog('move', '{{ $move->to_state }}')" class="btn-primary">{{ ucfirst(str_replace('_', ' ', $move->action)) }} → {{ $states->firstWhere('code', $move->to_state)?->name }}</button>
            @endforeach
        </div>
    </div>

    <x-status />
    @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if ($incident->isDuplicate())
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            Another incident already covers the same record: <a href="{{ route('loss.incidents.show', $incident->primary_incident_id) }}" class="font-mono underline">{{ $incident->primary?->incident_number }}</a>. This one is kept, but its figures are not counted, so the loss is not counted twice.
        </div>
    @endif
    @if ($incident->needsImmediateEscalation() && $incident->contained_at === null)
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">This is a {{ $incident->severity === 'critical' ? 'critical' : 'safety, quality or compliance' }} risk and has not been contained yet.{{ $incident->escalated_at ? ' The loss managers were told on '.$incident->escalated_at->format('d-m-Y H:i').'.' : '' }}</div>
    @endif

    {{-- the four values, never added together --}}
    <dl class="grid gap-3 sm:grid-cols-4">
        @foreach ($totals as $type => $t)
            <div class="card px-5 py-3">
                <dt class="text-xs text-slate-500">{{ $t['label'] }}</dt>
                <dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($type === 'actual' ? $t['validated'] : $t['amount'], 2) }}</dd>
                @if ($type === 'actual' && $t['amount'] !== $t['validated'])<dd class="text-xs text-slate-500">₹ {{ Format::number($t['amount'], 2) }} including figures not validated yet</dd>@endif
            </div>
        @endforeach
        <div class="card px-5 py-3">
            <dt class="text-xs text-slate-500">Verified savings</dt>
            <dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number(bcadd((string) $actions->where('state', 'verified')->sum('verified_result_amount'), '0', 2), 2) }}</dd>
        </div>
    </dl>

    <div class="grid gap-5 lg:grid-cols-3">
        <section class="card lg:col-span-2" aria-labelledby="s-what">
            <div class="card-header"><h2 id="s-what" class="text-sm font-semibold text-slate-900">What happened</h2></div>
            <div class="card-body space-y-3 text-sm">
                <p class="whitespace-pre-line text-slate-800">{{ $incident->description }}</p>
                <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-3">
                    <div><dt class="text-xs text-slate-500">When</dt><dd>{{ $incident->occurred_at->format('d-m-Y H:i') }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Reported</dt><dd>{{ $incident->reported_at->format('d-m-Y H:i') }} by {{ $incident->reporter?->name }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Department / process</dt><dd>{{ $incident->department?->name ?? '—' }}{{ $incident->process ? ' · '.$incident->process : '' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Item</dt><dd>{{ $incident->item ? trim($incident->item->code.' · '.$incident->item->name) : '—' }}{{ $incident->batch ? ' · batch '.$incident->batch->batch_no : '' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Quantity</dt><dd>{{ $incident->quantity !== null ? $qty($incident->quantity).' '.$incident->unit?->code : '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Asset</dt><dd>{{ $incident->asset_ref ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Cost centre</dt><dd>{{ $incident->costCenter?->name ?? '—' }}</dd></div>
                    @if ($sourceLabel)<div class="sm:col-span-3"><dt class="text-xs text-slate-500">Reported from</dt><dd>{{ $sourceLabel }}</dd></div>@endif
                    @foreach ($incident->custom_fields ?? [] as $key => $value)
                        <div><dt class="text-xs text-slate-500">{{ ucfirst(str_replace('_', ' ', $key)) }}</dt><dd>{{ is_bool($value) ? ($value ? 'Yes' : 'No') : (is_array($value) ? implode(', ', $value) : $value) }}</dd></div>
                    @endforeach
                </dl>
            </div>
        </section>

        <section class="card" aria-labelledby="s-who">
            <div class="card-header"><h2 id="s-who" class="text-sm font-semibold text-slate-900">Who and containment</h2></div>
            <div class="card-body space-y-3 text-sm">
                @if ($can['assign'])
                    <form wire:submit="saveAssignment" class="space-y-2">
                        <label for="s-inv" class="label">Investigator</label>
                        <select id="s-inv" wire:model="investigatorId" class="input">
                            <option value="">—</option>
                            @foreach ($investigators as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                        </select>
                        <label for="s-own" class="label">Accountable owner</label>
                        <select id="s-own" wire:model="ownerId" class="input">
                            <option value="">—</option>
                            @foreach ($investigators as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                        </select>
                        <button type="submit" class="btn-secondary btn-sm">Save</button>
                    </form>
                @else
                    <p><span class="text-xs text-slate-500">Investigator</span><br>{{ $incident->investigator?->name ?? '—' }}</p>
                    <p><span class="text-xs text-slate-500">Accountable owner</span><br>{{ $incident->owner?->name ?? '—' }}</p>
                @endif
                <div class="border-t border-slate-100 pt-3">
                    <span class="text-xs text-slate-500">Containment</span>
                    @if ($incident->contained_at)<p class="text-emerald-700">Contained {{ $incident->contained_at->format('d-m-Y H:i') }}</p>@endif
                    @if ($can['investigate'])
                        <form wire:submit="saveContainment" class="mt-1 space-y-2">
                            <label for="s-cont" class="sr-only">What was done to contain it</label>
                            <textarea id="s-cont" rows="2" wire:model="containment" class="input" placeholder="What was done to stop it getting worse…"></textarea>
                            @error('containment_notes')<p class="error">{{ $message }}</p>@enderror
                            <button type="submit" class="btn-secondary btn-sm">Record containment</button>
                        </form>
                    @else
                        <p class="whitespace-pre-line">{{ $incident->containment_notes ?? 'Not recorded.' }}</p>
                    @endif
                </div>
            </div>
        </section>
    </div>

    <section class="card" aria-labelledby="s-money">
        <div class="card-header">
            <h2 id="s-money" class="text-sm font-semibold text-slate-900">Figures</h2>
            @if ($can['investigate'])<button type="button" wire:click="openDialog('impact')" class="btn-secondary btn-sm">Add a figure</button>@endif
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Kind</th><th>Worked out as</th><th class="text-right">Amount (₹)</th><th>Assumptions</th><th>Validation</th><th class="text-right"></th></tr></thead>
                <tbody>
                    @forelse ($lines as $l)
                        <tr wire:key="il-{{ $l->id }}" @class(['opacity-50' => ! $l->isCurrent()])>
                            <td>{{ $l->typeLabel() }}</td>
                            <td class="text-xs">{{ $formulas[$l->formula_key]['label'] ?? $l->formula_key }} <span class="text-slate-400">v{{ $l->formula_version }}</span>
                                <span class="block text-slate-500">{{ collect($l->inputs)->map(fn ($v, $k) => str_replace('_', ' ', $k).' '.$v)->implode(', ') }}</span></td>
                            <td @class(['text-right tabular-nums', 'line-through' => ! $l->isCurrent()])>{{ Format::number($l->amount, 2) }}</td>
                            <td class="max-w-xs text-xs text-slate-600">{{ $l->assumptions }}@if ($l->confidence_pct !== null) · {{ $l->confidence_pct }}% confident @endif @if ($l->target_period) · by {{ $l->target_period }}@endif</td>
                            <td class="text-xs">
                                @if (! $l->isCurrent())<span class="text-slate-500">Replaced by a recalculation</span>
                                @else<span @class(['badge', 'bg-emerald-100 text-emerald-800' => $l->validation_state === 'validated', 'bg-rose-100 text-rose-800' => $l->validation_state === 'rejected', 'bg-slate-100 text-slate-700' => $l->validation_state === 'pending'])>{{ ucfirst($l->validation_state) }}</span>
                                    <span class="block text-slate-500">by {{ $l->creator?->name }}{{ $l->validator ? ', decided by '.$l->validator->name : '' }}</span>@endif
                            </td>
                            <td class="whitespace-nowrap text-right">
                                @if ($l->isCurrent() && $l->validation_state === 'pending')
                                    @if ($can['validate'] && $l->created_by !== $me)
                                        <button type="button" wire:click="validateFigure({{ $l->id }})" class="btn-ghost btn-sm">Validate</button>
                                        <button type="button" wire:click="openDialog('reject-line', '{{ $l->id }}')" class="btn-ghost btn-sm text-rose-600">Reject</button>
                                    @endif
                                @endif
                                @if ($l->isCurrent() && $can['investigate'])<button type="button" wire:click="openDialog('recalc', '{{ $l->id }}')" class="btn-ghost btn-sm">Recalculate</button>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-slate-500">No figures yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="border-t border-slate-100 px-5 py-2 text-xs text-slate-500">Actual loss, exposure and opportunity are kept apart and never added together. Only validated actual figures count as actual loss.</p>
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card" aria-labelledby="s-rca">
            <div class="card-header">
                <h2 id="s-rca" class="text-sm font-semibold text-slate-900">Root cause</h2>
                @if ($can['investigate'])<button type="button" wire:click="openDialog('rca')" class="btn-secondary btn-sm">Record a root cause</button>@endif
            </div>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($analyses as $a)
                    <li class="space-y-1 px-5 py-3" wire:key="rca-{{ $a->id }}">
                        <p class="font-medium text-slate-900">{{ $a->root_cause }}</p>
                        <p class="text-xs text-slate-500">{{ $methods[$a->method] ?? $a->method }}{{ $a->cause_category ? ' · '.($categories[$a->cause_category] ?? $a->cause_category) : '' }} · {{ $a->analyst?->name }}, {{ $a->analysed_at->format('d-m-Y') }}</p>
                        @if ($a->whys)<ol class="list-decimal pl-5 text-slate-700">@foreach ($a->whys as $why)<li>{{ $why }}</li>@endforeach</ol>@endif
                        @if ($a->contributing_factors)<p class="text-slate-700">Contributing: {{ implode('; ', $a->contributing_factors) }}</p>@endif
                        @if ($a->findings)<p class="whitespace-pre-line text-slate-700">{{ $a->findings }}</p>@endif
                    </li>
                @empty
                    <li class="px-5 py-6 text-center text-slate-500">Not recorded yet.</li>
                @endforelse
            </ul>
        </section>

        <section class="card" aria-labelledby="s-capa">
            <div class="card-header">
                <h2 id="s-capa" class="text-sm font-semibold text-slate-900">Corrective and preventive actions</h2>
                @if ($can['addAction'])<button type="button" wire:click="openDialog('action')" class="btn-secondary btn-sm">Add an action</button>@endif
            </div>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($actions as $act)
                    <li class="space-y-1 px-5 py-3" wire:key="ca-{{ $act->id }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-medium text-slate-900"><span class="font-mono text-xs text-slate-500">{{ $act->action_number }}</span> {{ $act->title }}</span>
                            <span class="badge {{ $stateClass[$act->state] ?? '' }}">{{ $act->stateLabel() }}</span>
                        </div>
                        <p class="text-xs text-slate-500">{{ ucfirst($act->action_type) }} · {{ $act->owner?->name }} · due {{ $act->due_date->format('d-m-Y') }}@if ($act->isOverdue()) <span class="font-medium text-rose-700">overdue</span>@endif
                            @if ($act->expected_benefit_amount !== null) · expected ₹ {{ Format::number($act->expected_benefit_amount, 2) }}@endif
                            @if ($act->verified_result_amount !== null) · <span class="text-emerald-700">verified ₹ {{ Format::number($act->verified_result_amount, 2) }}</span>@endif</p>
                        <div class="flex flex-wrap gap-1">
                            @can('work', $act)
                                @if ($act->state === 'open')<button type="button" wire:click="startAction({{ $act->id }})" class="btn-ghost btn-sm">Start</button>@endif
                                @if (in_array($act->state, ['open', 'in_progress'], true))<button type="button" wire:click="openDialog('complete', '{{ $act->id }}')" class="btn-ghost btn-sm">Done…</button>@endif
                            @endcan
                            @can('verify', $act)
                                @if ($act->state === 'done')
                                    <button type="button" wire:click="openDialog('verify', '{{ $act->id }}')" class="btn-ghost btn-sm text-emerald-700">Verify…</button>
                                    <button type="button" wire:click="openDialog('send-back', '{{ $act->id }}')" class="btn-ghost btn-sm">Send back…</button>
                                @endif
                                @if (! $act->isFinished())<button type="button" wire:click="openDialog('reject-action', '{{ $act->id }}')" class="btn-ghost btn-sm text-rose-600">Reject…</button>@endif
                            @endcan
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-6 text-center text-slate-500">No actions yet.</li>
                @endforelse
            </ul>
        </section>
    </div>

    <section class="card" aria-labelledby="s-timeline">
        <div class="card-header"><h2 id="s-timeline" class="text-sm font-semibold text-slate-900">Workflow history</h2></div>
        <ul class="divide-y divide-slate-100 text-sm">
            @foreach ($events as $e)
                <li class="flex flex-wrap justify-between gap-2 px-5 py-2" wire:key="ev-{{ $e->id }}">
                    <span>{{ ucfirst(str_replace('_', ' ', $e->action)) }}: {{ $e->from_state ? ($states->firstWhere('code', $e->from_state)?->name ?? $e->from_state).' → ' : '' }}{{ $states->firstWhere('code', $e->to_state)?->name ?? $e->to_state }}@if ($e->reason) — <span class="text-slate-600">{{ $e->reason }}</span>@endif</span>
                    <span class="text-xs text-slate-500">{{ $e->actor?->name ?? 'System' }} · {{ $e->created_at?->format('d-m-Y H:i') }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    <livewire:shared.comments type="loss_incident" :id="$incident->id" :key="'cm-'.$incident->id" />

    @if ($history->isNotEmpty())
        <section class="card" aria-labelledby="s-audit">
            <div class="card-header"><h2 id="s-audit" class="text-sm font-semibold text-slate-900">Audit trail</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($history as $entry)
                    <li class="flex justify-between gap-3 px-5 py-2" wire:key="h-{{ $entry->id }}"><span>{{ str_replace('_', ' ', ucfirst((string) ($entry->event ?? $entry->description))) }} · {{ $entry->causer?->name ?? 'System' }}</span><span class="tabular-nums text-xs text-slate-500">{{ $entry->created_at?->format('d-m-Y H:i') }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($dialog !== '')
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="d-title">
            <div class="card w-full max-w-2xl">
                <div class="card-header">
                    <h2 id="d-title" class="text-sm font-semibold text-slate-900">
                        @switch($dialog)
                            @case('move') Move to {{ $states->firstWhere('code', $target)?->name }} @break
                            @case('impact') Add a figure @break
                            @case('recalc') Recalculate the figure @break
                            @case('reject-line') Reject the figure @break
                            @case('rca') Record a root cause @break
                            @case('action') Add a corrective or preventive action @break
                            @case('complete') Say the action is done @break
                            @case('verify') Verify the action @break
                            @case('send-back') Send the action back @break
                            @default Reject the action
                        @endswitch
                    </h2>
                    <button type="button" wire:click="closeDialog" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>

                @error('workflow')<p class="mx-5 mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror

                @if (in_array($dialog, ['impact', 'recalc'], true))
                    @include('loss.partials.impact-fields', ['prop' => 'line', 'formula' => $formulas[$line['formula_key'] ?? ''] ?? null, 'lockType' => $dialog === 'recalc', 'kind' => $line['impact_type'] ?? 'actual'])
                    <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                        <button type="button" wire:click="closeDialog" class="btn-secondary">Cancel</button>
                        <button type="button" wire:click="{{ $dialog === 'impact' ? 'addImpact' : 'recalculateLine' }}" class="btn-primary">{{ $dialog === 'impact' ? 'Add' : 'Recalculate' }}</button>
                    </div>
                @elseif ($dialog === 'rca')
                    <div class="card-body grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="r-method" class="label">Method</label>
                            <select id="r-method" wire:model.live="rca.method" class="input">@foreach ($methods as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                            @error('rca.method')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="r-cat" class="label">Cause category{{ ($rca['method'] ?? '') === 'fishbone' ? '' : ' (optional)' }}</label>
                            <select id="r-cat" wire:model="rca.cause_category" class="input"><option value="">—</option>@foreach ($categories as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                            @error('rca.cause_category')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        @if (($rca['method'] ?? '') === 'five_whys')
                            @foreach (range(0, 4) as $i)
                                <div class="sm:col-span-2" wire:key="why-{{ $i }}">
                                    <label for="r-why{{ $i }}" class="label">Why {{ $i + 1 }}?</label>
                                    <input id="r-why{{ $i }}" type="text" wire:model="rca.whys.{{ $i }}" class="input" maxlength="500">
                                </div>
                            @endforeach
                            @error('rca.whys')<p class="error sm:col-span-2">{{ $message }}</p>@enderror
                        @else
                            <div class="sm:col-span-2">
                                <label for="r-factors" class="label">Contributing factors (one a line)</label>
                                <textarea id="r-factors" rows="3" wire:model="rca.contributing_factors" class="input"></textarea>
                            </div>
                        @endif
                        <div class="sm:col-span-2">
                            <label for="r-root" class="label">Root cause</label>
                            <textarea id="r-root" rows="2" wire:model="rca.root_cause" class="input" maxlength="2000"></textarea>
                            @error('rca.root_cause')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="r-find" class="label">Findings and evidence (attach files as comments)</label>
                            <textarea id="r-find" rows="3" wire:model="rca.findings" class="input" maxlength="5000"></textarea>
                            @error('rca.findings')<p class="error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                        <button type="button" wire:click="closeDialog" class="btn-secondary">Cancel</button>
                        <button type="button" wire:click="saveRootCause" class="btn-primary">Record</button>
                    </div>
                @elseif ($dialog === 'action')
                    <div class="card-body grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="c-type" class="label">Kind</label>
                            <select id="c-type" wire:model="capa.action_type" class="input"><option value="corrective">Corrective (fix this one)</option><option value="preventive">Preventive (stop it happening again)</option></select>
                        </div>
                        <div>
                            <label for="c-due" class="label">Due by</label>
                            <input id="c-due" type="date" wire:model="capa.due_date" min="{{ now()->toDateString() }}" class="input">
                            @error('capa.due_date')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="c-title" class="label">What will be done</label>
                            <input id="c-title" type="text" wire:model="capa.title" class="input" maxlength="200">
                            @error('capa.title')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="c-desc" class="label">Details</label>
                            <textarea id="c-desc" rows="2" wire:model="capa.description" class="input" maxlength="5000"></textarea>
                        </div>
                        <div>
                            <label for="c-owner" class="label">Owner</label>
                            <select id="c-owner" wire:model="capa.owner_id" class="input"><option value="">Select…</option>@foreach ($actionOwners as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
                            @error('capa.owner_id')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="c-appr" class="label">Verified by (optional)</label>
                            <select id="c-appr" wire:model="capa.approver_id" class="input"><option value="">Anyone who may verify</option>@foreach ($verifiers as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
                            @error('capa.approver_id')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="c-benefit" class="label">Expected saving (₹)</label>
                            <input id="c-benefit" type="text" inputmode="decimal" wire:model="capa.expected_benefit_amount" class="input tabular-nums">
                            @error('capa.expected_benefit_amount')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="c-base" class="label">Baseline (₹)</label>
                            <input id="c-base" type="text" inputmode="decimal" wire:model="capa.baseline_value" class="input tabular-nums">
                            @error('capa.baseline_value')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        @if ($analyses->isNotEmpty())
                            <div class="sm:col-span-2">
                                <label for="c-rca" class="label">Answers the root cause</label>
                                <select id="c-rca" wire:model="capa.root_cause_analysis_id" class="input"><option value="">—</option>@foreach ($analyses as $a)<option value="{{ $a->id }}">{{ \Illuminate\Support\Str::limit($a->root_cause, 80) }}</option>@endforeach</select>
                            </div>
                        @endif
                    </div>
                    <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                        <button type="button" wire:click="closeDialog" class="btn-secondary">Cancel</button>
                        <button type="button" wire:click="addAction" class="btn-primary">Add</button>
                    </div>
                @else
                    <div class="card-body space-y-3">
                        @if ($dialog === 'verify')
                            <div>
                                <label for="d-amount" class="label">Verified saving (₹, 0 if none)</label>
                                <input id="d-amount" type="text" inputmode="decimal" wire:model="resultAmount" class="input tabular-nums">
                                @error('resultAmount')<p class="error">{{ $message }}</p>@enderror
                            </div>
                        @endif
                        <div>
                            <label for="d-comment" class="label">{{ match ($dialog) { 'move' => 'Comment', 'complete' => 'What was done', 'verify' => 'How it was verified', default => 'Why' } }}</label>
                            <textarea id="d-comment" rows="3" wire:model="comment" class="input" maxlength="2000"></textarea>
                            @error('comment')<p class="error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                        <button type="button" wire:click="closeDialog" class="btn-secondary">Cancel</button>
                        <button type="button" wire:click="{{ match ($dialog) { 'move' => 'makeMove', 'reject-line' => 'rejectFigure', 'complete' => 'completeAction', 'verify' => 'verifyAction', 'send-back' => 'sendBackAction', default => 'rejectAction' } }}" class="btn-primary">Confirm</button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>

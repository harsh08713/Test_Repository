<div class="mx-auto max-w-5xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('loss.incidents.index') }}" wire:navigate class="hover:text-brand-700">Loss incidents</a></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $editing ? 'Change the loss incident' : 'Report a loss' }}</h1>
        <p class="mt-1 text-sm text-slate-500">What happened, where, how bad. A safety, quality or compliance risk — or anything critical — is escalated to the loss managers the moment it is saved.</p>
    </div>

    @error('form')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @if ($sourceLabel)
        <div class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900">Reported from: {{ $sourceLabel }}. What it says has been filled in below; change anything that is not right.</div>
    @endif

    @if ($templates->isEmpty())
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Your company has not switched on an industry template yet. An administrator does that under <a href="{{ route('industries.index') }}" class="underline">Industries</a>.</div>
    @endif

    <form wire:submit="save" class="space-y-5" novalidate>
        <section class="card" aria-labelledby="f-what">
            <div class="card-header"><h2 id="f-what" class="text-sm font-semibold text-slate-900">What kind of loss</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="f-tpl" class="label">Industry template</label>
                    <select id="f-tpl" wire:model.live="incident.industry_template_id" class="input" @disabled($editing)>
                        <option value="">Select…</option>
                        @foreach ($templates as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                    </select>
                    @error('incident.industry_template_id')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="f-cat" class="label">Loss category</label>
                    <select id="f-cat" wire:model="incident.loss_category_id" class="input">
                        <option value="">Select…</option>
                        @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select>
                    @error('incident.loss_category_id')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="f-title" class="label">Title</label>
                    <input id="f-title" type="text" wire:model="incident.title" class="input" maxlength="200">
                    @error('incident.title')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="f-desc" class="label">What happened</label>
                    <textarea id="f-desc" rows="3" wire:model="incident.description" class="input" maxlength="5000"></textarea>
                    @error('incident.description')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="f-sev" class="label">Severity</label>
                    <select id="f-sev" wire:model="incident.severity" class="input">
                        @foreach ($severities as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    @error('incident.severity')<p class="error">{{ $message }}</p>@enderror
                </div>
                <fieldset>
                    <legend class="label">It is a risk to</legend>
                    <div class="flex flex-wrap gap-4 text-sm">
                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="incident.is_safety" class="rounded border-slate-300 text-brand-600"> Safety</label>
                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="incident.is_quality" class="rounded border-slate-300 text-brand-600"> Quality</label>
                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="incident.is_compliance" class="rounded border-slate-300 text-brand-600"> Compliance</label>
                    </div>
                </fieldset>
            </div>
        </section>

        <section class="card" aria-labelledby="f-where">
            <div class="card-header"><h2 id="f-where" class="text-sm font-semibold text-slate-900">Where, when and what</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="f-loc" class="label">Branch / site</label>
                    <select id="f-loc" wire:model="incident.location_id" class="input">
                        <option value="">Select…</option>
                        @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('incident.location_id')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="f-dep" class="label">Department</label>
                    <select id="f-dep" wire:model="incident.department_id" class="input">
                        <option value="">—</option>
                        @foreach ($departments as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="f-cc" class="label">Charge to cost centre</label>
                    <select id="f-cc" wire:model="incident.cost_center_id" class="input">
                        <option value="">—</option>
                        @foreach ($costCenters as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('incident.cost_center_id')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="f-when" class="label">When it happened</label>
                    <input id="f-when" type="datetime-local" wire:model="incident.occurred_at" max="{{ now()->format('Y-m-d\TH:i') }}" class="input">
                    @error('incident.occurred_at')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="f-proc" class="label">Process</label>
                    <input id="f-proc" type="text" wire:model="incident.process" class="input" maxlength="150">
                </div>
                <div>
                    <label for="f-asset" class="label">Asset / machine</label>
                    <input id="f-asset" type="text" wire:model="incident.asset_ref" class="input" maxlength="100">
                </div>
                <div></div>
                <div class="sm:col-span-2">
                    @if ($item)
                        <span class="label">Item</span>
                        <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                            <span class="truncate text-slate-900">{{ $itemLabel }}</span>
                            <button type="button" wire:click="clearItem" class="shrink-0 text-xs text-brand-700 hover:underline">Change</button>
                        </div>
                    @else
                        <label for="f-item" class="label">Item (if any)</label>
                        <input id="f-item" type="search" wire:model.live.debounce.300ms="itemSearch" class="input" autocomplete="off" placeholder="Code or name…">
                        @if ($results->isNotEmpty())
                            <ul class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                                @foreach ($results as $hit)<li><button type="button" wire:click="pickItem({{ $hit->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">{{ trim($hit->code.' · '.$hit->name) }}</button></li>@endforeach
                            </ul>
                        @endif
                    @endif
                    @error('incident.item_id')<p class="error">{{ $message }}</p>@enderror
                </div>
                @if ($batches !== [])
                    <div>
                        <label for="f-batch" class="label">Batch</label>
                        <select id="f-batch" wire:model="incident.batch_id" class="input">
                            <option value="">—</option>
                            @foreach ($batches as $id => $no)<option value="{{ $id }}">{{ $no }}</option>@endforeach
                        </select>
                        @error('incident.batch_id')<p class="error">{{ $message }}</p>@enderror
                    </div>
                @endif
                <div>
                    <label for="f-qty" class="label">Quantity</label>
                    <input id="f-qty" type="text" inputmode="decimal" wire:model="incident.quantity" class="input tabular-nums">
                    @error('incident.quantity')<p class="error">{{ $message }}</p>@enderror
                </div>
                @if ($units !== [])
                    <div>
                        <label for="f-unit" class="label">Unit</label>
                        <select id="f-unit" wire:model="incident.unit_id" class="input">
                            <option value="">—</option>
                            @foreach ($units as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                    </div>
                @endif
            </div>
        </section>

        @if ($fields->isNotEmpty())
            <section class="card" aria-labelledby="f-custom">
                <div class="card-header"><h2 id="f-custom" class="text-sm font-semibold text-slate-900">What this industry also records</h2></div>
                <div class="card-body grid gap-4 sm:grid-cols-2">
                    @foreach ($fields as $field)
                        <div wire:key="cf-{{ $field->key }}">
                            @switch($field->type)
                                @case('boolean')
                                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="custom.{{ $field->key }}" class="rounded border-slate-300 text-brand-600"> {{ $field->label }}@if ($field->is_required) <span class="text-rose-600">*</span>@endif</label>
                                    @break
                                @case('select')
                                    <label for="cf-{{ $field->key }}" class="label">{{ $field->label }}@if ($field->is_required) <span class="text-rose-600">*</span>@endif</label>
                                    <select id="cf-{{ $field->key }}" wire:model="custom.{{ $field->key }}" class="input">
                                        <option value="">—</option>
                                        @foreach ($field->options ?? [] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                                    </select>
                                    @break
                                @case('multiselect')
                                    <span class="label">{{ $field->label }}@if ($field->is_required) <span class="text-rose-600">*</span>@endif</span>
                                    <div class="flex flex-wrap gap-3 text-sm">
                                        @foreach ($field->options ?? [] as $option)<label class="flex items-center gap-1"><input type="checkbox" value="{{ $option }}" wire:model="custom.{{ $field->key }}" class="rounded border-slate-300 text-brand-600"> {{ $option }}</label>@endforeach
                                    </div>
                                    @break
                                @default
                                    <label for="cf-{{ $field->key }}" class="label">{{ $field->label }}@if ($field->unit) ({{ $field->unit }})@endif @if ($field->is_required) <span class="text-rose-600">*</span>@endif</label>
                                    <input id="cf-{{ $field->key }}" type="{{ match ($field->type) { 'date' => 'date', 'datetime' => 'datetime-local', default => 'text' } }}"
                                           @if (in_array($field->type, ['number', 'decimal'], true)) inputmode="decimal" @endif wire:model="custom.{{ $field->key }}" class="input">
                            @endswitch
                            @error('custom.'.$field->key)<p class="error">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @unless ($editing)
            <section class="card" aria-labelledby="f-impact">
                <div class="card-header">
                    <h2 id="f-impact" class="text-sm font-semibold text-slate-900">First figure (optional)</h2>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="withImpact" class="rounded border-slate-300 text-brand-600"> Add one now</label>
                </div>
                @if ($withImpact)
                    @include('loss.partials.impact-fields', ['prop' => 'impact', 'formula' => $formulas[$impact['formula_key']] ?? null, 'lockType' => false, 'kind' => $impact['impact_type']])
                @else
                    <p class="px-5 py-3 text-sm text-slate-500">Figures can be added later, and are validated by someone other than the person who entered them.</p>
                @endif
            </section>
        @endunless

        <div class="flex justify-end gap-3">
            <a href="{{ $editing ? route('loss.incidents.show', $incidentId) : route('loss.incidents.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $editing ? 'Save' : 'Report the loss' }}</button>
        </div>
    </form>
</div>

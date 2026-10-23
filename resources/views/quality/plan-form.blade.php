<div class="mx-auto max-w-5xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('quality.plans.index') }}" wire:navigate class="hover:text-brand-700">Inspection plans</a></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $editing ? 'Change' : 'New' }} inspection plan</h1>
        <p class="mt-1 text-sm text-slate-500">A measurement passes when it is within the limits you give (leave one limit empty to leave that side open); a pass / fail check passes when the inspector says so. A <em>critical</em> check that fails makes the non-conformance critical.</p>
    </div>

    @error('form')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <form wire:submit="save" class="space-y-5" novalidate>
        <section class="card" aria-labelledby="s-head">
            <div class="card-header"><h2 id="s-head" class="text-sm font-semibold text-slate-900">The plan</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="qp-code" class="label">Code</label>
                    <input id="qp-code" type="text" wire:model="code" class="input uppercase" maxlength="30" placeholder="QP-BOLT-IN">
                    @error('code')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="qp-name" class="label">Name</label>
                    <input id="qp-name" type="text" wire:model="name" class="input" maxlength="150">
                    @error('name')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    @if ($itemId !== '')
                        <span class="label">Item</span>
                        <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                            <span class="truncate text-slate-900">{{ $itemLabel }}</span>
                            <button type="button" wire:click="clearItem" class="shrink-0 text-xs text-brand-700 hover:underline">Change</button>
                        </div>
                    @else
                        <label for="qp-item" class="label">Find the item</label>
                        <input id="qp-item" type="search" wire:model.live.debounce.300ms="itemSearch" class="input" autocomplete="off" placeholder="Code or name…">
                        @if ($itemResults->isNotEmpty())
                            <ul class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                                @foreach ($itemResults as $hit)<li><button type="button" wire:click="pickItem({{ $hit->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">{{ trim($hit->code.' · '.$hit->name) }}</button></li>@endforeach
                            </ul>
                        @endif
                    @endif
                    @error('item_id')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="qp-stage" class="label">Stage</label>
                    <select id="qp-stage" wire:model="stage" class="input">@foreach ($stages as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>
                    @error('stage')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="qp-rem" class="label">Remarks</label>
                    <input id="qp-rem" type="text" wire:model="remarks" class="input" maxlength="1000">
                    @error('remarks')<p class="error">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="s-items">
            <div class="card-header">
                <h2 id="s-items" class="text-sm font-semibold text-slate-900">What to check</h2>
                @if (count($rows) < $max)<button type="button" wire:click="addRow" class="btn-secondary btn-sm">Add a check</button>@endif
            </div>
            <div class="card-body space-y-3">
                @error('items')<p class="error">{{ $message }}</p>@enderror
                @foreach ($rows as $i => $row)
                    <div class="grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-12" wire:key="qr-{{ $row['key'] }}">
                        <div class="sm:col-span-4">
                            <label for="qr{{ $i }}-n" class="label">Check</label>
                            <input id="qr{{ $i }}-n" type="text" wire:model="rows.{{ $i }}.name" class="input" maxlength="120" placeholder="Diameter, surface finish…">
                            @error("items.$i.name")<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="qr{{ $i }}-k" class="label">Kind</label>
                            <select id="qr{{ $i }}-k" wire:model.live="rows.{{ $i }}.kind" class="input"><option value="measure">Measurement</option><option value="check">Pass / fail</option></select>
                            @error("items.$i.kind")<p class="error">{{ $message }}</p>@enderror
                        </div>
                        @if (($row['kind'] ?? 'measure') === 'measure')
                            <div class="sm:col-span-2">
                                <label for="qr{{ $i }}-lo" class="label">Lowest</label>
                                <input id="qr{{ $i }}-lo" type="text" inputmode="decimal" wire:model="rows.{{ $i }}.min_value" class="input tabular-nums" autocomplete="off">
                                @error("items.$i.min_value")<p class="error">{{ $message }}</p>@enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label for="qr{{ $i }}-hi" class="label">Highest</label>
                                <input id="qr{{ $i }}-hi" type="text" inputmode="decimal" wire:model="rows.{{ $i }}.max_value" class="input tabular-nums" autocomplete="off">
                                @error("items.$i.max_value")<p class="error">{{ $message }}</p>@enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label for="qr{{ $i }}-u" class="label">Unit</label>
                                <input id="qr{{ $i }}-u" type="text" wire:model="rows.{{ $i }}.unit_text" class="input" maxlength="20" placeholder="mm">
                            </div>
                        @else
                            <div class="sm:col-span-6"></div>
                        @endif
                        <div class="sm:col-span-6">
                            <label for="qr{{ $i }}-m" class="label">How to check (optional)</label>
                            <input id="qr{{ $i }}-m" type="text" wire:model="rows.{{ $i }}.method" class="input" maxlength="200">
                        </div>
                        <div class="flex items-end gap-4 sm:col-span-6">
                            <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model="rows.{{ $i }}.is_critical" class="checkbox"> Critical</label>
                            @if (count($rows) > 1)<button type="button" wire:click="removeRow({{ $i }})" class="btn-ghost btn-sm text-rose-600">Remove</button>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ route('quality.plans.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save as draft</button>
        </div>
    </form>
</div>

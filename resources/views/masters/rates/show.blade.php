@php
    $state = $rate->periodState($today);
    $inForce = $rate->isApproved() && $rate->status === 'active' && $state === 'current';
    $why = match (true) {
        ! $rate->isApproved() => 'it is not approved yet',
        $rate->status !== 'active' => 'it is switched off',
        $state === 'upcoming' => 'it starts on '.$rate->effective_from->format('j M Y'),
        $state === 'expired' => 'it ended on '.$rate->effective_to?->format('j M Y'),
        default => '',
    };
    $field = fn (string $label, $value, bool $mono = false) => $value === null || $value === '' ? null : ['label' => $label, 'value' => $value, 'mono' => $mono];
    $details = array_values(array_filter([
        $field('Item', $rate->item?->name),
        $field('Item code', $rate->item?->code, true),
        $field('Branch', $rate->location?->name),
        $field('Unit', $rate->unit ? "{$rate->unit->name} ({$rate->unit->code})" : null),
        $field('Price list', $rate->priceList?->name ?? 'Standard (no price list)'),
        $field('Rate', '₹ '.$rate->rateLabel().' per '.$rate->unit?->name.', excluding GST'),
        $field('Effective', $rate->periodLabel()),
        $field('Remarks', $rate->remarks),
    ]));
@endphp

<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('masters.rates.index') }}" wire:navigate class="hover:text-brand-700">Rates</a> › {{ $rate->location?->name }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $rate->item?->name }} <span class="font-normal text-slate-500">· ₹ {{ $rate->rateLabel() }} per {{ $rate->unit?->name }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="badge {{ $rate->approval_status->badge() }}">{{ $rate->approval_status->label() }}</span>
                <span class="badge {{ $rate->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($rate->status) }}</span>
                @if ($inForce)
                    <span class="text-xs text-emerald-700">In force today: quotations and orders can use it</span>
                @else
                    <span class="text-xs text-slate-500">Not offered to quotations or orders today: {{ $why }}</span>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canEdit)<a href="{{ route('masters.rates.edit', $rate) }}" wire:navigate class="btn-primary">Edit</a>@endif
            @if ($canEnd)<button type="button" wire:click="openDialog('end')" class="btn-secondary">End on a date…</button>@endif
            @if ($canReopen)<button type="button" wire:click="openDialog('reopen')" class="btn-secondary">Re-open for editing</button>@endif
            @if ($canCopy)<a href="{{ route('masters.rates.create', ['copy' => $rate->id]) }}" wire:navigate class="btn-secondary">Copy as new rate</a>@endif
            @if ($canChangeStatus)
                <button type="button" wire:click="toggleStatus" class="btn-secondary">{{ $rate->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
            @endif
            @if ($canDelete)<button type="button" wire:click="delete" wire:confirm="Delete this rate?" class="btn-ghost text-rose-600">Delete</button>@endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif
    @error('delete')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if ($missing !== [])
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            Before this can be submitted for approval it still needs: {{ implode('; ', $missing) }}.
        </div>
    @endif

    <section class="card" aria-labelledby="s-details">
        <div class="card-header"><h2 id="s-details" class="text-sm font-semibold text-slate-900">Details</h2></div>
        <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-2">
            @foreach ($details as $d)
                <div>
                    <dt class="text-xs text-slate-500">{{ $d['label'] }}</dt>
                    <dd @class(['text-sm text-slate-900', 'font-mono' => $d['mono']])>{{ $d['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="card" aria-labelledby="s-timeline">
        <div class="card-header">
            <h2 id="s-timeline" class="text-sm font-semibold text-slate-900">Rates for the same item, branch, unit and price list</h2>
            <span class="text-xs text-slate-500">Periods of live rates cannot overlap</span>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Period</th><th class="text-right">Rate (₹)</th><th>Approval</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($siblings as $other)
                        <tr wire:key="sib-{{ $other->id }}" @class(['bg-brand-50/40' => $other->id === $rate->id])>
                            <td class="whitespace-nowrap">
                                @if ($other->id === $rate->id)<span class="font-medium text-slate-900">{{ $other->periodLabel() }}</span> <span class="text-xs text-slate-500">(this rate)</span>
                                @else<a href="{{ route('masters.rates.show', $other) }}" wire:navigate class="hover:text-brand-700">{{ $other->periodLabel() }}</a>@endif
                            </td>
                            <td class="text-right tabular-nums">{{ $other->rateLabel() }}</td>
                            <td><span class="badge {{ $other->approval_status->badge() }}">{{ $other->approval_status->label() }}</span></td>
                            <td><span class="badge {{ $other->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($other->status) }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <livewire:shared.approval-panel type="rate" :id="$rate->id" :key="'ap-'.$rate->id" />

    <livewire:shared.comments type="rate" :id="$rate->id" :key="'cm-'.$rate->id" />

    @if ($history->isNotEmpty())
        <section class="card" aria-labelledby="s-history">
            <div class="card-header"><h2 id="s-history" class="text-sm font-semibold text-slate-900">History</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($history as $entry)
                    @php
                        $changed = collect($entry->properties['attributes'] ?? [])->keys()->reject(fn ($k) => in_array($k, ['updated_by', 'created_by'], true))->map(fn ($k) => str_replace('_', ' ', $k))->implode(', ');
                        $note = $entry->properties['comment'] ?? $entry->properties['reason'] ?? null;
                    @endphp
                    <li class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-2.5" wire:key="h-{{ $entry->id }}">
                        <span>
                            <span class="font-medium text-slate-900">{{ str_replace('_', ' ', ucfirst((string) ($entry->event ?? $entry->description))) }}</span>
                            @if ($changed !== '')<span class="text-slate-500"> — {{ $changed }}</span>@endif
                            @if (! empty($note))<span class="text-slate-600"> — “{{ $note }}”</span>@endif
                        </span>
                        <span class="text-xs text-slate-500">{{ $entry->causer?->name ?? 'System' }} · {{ $entry->created_at?->format(config('formezy.display.datetime')) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($dialog === 'reopen')
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="reopen-title">
            <form wire:submit="reopen" class="card w-full max-w-lg" novalidate>
                <div class="card-header">
                    <h2 id="reopen-title" class="text-sm font-semibold text-slate-900">Re-open this rate for editing</h2>
                    <button type="button" wire:click="closeDialog" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="card-body space-y-3">
                    <p class="text-sm text-slate-600">An approved rate is locked. Re-opening takes it back to a draft: it will <strong>not be offered to quotations or orders</strong> until it is submitted and approved again, which leaves a gap in pricing. To change a price from a future date, add a new rate and end this one instead.</p>
                    <div>
                        <label for="reopen-reason" class="label">Why are you re-opening it?</label>
                        <textarea id="reopen-reason" rows="3" wire:model="reason" class="input" maxlength="1000"></textarea>
                        @error('reason') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeDialog" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">Re-open</button>
                </div>
            </form>
        </div>
    @endif

    @if ($dialog === 'end')
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="end-title">
            <form wire:submit="endEarly" class="card w-full max-w-lg" novalidate>
                <div class="card-header">
                    <h2 id="end-title" class="text-sm font-semibold text-slate-900">End this rate on a date</h2>
                    <button type="button" wire:click="closeDialog" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="card-body space-y-3">
                    <p class="text-sm text-slate-600">The rate stays approved and keeps applying up to and including the day you choose, then stops. This only ever shortens a rate, so it needs no new approval; it is recorded with your reason. Do this before adding the next rate for the same item, branch and unit, because their periods cannot overlap.</p>
                    <div>
                        <label for="end-on" class="label">Last day the rate applies</label>
                        <input id="end-on" type="date" wire:model="endOn" min="{{ $today }}" class="input">
                        @error('on') <p class="error">{{ $message }}</p> @enderror
                        @error('endOn') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="end-reason" class="label">Why?</label>
                        <textarea id="end-reason" rows="2" wire:model="reason" class="input" maxlength="1000"></textarea>
                        @error('reason') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeDialog" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">End the rate</button>
                </div>
            </form>
        </div>
    @endif
</div>

@php use App\Modules\Shared\Support\Format; $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; $unit = $order->item?->baseUnit?->code; @endphp
<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('production.orders.index') }}" wire:navigate class="hover:text-brand-700">Production orders</a> › {{ $order->location?->name }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $order->item_name }} <span class="font-mono text-base font-normal text-slate-500">{{ $order->order_number }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span @class(['badge', 'bg-slate-100 text-slate-700' => in_array($order->state, ['draft', 'cancelled'], true), 'bg-amber-100 text-amber-800' => in_array($order->state, ['released', 'in_progress'], true), 'bg-emerald-100 text-emerald-800' => $order->state === 'completed'])>{{ $states[$order->state] }}</span>
                <span class="tabular-nums text-slate-600">Plan {{ $qty($f['planned']) }} {{ $unit }} · {{ $order->planned_start->format('d-m-Y') }} → {{ $order->planned_end->format('d-m-Y') }} · bill {{ $order->bom?->code }}</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('inspection.create')@if ($order->state !== 'draft')<a href="{{ route('quality.inspections.create', ['order' => $order->id]) }}" wire:navigate class="btn-secondary">Inspect the output</a>@endif @endcan
            @if ($canEdit)<a href="{{ route('production.orders.edit', $order) }}" wire:navigate class="btn-secondary">Edit</a>@endif
            @if ($canRelease && $order->state === 'draft')<button type="button" wire:click="release" class="btn-primary">Release to the shop floor</button>@endif
            @if ($canRelease && $order->isOpen())<button type="button" wire:click="openDialog('complete')" class="btn-secondary">Complete…</button>@endif
            @if ($canRelease && ! $order->isFinished())<button type="button" wire:click="openDialog('cancel')" class="btn-ghost text-rose-600">Cancel…</button>@endif
        </div>
    </div>

    <x-status />
    @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('entry')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if ($order->state === 'cancelled')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">Cancelled{{ $order->cancelled_at ? ' on '.$order->cancelled_at->format('d-m-Y') : '' }}: {{ $order->cancel_reason }}</div>@endif
    @if ($order->state === 'completed' && $order->completion_note)<div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">Closed short: {{ $order->completion_note }}</div>@endif

    <section class="grid gap-4 sm:grid-cols-4" aria-label="Progress">
        <div class="card card-body"><p class="text-xs text-slate-500">Made (good)</p><p class="text-xl font-semibold tabular-nums text-slate-900">{{ $qty($f['good']) }} <span class="text-sm font-normal text-slate-500">of {{ $qty($f['planned']) }}</span></p></div>
        <div class="card card-body"><p class="text-xs text-slate-500">Rejected</p><p class="text-xl font-semibold tabular-nums text-slate-900">{{ $qty($f['rejected']) }}</p></div>
        <div class="card card-body"><p class="text-xs text-slate-500">Yield</p><p class="text-xl font-semibold tabular-nums text-slate-900">{{ $f['yield'] === null ? '—' : $qty($f['yield']).'%' }}</p></div>
        <div class="card card-body"><p class="text-xs text-slate-500">Material cost per good unit</p><p class="text-xl font-semibold tabular-nums text-slate-900">{{ $f['unit_cost'] === null ? '—' : '₹ '.Format::number($f['unit_cost'], 2) }}</p><p class="text-xs text-slate-500">Material ₹ {{ Format::number($f['material_value'], 2) }}; labour and overhead are not counted</p></div>
    </section>

    <section class="card" aria-labelledby="s-comp">
        <div class="card-header"><h2 id="s-comp" class="text-sm font-semibold text-slate-900">Material</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>#</th><th>Component</th><th class="text-right">Required</th><th class="text-right">Issued</th><th class="text-right">Standard for what was made</th><th class="text-right">Over (+) / under (−)</th><th class="text-right">May still issue</th></tr></thead>
                <tbody>
                    @foreach ($f['components'] as $c)
                        <tr wire:key="pc-{{ $c['id'] }}">
                            <td class="tabular-nums text-slate-500">{{ $c['line_no'] }}</td>
                            <td class="font-medium text-slate-900">{{ $c['item_name'] }}</td>
                            <td class="text-right tabular-nums">{{ $qty($c['required']) }}</td>
                            <td class="text-right tabular-nums">{{ $qty($c['issued']) }}</td>
                            <td class="text-right tabular-nums">{{ $qty($c['standard_for_actual']) }}</td>
                            <td class="text-right tabular-nums {{ bccomp($c['variance'], '0', 3) > 0 ? 'text-rose-700' : '' }}">{{ bccomp($c['variance'], '0', 3) > 0 ? '+' : '' }}{{ $qty($c['variance']) }}</td>
                            <td class="text-right tabular-nums">{{ $qty($c['allowed_more']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @if ($canExecute)
        <div class="grid gap-5 lg:grid-cols-2">
            <form wire:submit="issueMaterial" class="card" novalidate>
                <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Issue material</h2></div>
                <div class="card-body space-y-3">
                    <div>
                        <label for="is-comp" class="label">Component</label>
                        <select id="is-comp" wire:model.live="componentId" class="input"><option value="">Select…</option>@foreach ($f['components'] as $c)<option value="{{ $c['id'] }}">{{ $c['item_name'] }}</option>@endforeach</select>
                        @error('component')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    @if ($batches !== [])
                        <div>
                            <label for="is-batch" class="label">Batch</label>
                            <select id="is-batch" wire:model="issueBatch" class="input"><option value="">Select…</option>@foreach ($batches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                            @error('batch')<p class="error">{{ $message }}</p>@enderror
                        </div>
                    @endif
                    <div>
                        <label for="is-qty" class="label">Quantity (base units)</label>
                        <input id="is-qty" type="text" inputmode="decimal" wire:model="issueQuantity" class="input tabular-nums" autocomplete="off">
                        @error('quantity')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="flex justify-end border-t border-slate-200 px-5 py-3"><button type="submit" class="btn-primary" wire:loading.attr="disabled">Issue</button></div>
            </form>

            <form wire:submit="reportOutput" class="card" novalidate>
                <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Report output</h2></div>
                <div class="card-body grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="out-good" class="label">Good ({{ $unit }})</label>
                        <input id="out-good" type="text" inputmode="decimal" wire:model="goodQuantity" class="input tabular-nums" autocomplete="off">
                        @error('good_quantity')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="out-rej" class="label">Rejected ({{ $unit }})</label>
                        <input id="out-rej" type="text" inputmode="decimal" wire:model="rejectedQuantity" class="input tabular-nums" autocomplete="off">
                        @error('rejected_quantity')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="out-why" class="label">Why were they rejected? (if any)</label>
                        <input id="out-why" type="text" wire:model="rejectReason" class="input" maxlength="300">
                        @error('reject_reason')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    @if ($tracked)
                        <div>
                            <label for="out-batch" class="label">Batch number</label>
                            <input id="out-batch" type="text" wire:model="batchNo" class="input uppercase" maxlength="40">
                            @error('batch_no')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="out-mfg" class="label">Made on</label>
                            <input id="out-mfg" type="date" wire:model="mfgDate" class="input">
                            @error('mfg_date')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        @if ($order->item?->has_expiry)
                            <div>
                                <label for="out-exp" class="label">Expires on</label>
                                <input id="out-exp" type="date" wire:model="expiryDate" class="input">
                                @error('expiry_date')<p class="error">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    @endif
                </div>
                <div class="flex justify-end border-t border-slate-200 px-5 py-3"><button type="submit" class="btn-primary" wire:loading.attr="disabled">Record</button></div>
            </form>
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card" aria-labelledby="s-iss">
            <div class="card-header"><h2 id="s-iss" class="text-sm font-semibold text-slate-900">Material issued</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($issues as $i)
                    <li class="flex justify-between gap-3 px-5 py-2" wire:key="mi-{{ $i->id }}"><span>{{ $i->component?->item_name }}{{ $i->batch ? ' · '.$i->batch->batch_no : '' }}</span><span class="tabular-nums text-slate-600">{{ $qty($i->quantity) }} · {{ $i->issued_at->format('d-m H:i') }}</span></li>
                @empty
                    <li class="px-5 py-3 text-slate-500">Nothing issued yet.</li>
                @endforelse
            </ul>
        </section>
        <section class="card" aria-labelledby="s-out">
            <div class="card-header"><h2 id="s-out" class="text-sm font-semibold text-slate-900">Output reported</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($outputs as $o)
                    <li class="px-5 py-2" wire:key="mp-{{ $o->id }}"><div class="flex justify-between gap-3"><span>{{ $qty($o->good_quantity) }} good @if ($o->rejected_quantity > 0)· <span class="text-rose-700">{{ $qty($o->rejected_quantity) }} rejected</span>@endif{{ $o->batch ? ' · '.$o->batch->batch_no : '' }}</span><span class="tabular-nums text-slate-600">{{ $o->recorded_at->format('d-m H:i') }}</span></div>@if ($o->reject_reason)<p class="text-xs text-slate-500">{{ $o->reject_reason }}</p>@endif</li>
                @empty
                    <li class="px-5 py-3 text-slate-500">Nothing reported yet.</li>
                @endforelse
            </ul>
        </section>
    </div>

    @if ($history->isNotEmpty())
        <section class="card" aria-labelledby="s-hist">
            <div class="card-header"><h2 id="s-hist" class="text-sm font-semibold text-slate-900">Audit trail</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($history as $entry)
                    <li class="flex justify-between gap-3 px-5 py-2" wire:key="h-{{ $entry->id }}"><span>{{ str_replace('_', ' ', ucfirst((string) ($entry->event ?? $entry->description))) }} · {{ $entry->causer?->name ?? 'System' }}</span><span class="tabular-nums text-xs text-slate-500">{{ $entry->created_at?->format('d-m-Y H:i') }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($dialog !== '')
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="d-title">
            <form wire:submit="finishOrder" class="card w-full max-w-lg" novalidate>
                <div class="card-header"><h2 id="d-title" class="text-sm font-semibold text-slate-900">{{ $dialog === 'cancel' ? 'Cancel' : 'Complete' }} production order {{ $order->order_number }}</h2></div>
                <div class="card-body">
                    <label for="d-note" class="label">{{ $dialog === 'cancel' ? 'Why?' : 'Note (needed if less was made than planned)' }}</label>
                    <textarea id="d-note" rows="3" wire:model="note" class="input" maxlength="500"></textarea>
                    @error('note')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeDialog" class="btn-secondary">Back</button>
                    <button type="submit" class="btn-primary">{{ $dialog === 'cancel' ? 'Cancel the order' : 'Complete the order' }}</button>
                </div>
            </form>
        </div>
    @endif
</div>

@php
    use App\Modules\Masters\Enums\ItemStage;

    $trim = fn ($n) => rtrim(rtrim((string) $n, '0'), '.') === '' ? '0' : rtrim(rtrim((string) $n, '0'), '.');
    $steps = [1 => 'Purchase', 2 => 'Inventory', 3 => 'Approval', 4 => 'Approved'];
    $current = $item->workflow_stage->step();
    $returned = $item->isReturned();
    $stageNames = ['purchase_draft' => 'Purchase', 'inventory_pending' => 'Inventory'];
@endphp

<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('masters.items.index') }}" wire:navigate class="hover:text-brand-700">Items</a> › {{ $item->itemGroup?->name }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $item->name }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                @if ($item->code)<span class="badge bg-slate-100 font-mono text-slate-700">{{ $item->code }}</span>@endif
                <span class="badge {{ $returned ? 'bg-rose-100 text-rose-800' : $item->workflow_stage->badge() }}">{{ $item->stageLabel() }}</span>
                <span class="badge {{ $item->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($item->status) }}</span>
                @if ($item->isUsable())<span class="text-xs text-emerald-700">Available in transactions</span>@else<span class="text-xs text-slate-500">Not available in transactions</span>@endif
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canEdit)<a href="{{ route('masters.items.edit', $item) }}" wire:navigate class="btn-primary">Edit {{ $item->workflow_stage === ItemStage::PurchaseDraft ? 'Purchase' : 'Inventory' }} details</a>@endif
            @if ($canSend)<button type="button" wire:click="sendToInventory" class="btn-secondary">Send to Inventory</button>@endif
            @if ($canReturn)<button type="button" wire:click="openDialog('return')" class="btn-secondary">Return to Purchase</button>@endif
            @if ($canSubmit)<button type="button" wire:click="submitForApproval" class="btn-secondary">Submit for approval</button>@endif
            @if ($canReopen)<button type="button" wire:click="openDialog('reopen')" class="btn-secondary">Re-open</button>@endif
            @if ($canChangeStatus)<button type="button" wire:click="toggleStatus" class="btn-secondary">{{ $item->status === 'active' ? 'Deactivate' : 'Activate' }}</button>@endif
            @if ($canDelete)<button type="button" wire:click="delete" wire:confirm="Delete this item?" class="btn-ghost text-rose-600">Delete</button>@endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif
    @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    {{-- progress --}}
    <ol class="grid grid-cols-4 gap-2" aria-label="Progress">
        @foreach ($steps as $n => $label)
            <li @class([
                'rounded-lg border px-3 py-2 text-sm',
                'border-emerald-300 bg-emerald-50 text-emerald-800' => $n < $current || ($n === 4 && $current === 4),
                'border-brand-500 bg-brand-50 font-medium text-brand-800' => $n === $current && $current < 4,
                'border-slate-200 text-slate-400' => $n > $current,
            ]) @if ($n === $current) aria-current="step" @endif>
                <span class="text-xs">Step {{ $n }}</span><br>{{ $label }}
            </li>
        @endforeach
    </ol>

    @if ($returned && $item->workflow_stage->isWorkStage())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
            Sent back to <strong>{{ $item->workflow_stage->label() }}</strong>.
            @php $last = $history->last(fn ($h) => in_array($h->event, ['rejected', 'returned_to_purchase'], true) && $h->comment); @endphp
            @if ($last)Reason: “{{ $last->comment }}” — {{ $last->actor?->name }}.@endif
        </div>
    @endif

    @if ($missing !== [])
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            @if ($item->workflow_stage === ItemStage::PurchaseDraft) Before it can go to Inventory it still needs: @else Before it can be submitted for approval it still needs: @endif
            {{ implode('; ', $missing) }}.
        </div>
    @endif

    {{-- approval --}}
    @if ($item->workflow_stage === ItemStage::ApprovalPending)
        <section class="card" aria-labelledby="s-approval">
            <div class="card-header"><h2 id="s-approval" class="text-sm font-semibold text-slate-900">Waiting for approval</h2></div>
            <div class="card-body space-y-3 text-sm text-slate-700">
                <p>Routed to {{ $routedTo }}. Operations can view and comment on the item but cannot change or approve it.</p>
                @if ($canDecide)
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="openDialog('approve')" class="btn-primary">Approve</button>
                        <button type="button" wire:click="openDialog('reject')" class="btn-secondary">Reject…</button>
                    </div>
                @elseif ($canApprove)
                    <p class="text-slate-500">You hold the approval permission but cannot decide this one: you may not be the designated approver, or you created or submitted it yourself.</p>
                @endif
            </div>
        </section>
    @endif

    {{-- details --}}
    <section class="card" aria-labelledby="s-details">
        <div class="card-header"><h2 id="s-details" class="text-sm font-semibold text-slate-900">Purchase details</h2></div>
        <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-3">
            <div><dt class="text-xs text-slate-500">Item group</dt><dd class="text-sm text-slate-900">{{ $item->itemGroup?->name }}</dd></div>
            <div><dt class="text-xs text-slate-500">Brand</dt><dd class="text-sm text-slate-900">{{ $item->brand?->name ?? '—' }}</dd></div>
            <div><dt class="text-xs text-slate-500">Industry</dt><dd class="text-sm text-slate-900">{{ $item->industryTemplate?->name ?? 'Not assigned' }}</dd></div>
            <div><dt class="text-xs text-slate-500">Standard cost</dt><dd class="text-sm text-slate-900">{{ $item->standard_cost !== null ? '₹ '.$trim($item->standard_cost).' per '.$item->baseUnit?->name : '—' }}</dd></div>
            <div><dt class="text-xs text-slate-500">Base unit</dt><dd class="text-sm text-slate-900">{{ $item->baseUnit?->name }} ({{ $item->baseUnit?->code }})</dd></div>
            <div><dt class="text-xs text-slate-500">HSN / SAC</dt><dd class="font-mono text-sm text-slate-900">{{ $item->hsn_code ?: '—' }}</dd></div>
            <div><dt class="text-xs text-slate-500">GST rate</dt><dd class="text-sm text-slate-900">{{ $item->gst_rate !== null ? $trim($item->gst_rate).'%' : '—' }}</dd></div>
            <div><dt class="text-xs text-slate-500">Tracking</dt><dd class="text-sm text-slate-900">{{ $item->tracking === 'batch' ? 'By batch' : 'None' }}@if ($item->has_expiry), expires{{ $item->shelf_life_days ? ' (shelf life '.$item->shelf_life_days.' days)' : '' }}@endif</dd></div>
            <div><dt class="text-xs text-slate-500">PO tolerance</dt><dd class="text-sm text-slate-900">{{ $trim($item->po_tolerance_percent) }}%</dd></div>
            @if ($item->description)<div class="sm:col-span-3"><dt class="text-xs text-slate-500">Description</dt><dd class="text-sm text-slate-900">{{ $item->description }}</dd></div>@endif
        </dl>
        <div class="border-t border-slate-100 px-5 py-3 text-sm text-slate-700">
            <span class="text-xs text-slate-500">Units</span>
            <ul class="mt-1 space-y-0.5">
                <li>1 {{ $item->baseUnit?->name }} <span class="text-slate-500">(base unit)</span></li>
                @foreach ($item->uoms as $u)<li wire:key="u-{{ $u->id }}">1 {{ $u->unit->name }} = {{ $trim($u->factor) }} {{ $item->baseUnit?->name }}</li>@endforeach
            </ul>
        </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card" aria-labelledby="s-inv">
            <div class="card-header"><h2 id="s-inv" class="text-sm font-semibold text-slate-900">Inventory details</h2></div>
            <div class="card-body space-y-3 text-sm">
                <div><span class="text-xs text-slate-500">Item code</span><div class="font-mono text-slate-900">{{ $item->code ?: 'Not assigned yet' }}</div></div>
                <div>
                    <span class="text-xs text-slate-500">Alias names</span>
                    <div class="text-slate-900">{{ $item->aliases->pluck('alias')->implode(', ') ?: 'None' }}</div>
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="s-stock">
            <div class="card-header"><h2 id="s-stock" class="text-sm font-semibold text-slate-900">Stock levels <span class="font-normal text-slate-500">({{ $item->baseUnit?->code }})</span></h2></div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Branch</th><th class="text-right">Min</th><th class="text-right">Reorder</th><th class="text-right">Max</th></tr></thead>
                    <tbody>
                        @forelse ($item->locationParameters as $p)
                            <tr wire:key="p-{{ $p->id }}">
                                <td>{{ $p->location?->name }}</td>
                                <td class="text-right tabular-nums">{{ $trim($p->minimum_stock) }}</td>
                                <td class="text-right tabular-nums">{{ $trim($p->reorder_level) }}</td>
                                <td class="text-right tabular-nums">{{ $trim($p->maximum_stock) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-slate-500">No stock levels set.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <livewire:shared.comments type="item" :id="$item->id" :key="'cm-'.$item->id" />

    {{-- stage history --}}
    @if ($costHistory->isNotEmpty())
        <section class="card" aria-labelledby="s-cost">
            <div class="card-header"><h2 id="s-cost" class="text-sm font-semibold text-slate-900">Cost history</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($costHistory as $c)
                    <li class="flex flex-wrap justify-between gap-2 px-5 py-2" wire:key="cost-{{ $c->id }}">
                        <span>{{ $c->method === 'standard' ? 'Standard cost' : 'Moving average' }}{{ $c->location_id ? ' at a branch' : '' }}:
                            {{ $c->previous_cost !== null ? '₹ '.rtrim(rtrim((string) $c->previous_cost, '0'), '.').' → ' : '' }}<span class="font-medium">{{ $c->unit_cost === null ? 'cleared' : '₹ '.rtrim(rtrim((string) $c->unit_cost, '0'), '.') }}</span></span>
                        <span class="text-xs text-slate-500">{{ $c->source_type ? ($c->source_type === 'grn' ? 'goods receipt' : $c->source_type) : '' }}{{ $c->changer ? ' · '.$c->changer->name : '' }} · {{ $c->effective_at->format('d-m-Y H:i') }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="card" aria-labelledby="s-history">
        <div class="card-header"><h2 id="s-history" class="text-sm font-semibold text-slate-900">Stage history</h2></div>
        <ul class="divide-y divide-slate-100 text-sm">
            @foreach ($history as $entry)
                <li class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-2.5" wire:key="h-{{ $entry->id }}">
                    <span>
                        <span class="font-medium text-slate-900">{{ $entry->label() }}</span>
                        @if ($entry->comment)<span class="text-slate-600"> — “{{ $entry->comment }}”</span>@endif
                    </span>
                    <span class="text-xs text-slate-500">{{ $entry->actor?->name ?? 'System' }} · {{ $entry->created_at?->format(config('formezy.display.datetime')) }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    @if ($decisions->isNotEmpty())
        <section class="card" aria-labelledby="s-decisions">
            <div class="card-header"><h2 id="s-decisions" class="text-sm font-semibold text-slate-900">Approval decisions</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($decisions->sortKeysDesc() as $cycle => $rows)
                    @foreach ($rows as $row)
                        <li class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-2.5" wire:key="d-{{ $row->id }}">
                            <span>Round {{ $cycle }}, level {{ $row->level }}: <strong>{{ ucfirst($row->status->value) }}</strong>@if ($row->comment) <span class="text-slate-600">— “{{ $row->comment }}”</span>@endif</span>
                            <span class="text-xs text-slate-500">{{ $row->actor?->name ?? 'waiting' }}@if ($row->acted_at) · {{ $row->acted_at->format(config('formezy.display.datetime')) }}@endif</span>
                        </li>
                    @endforeach
                @endforeach
            </ul>
        </section>
    @endif

    {{-- dialogs --}}
    @if ($dialog !== '')
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="dlg-title">
            <form wire:submit="{{ ['return' => 'returnToPurchase', 'approve' => 'approve', 'reject' => 'reject', 'reopen' => 'reopen'][$dialog] }}" class="card w-full max-w-lg" novalidate>
                <div class="card-header">
                    <h2 id="dlg-title" class="text-sm font-semibold text-slate-900">
                        {{ ['return' => 'Return to Purchase', 'approve' => 'Approve this item', 'reject' => 'Reject this item', 'reopen' => 'Re-open this item'][$dialog] }}
                    </h2>
                    <button type="button" wire:click="closeDialog" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="card-body space-y-3">
                    @if ($dialog === 'reopen')
                        <p class="text-sm text-slate-600">An approved item is locked. Re-opening sends it back for correction: it <strong>cannot be used in new transactions</strong> until it is approved again. The approval history is kept.</p>
                    @endif

                    @if (in_array($dialog, ['reject', 'reopen'], true))
                        <div>
                            <label for="dlg-to" class="label">{{ $dialog === 'reject' ? 'Send it back to' : 'Re-open to' }}</label>
                            <select id="dlg-to" wire:model="returnTo" class="input">
                                @if ($dialog === 'reject' || in_array(ItemStage::PurchaseDraft, $reopenStages, true))<option value="purchase_draft">Purchase — name, group, units, HSN, GST</option>@endif
                                @if ($dialog === 'reject' || in_array(ItemStage::InventoryPending, $reopenStages, true))<option value="inventory_pending">Inventory — code, aliases, stock levels</option>@endif
                            </select>
                        </div>
                    @endif

                    <div>
                        <label for="dlg-comment" class="label">{{ $dialog === 'approve' ? 'Comment (optional)' : ($dialog === 'reopen' ? 'Why are you re-opening it?' : 'What needs to change?') }}</label>
                        <textarea id="dlg-comment" rows="3" wire:model="comment" class="input" maxlength="1000"></textarea>
                        @error('comment') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeDialog" class="btn-secondary">Cancel</button>
                    <button type="submit" class="{{ $dialog === 'reject' ? 'btn-danger' : 'btn-primary' }}" wire:loading.attr="disabled">{{ ['return' => 'Return', 'approve' => 'Approve', 'reject' => 'Reject', 'reopen' => 'Re-open'][$dialog] }}</button>
                </div>
            </form>
        </div>
    @endif
</div>

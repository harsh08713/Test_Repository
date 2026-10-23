@php use App\Modules\Maintenance\Services\MaintenanceFigures; use App\Modules\Shared\Support\Format; $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('maintenance.orders.index') }}" wire:navigate class="hover:text-brand-700">Work orders</a> › {{ $order->location?->name }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $order->title }} <span class="font-mono text-base font-normal text-slate-500">{{ $order->order_number }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span @class(['badge', 'bg-amber-100 text-amber-800' => in_array($order->state, ['open', 'in_progress'], true), 'bg-emerald-100 text-emerald-800' => $order->state === 'completed', 'bg-slate-100 text-slate-700' => $order->state === 'cancelled'])>{{ $states[$order->state] }}</span>
                <span class="badge bg-slate-100 text-slate-700">{{ $types[$order->type] }}</span>
                <span @class(['badge', 'bg-slate-100 text-slate-700' => in_array($order->priority, ['low', 'normal'], true), 'bg-amber-100 text-amber-800' => $order->priority === 'high', 'bg-rose-100 text-rose-800' => $order->priority === 'urgent'])>{{ $priorities[$order->priority] }}</span>
                <a href="{{ route('maintenance.assets.show', $order->asset_id) }}" wire:navigate class="text-brand-700 hover:underline">{{ $order->asset?->name }}</a>
                <span class="text-slate-600">{{ $order->assignee ? 'assigned to '.$order->assignee->name : 'not assigned' }}{{ $order->due_on ? ' · due '.$order->due_on->format('d-m-Y') : '' }}@if ($order->isOverdue()) <span class="font-medium text-rose-700">overdue</span>@endif{{ $order->plan ? ' · schedule '.$order->plan->code : '' }}</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canExecute && $order->state === 'open')<button type="button" wire:click="startWork" class="btn-secondary">Start work</button>@endif
            @if ($canExecute)<button type="button" wire:click="openDialog('complete')" class="btn-primary">Complete…</button>@endif
            @if ($canCancel && $order->isOpen())<button type="button" wire:click="openDialog('cancel')" class="btn-ghost text-rose-600">Cancel…</button>@endif
        </div>
    </div>

    <x-status />
    @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('tasks')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('entry')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @if ($order->state === 'cancelled')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">Cancelled: {{ $order->cancel_reason }}</div>@endif
    @if ($order->state === 'completed')<div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">Completed {{ $order->completed_at?->format('d-m-Y H:i') }}: {{ $order->completion_note }}</div>@endif
    @if ($order->description)<section class="card card-body text-sm text-slate-700"><p class="whitespace-pre-line">{{ $order->description }}</p></section>@endif

    <section class="grid gap-4 sm:grid-cols-4" aria-label="Figures">
        <div class="card card-body"><p class="text-xs text-slate-500">Downtime</p><p class="text-xl font-semibold tabular-nums text-slate-900">{{ $order->type === 'breakdown' ? MaintenanceFigures::duration($f['downtime_minutes']) : '—' }}</p>@if ($order->type === 'breakdown')<p class="text-xs text-slate-500">from {{ $order->stopped_at?->format('d-m H:i') }}{{ $order->restored_at ? ' to '.$order->restored_at->format('d-m H:i') : ' (still down)' }}</p>@endif</div>
        <div class="card card-body"><p class="text-xs text-slate-500">Tasks done</p><p class="text-xl font-semibold tabular-nums text-slate-900">{{ $f['tasks_done'] }} of {{ $f['tasks_total'] }}</p></div>
        <div class="card card-body"><p class="text-xs text-slate-500">Hours worked</p><p class="text-xl font-semibold tabular-nums text-slate-900">{{ $qty($f['labour_hours']) }} h</p></div>
        <div class="card card-body"><p class="text-xs text-slate-500">Spare parts</p><p class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($f['parts_value'], 2) }}</p></div>
    </section>

    @if ($order->tasks->isNotEmpty())
        <section class="card" aria-labelledby="s-tasks">
            <div class="card-header"><h2 id="s-tasks" class="text-sm font-semibold text-slate-900">Tasks</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($order->tasks as $t)
                    <li class="flex items-center justify-between gap-3 px-5 py-2.5" wire:key="tk-{{ $t->id }}">
                        <span class="{{ $t->done_at ? 'text-slate-500 line-through' : 'text-slate-900' }}">{{ $t->line_no }}. {{ $t->description }}</span>
                        @if ($t->done_at)<span class="text-xs text-emerald-700">done {{ $t->done_at->format('d-m H:i') }}</span>@elseif ($canExecute)<button type="button" wire:click="tickTask({{ $t->id }})" class="btn-secondary btn-sm">Done</button>@endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($canExecute)
        <div class="grid gap-5 lg:grid-cols-2">
            <form wire:submit="issueSparePart" class="card" novalidate>
                <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Take a spare part from stock</h2></div>
                <div class="card-body space-y-3">
                    <div>
                        @if ($partItemId !== '')
                            <span class="label">Part</span>
                            <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm"><span class="truncate text-slate-900">{{ $partItemLabel }}</span><button type="button" wire:click="clearPart" class="shrink-0 text-xs text-brand-700 hover:underline">Change</button></div>
                        @else
                            <label for="sp-search" class="label">Find the part</label>
                            <input id="sp-search" type="search" wire:model.live.debounce.300ms="partSearch" class="input" autocomplete="off" placeholder="Code or name…">
                            @if ($partResults->isNotEmpty())<ul class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">@foreach ($partResults as $hit)<li><button type="button" wire:click="pickPart({{ $hit->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">{{ trim($hit->code.' · '.$hit->name) }}</button></li>@endforeach</ul>@endif
                        @endif
                        @error('item_id')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    @if ($batches !== [])<div><label for="sp-batch" class="label">Batch</label><select id="sp-batch" wire:model="partBatch" class="input"><option value="">Select…</option>@foreach ($batches as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('batch')<p class="error">{{ $message }}</p>@enderror</div>@endif
                    <div><label for="sp-qty" class="label">Quantity (base units)</label><input id="sp-qty" type="text" inputmode="decimal" wire:model="partQuantity" class="input tabular-nums" autocomplete="off">@error('quantity')<p class="error">{{ $message }}</p>@enderror</div>
                </div>
                <div class="flex justify-end border-t border-slate-200 px-5 py-3"><button type="submit" class="btn-primary" wire:loading.attr="disabled">Issue</button></div>
            </form>

            <form wire:submit="logWork" class="card" novalidate>
                <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Log your hours</h2></div>
                <div class="card-body grid gap-3 sm:grid-cols-2">
                    <div><label for="lh-hours" class="label">Hours</label><input id="lh-hours" type="text" inputmode="decimal" wire:model="hours" class="input tabular-nums" autocomplete="off">@error('hours')<p class="error">{{ $message }}</p>@enderror</div>
                    <div><label for="lh-date" class="label">Day worked</label><input id="lh-date" type="date" wire:model="workDate" class="input" max="{{ now()->toDateString() }}">@error('work_date')<p class="error">{{ $message }}</p>@enderror</div>
                    <div class="sm:col-span-2"><label for="lh-note" class="label">Note (optional)</label><input id="lh-note" type="text" wire:model="hourNote" class="input" maxlength="200">@error('note')<p class="error">{{ $message }}</p>@enderror</div>
                </div>
                <div class="flex justify-end border-t border-slate-200 px-5 py-3"><button type="submit" class="btn-primary" wire:loading.attr="disabled">Log</button></div>
            </form>
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card" aria-labelledby="s-parts">
            <div class="card-header"><h2 id="s-parts" class="text-sm font-semibold text-slate-900">Parts used</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($parts as $p)<li class="flex justify-between gap-3 px-5 py-2" wire:key="pt-{{ $p->id }}"><span>{{ $p->item?->name }}</span><span class="tabular-nums text-slate-600">{{ $qty($p->quantity) }} · {{ $p->issued_at->format('d-m H:i') }}</span></li>@empty<li class="px-5 py-3 text-slate-500">No parts yet.</li>@endforelse
            </ul>
        </section>
        <section class="card" aria-labelledby="s-lab">
            <div class="card-header"><h2 id="s-lab" class="text-sm font-semibold text-slate-900">Hours</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($labour as $l)<li class="flex justify-between gap-3 px-5 py-2" wire:key="lb-{{ $l->id }}"><span>{{ $l->who?->name }}{{ $l->note ? ' — '.$l->note : '' }}</span><span class="tabular-nums text-slate-600">{{ $qty($l->hours) }} h · {{ $l->work_date->format('d-m') }}</span></li>@empty<li class="px-5 py-3 text-slate-500">No hours yet.</li>@endforelse
            </ul>
        </section>
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        @if ($canAssign)
            <form wire:submit="assignTo" class="card" novalidate>
                <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Assigned to</h2></div>
                <div class="card-body"><label for="as-who" class="sr-only">Technician</label><select id="as-who" wire:model="assigneeId" class="input"><option value="">Nobody</option>@foreach ($technicians as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('assigned_to')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="flex justify-end border-t border-slate-200 px-5 py-3"><button type="submit" class="btn-secondary" wire:loading.attr="disabled">Save</button></div>
            </form>
        @endif
        <section class="card" aria-labelledby="s-loss">
            <div class="card-header"><h2 id="s-loss" class="text-sm font-semibold text-slate-900">Loss incident</h2></div>
            @if ($canLink)
                <form wire:submit="linkLoss" novalidate>
                    <div class="card-body"><label for="ll-inc" class="sr-only">Loss incident</label><select id="ll-inc" wire:model="incidentId" class="input"><option value="">None</option>@foreach ($incidents as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select><p class="mt-2 text-xs text-slate-500">Linked by hand: nothing is created for you.</p></div>
                    <div class="flex justify-end border-t border-slate-200 px-5 py-3"><button type="submit" class="btn-secondary" wire:loading.attr="disabled">Save the link</button></div>
                </form>
            @else
                <div class="card-body text-sm text-slate-700">{{ $order->lossIncident ? $order->lossIncident->incident_number.' · '.$order->lossIncident->title : 'No loss incident linked.' }}</div>
            @endif
        </section>
    </div>

    @if ($history->isNotEmpty())
        <section class="card" aria-labelledby="s-hist">
            <div class="card-header"><h2 id="s-hist" class="text-sm font-semibold text-slate-900">Audit trail</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($history as $log)<li class="flex justify-between gap-3 px-5 py-2" wire:key="h-{{ $log->id }}"><span>{{ str_replace('_', ' ', ucfirst((string) ($log->event ?? $log->description))) }} · {{ $log->causer?->name ?? 'System' }}</span><span class="tabular-nums text-xs text-slate-500">{{ $log->created_at?->format('d-m-Y H:i') }}</span></li>@endforeach
            </ul>
        </section>
    @endif

    @if ($dialog !== '')
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="d-title">
            <form wire:submit="finishDialog" class="card w-full max-w-lg" novalidate>
                <div class="card-header"><h2 id="d-title" class="text-sm font-semibold text-slate-900">{{ $dialog === 'cancel' ? 'Cancel' : 'Complete' }} work order {{ $order->order_number }}</h2></div>
                <div class="card-body space-y-3">
                    @if ($dialog === 'complete' && $order->type === 'breakdown')
                        <div><label for="d-rest" class="label">The asset was running again at</label><input id="d-rest" type="datetime-local" wire:model="restoredAt" class="input">@error('restored_at')<p class="error">{{ $message }}</p>@enderror</div>
                    @endif
                    <div><label for="d-note" class="label">{{ $dialog === 'cancel' ? 'Why?' : 'What was done?' }}</label><textarea id="d-note" rows="3" wire:model="note" class="input" maxlength="500"></textarea>@error('note')<p class="error">{{ $message }}</p>@enderror</div>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><button type="button" wire:click="closeDialog" class="btn-secondary">Back</button><button type="submit" class="btn-primary">{{ $dialog === 'cancel' ? 'Cancel the order' : 'Complete' }}</button></div>
            </form>
        </div>
    @endif
</div>

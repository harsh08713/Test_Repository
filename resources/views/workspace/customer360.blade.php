@php
    use App\Modules\Shared\Support\Format;

    $credit = $data['credit'];
    $creditText = ['ok' => ['Within the limit', 'bg-emerald-100 text-emerald-800'], 'near' => ['Close to the limit', 'bg-amber-100 text-amber-800'], 'over' => ['Over the limit', 'bg-rose-100 text-rose-800'], 'no_limit' => ['No credit limit set', 'bg-slate-100 text-slate-600']][$credit['status']];
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <p class="text-sm"><a href="{{ route('masters.ledgers.show', $ledger) }}" wire:navigate class="text-brand-700 hover:underline">Customer record</a> <span class="text-slate-400">/ Customer 360</span></p>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $ledger->name }}</h1>
            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                <span class="font-mono text-xs">{{ $ledger->code }}</span>
                @if ($ledger->customer_kind)<span class="badge bg-slate-100 text-slate-600">{{ strtoupper($ledger->customer_kind) }}</span>@endif
                @if ($ledger->gstin)<span>GSTIN {{ $ledger->gstin }}</span>@endif
                <span class="badge {{ $ledger->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $ledger->status === 'active' ? 'Active' : 'Inactive' }}</span>
            </p>
        </div>
        @if ($canTask)
            <a href="{{ route('workspace.tasks.create', ['type' => 'ledger', 'id' => $ledger->id]) }}" wire:navigate class="btn-secondary">Add a task</a>
        @endif
    </div>

    <x-status />

    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" aria-label="At a glance">
        @if ($data['totals']['orders'] !== null)
            <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Approved sales, net of tax</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($data['totals']['sales'], 0) }}</dd><dd class="text-xs text-slate-500">{{ $data['totals']['orders'] }} orders{{ $data['totals']['last_order'] ? ', last on '.Format::date($data['totals']['last_order']) : '' }}</dd></div>
        @endif
        @if ($data['totals']['open_deals'] !== null)
            <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Open deals</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ $data['totals']['open_deals'] }}</dd></div>
        @endif
        @if ($data['totals']['open_quotations'] !== null)
            <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Open quotations</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ $data['totals']['open_quotations'] }}</dd></div>
        @endif
        @if ($data['totals']['leads'] !== null)
            <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Leads and inquiries</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ $data['totals']['leads'] }}</dd></div>
        @endif
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Credit</dt><dd class="mt-1"><span class="badge {{ $creditText[1] }}">{{ $creditText[0] }}</span></dd></div>
    </dl>

    <div class="grid gap-5 lg:grid-cols-3">
        <section class="card lg:col-span-2" aria-labelledby="sec-credit">
            <div class="card-header"><h2 id="sec-credit" class="text-sm font-semibold text-slate-900">Credit terms</h2></div>
            <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-4">
                <div><dt class="text-xs text-slate-500">Credit limit</dt><dd class="text-sm tabular-nums text-slate-900">{{ $credit['limit'] === null ? 'None set' : '₹ '.Format::number($credit['limit'], 2) }}</dd></div>
                <div><dt class="text-xs text-slate-500">Credit days</dt><dd class="text-sm tabular-nums text-slate-900">{{ $credit['days'] }}</dd></div>
                <div><dt class="text-xs text-slate-500">Out on orders within credit days</dt><dd class="text-sm tabular-nums text-slate-900">₹ {{ Format::number($credit['exposure'], 2) }}</dd></div>
                <div><dt class="text-xs text-slate-500">Left</dt><dd class="text-sm tabular-nums {{ $credit['status'] === 'over' ? 'font-medium text-rose-700' : 'text-slate-900' }}">{{ $credit['available'] === null ? '—' : '₹ '.Format::number($credit['available'], 2) }}</dd></div>
            </dl>
            <p class="px-5 pb-3 text-xs text-slate-500">Payments are not recorded yet, so “out” counts every order of this customer, still pending or approved, made within the credit days.</p>
        </section>

        <section class="card" aria-labelledby="sec-contacts">
            <div class="card-header"><h2 id="sec-contacts" class="text-sm font-semibold text-slate-900">Contacts</h2></div>
            <ul class="divide-y divide-slate-100">
                @forelse ($data['contacts'] as $contact)
                    <li class="px-5 py-2 text-sm" wire:key="ct-{{ $contact->id }}">
                        <div class="font-medium text-slate-900">{{ $contact->name }} @if ($contact->is_primary)<span class="badge bg-slate-100 text-slate-600">Main</span>@endif</div>
                        <div class="text-xs text-slate-500">{{ collect([$contact->designation, $contact->mobile ?: $contact->phone, $contact->email])->filter()->implode(' · ') }}</div>
                    </li>
                @empty
                    <li class="px-5 py-4 text-sm text-slate-500">No contact on the record.</li>
                @endforelse
            </ul>
        </section>
    </div>

    @if ($data['addresses']->isNotEmpty())
        <section class="card" aria-labelledby="sec-addr">
            <div class="card-header"><h2 id="sec-addr" class="text-sm font-semibold text-slate-900">Addresses</h2></div>
            <ul class="grid gap-3 p-5 sm:grid-cols-2">
                @foreach ($data['addresses'] as $address)
                    <li class="text-sm text-slate-700" wire:key="ad-{{ $address->id }}">{{ collect([$address->line1, $address->line2, $address->city, $address->pin_code])->filter()->implode(', ') }}
                        @if ($address->is_default_billing)<span class="badge bg-slate-100 text-slate-600">Billing</span>@endif @if ($address->is_default_shipping)<span class="badge bg-slate-100 text-slate-600">Shipping</span>@endif</li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        @if ($data['deals'] !== null)
            <section class="card" aria-labelledby="sec-deals">
                <div class="card-header"><h2 id="sec-deals" class="text-sm font-semibold text-slate-900">Deals</h2></div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($data['deals'] as $deal)
                        <li class="flex items-center justify-between gap-3 px-5 py-2 text-sm" wire:key="dl-{{ $deal->id }}">
                            <span><a href="{{ route('crm.deals.show', $deal) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $deal->deal_number }}</a> <span class="text-slate-600">{{ $deal->stage->label() }}{{ $deal->expected_close_date ? ', close '.Format::date($deal->expected_close_date) : '' }}</span></span>
                            <span class="tabular-nums text-slate-900">₹ {{ Format::number($deal->expected_value, 0) }}</span>
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-slate-500">No deal you may see.</li>
                    @endforelse
                </ul>
            </section>
        @endif

        @if ($data['quotations'] !== null)
            <section class="card" aria-labelledby="sec-quotes">
                <div class="card-header"><h2 id="sec-quotes" class="text-sm font-semibold text-slate-900">Quotations</h2></div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($data['quotations'] as $q)
                        <li class="flex items-center justify-between gap-3 px-5 py-2 text-sm" wire:key="qt-{{ $q->id }}">
                            <span><a href="{{ route('sales.quotations.show', $q) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $q->quotation_number }} · Rev {{ $q->revision_no }}</a> <span class="text-slate-600">{{ $q->state->label() }}</span></span>
                            <span class="tabular-nums text-slate-900">₹ {{ Format::number($q->grand_total, 0) }}</span>
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-slate-500">No quotation you may see.</li>
                    @endforelse
                </ul>
            </section>
        @endif

        @if ($data['orders'] !== null)
            <section class="card" aria-labelledby="sec-orders">
                <div class="card-header"><h2 id="sec-orders" class="text-sm font-semibold text-slate-900">Sales orders</h2></div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($data['orders'] as $order)
                        <li class="flex items-center justify-between gap-3 px-5 py-2 text-sm" wire:key="so-{{ $order->id }}">
                            <span><a href="{{ route('sales.orders.show', $order) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $order->so_number }}</a> <span class="text-slate-600">{{ Format::date($order->order_date) }}, {{ $order->state->value === 'cancelled' ? 'cancelled' : $order->approval_status }}</span></span>
                            <span class="tabular-nums text-slate-900">₹ {{ Format::number($order->grand_total, 0) }}</span>
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-slate-500">No order you may see.</li>
                    @endforelse
                </ul>
            </section>
        @endif

        @if ($data['invoices'] !== null)
            <section class="card" aria-labelledby="sec-inv">
                <div class="card-header"><h2 id="sec-inv" class="text-sm font-semibold text-slate-900">Sales invoices</h2></div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($data['invoices'] as $inv)
                        <li class="flex items-center justify-between gap-3 px-5 py-2 text-sm" wire:key="iv-{{ $inv->id }}">
                            <span><a href="{{ route('sales.invoices.show', $inv) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $inv->invoice_number }}</a> <span class="text-slate-600">{{ Format::date($inv->invoice_date) }}, {{ $inv->status }}</span></span>
                            <span class="tabular-nums text-slate-900">₹ {{ Format::number($inv->grand_total, 0) }}</span>
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-slate-500">No invoice you may see.</li>
                    @endforelse
                </ul>
            </section>
        @endif

        @if ($data['leads'] !== null)
            <section class="card" aria-labelledby="sec-leads">
                <div class="card-header"><h2 id="sec-leads" class="text-sm font-semibold text-slate-900">Leads and inquiries</h2></div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($data['leads'] as $lead)
                        <li class="flex items-center justify-between gap-3 px-5 py-2 text-sm" wire:key="ld-{{ $lead->id }}">
                            <span><a href="{{ route('crm.leads.show', $lead) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $lead->lead_number }}</a> <span class="text-slate-600">{{ $lead->stage->label() }}</span></span>
                            <span class="text-xs text-slate-500">{{ Format::date($lead->lead_date) }}</span>
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-slate-500">No lead you may see is linked to this customer.</li>
                    @endforelse
                </ul>
            </section>
        @endif

        @if ($data['communications'] !== null)
            <section class="card" aria-labelledby="sec-comms">
                <div class="card-header"><h2 id="sec-comms" class="text-sm font-semibold text-slate-900">Messages sent <span class="font-normal text-slate-500">(e-mail from campaigns and workflows)</span></h2></div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($data['communications'] as $m)
                        <li class="px-5 py-2 text-sm" wire:key="cm-{{ $m->id }}">
                            <div class="flex items-center justify-between gap-3"><span class="truncate text-slate-800">{{ $m->subject ?? $m->channel->label() }}</span><span class="shrink-0 text-xs text-slate-500">{{ Format::dateTime($m->queued_at) }}</span></div>
                            <div class="text-xs text-slate-500">{{ $m->channel->label() }} · {{ $m->statusLabel() }}{{ $m->campaign ? ' · '.$m->campaign->code : '' }}{{ $m->failure ? ' · '.$m->failure : '' }}</div>
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-slate-500">No message has been sent to this customer.</li>
                    @endforelse
                </ul>
            </section>
        @endif

        @if ($data['possible'] !== null && $data['possible']->isNotEmpty())
            <section class="card" aria-labelledby="sec-possible">
                <div class="card-header"><h2 id="sec-possible" class="text-sm font-semibold text-slate-900">Possibly this customer <span class="font-normal text-slate-500">(leads with the same mobile or e-mail, not linked)</span></h2></div>
                <ul class="divide-y divide-slate-100">
                    @foreach ($data['possible'] as $lead)
                        <li class="flex items-center justify-between gap-3 px-5 py-2 text-sm" wire:key="pl-{{ $lead->id }}">
                            <span><a href="{{ route('crm.leads.show', $lead) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $lead->lead_number }}</a> <span class="text-slate-600">{{ $lead->name }} · {{ $lead->stage->label() }}</span></span>
                            <span class="text-xs text-slate-500">{{ Format::date($lead->lead_date) }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="px-5 pb-3 text-xs text-slate-500">These are not counted in the figures above until the lead is linked to this customer.</p>
            </section>
        @endif

        @if ($data['followups'] !== null)
            <section class="card" aria-labelledby="sec-fu">
                <div class="card-header"><h2 id="sec-fu" class="text-sm font-semibold text-slate-900">Follow-ups</h2></div>
                <ul class="divide-y divide-slate-100">
                    @foreach ($data['followups']['planned'] as $f)
                        <li class="flex items-center justify-between gap-3 px-5 py-2 text-sm" wire:key="fp-{{ $f->id }}"><span class="text-slate-800">{{ $f->type->label() }} <span class="text-xs text-slate-500">· {{ $f->assignee?->name }}</span></span><span @class(['tabular-nums text-xs', 'font-medium text-rose-700' => $f->isOverdue(), 'text-slate-600' => ! $f->isOverdue()])>{{ Format::dateTime($f->scheduled_at) }}</span></li>
                    @endforeach
                    @foreach ($data['followups']['done'] as $f)
                        <li class="flex items-center justify-between gap-3 px-5 py-2 text-sm text-slate-500" wire:key="fd-{{ $f->id }}"><span>{{ $f->type->label() }} done · {{ $f->assignee?->name }}</span><span class="tabular-nums text-xs">{{ Format::dateTime($f->completed_at) }}</span></li>
                    @endforeach
                    @if ($data['followups']['planned']->isEmpty() && $data['followups']['done']->isEmpty())<li class="px-5 py-4 text-sm text-slate-500">No follow-up on the leads you may see.</li>@endif
                </ul>
            </section>
        @endif

        @if ($data['tasks'] !== null)
            <section class="card" aria-labelledby="sec-tasks">
                <div class="card-header"><h2 id="sec-tasks" class="text-sm font-semibold text-slate-900">Tasks</h2></div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($data['tasks'] as $task)
                        <li class="flex items-center justify-between gap-3 px-5 py-2 text-sm" wire:key="tk-{{ $task->id }}">
                            <span><a href="{{ route('workspace.tasks.show', $task) }}" wire:navigate class="text-brand-700 hover:underline">{{ $task->title }}</a> <span class="text-xs text-slate-500">· {{ $task->assignee?->name }}</span></span>
                            <span class="badge {{ $task->status->badge() }}">{{ $task->status->label() }}</span>
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-slate-500">No task about this customer.</li>
                    @endforelse
                </ul>
            </section>
        @endif

        @if ($data['history'] !== null)
            <section class="card" aria-labelledby="sec-hist">
                <div class="card-header"><h2 id="sec-hist" class="text-sm font-semibold text-slate-900">Deal stage history</h2></div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($data['history'] as $entry)
                        <li class="px-5 py-2 text-sm" wire:key="hs-{{ $entry->id }}"><span class="font-mono text-xs text-slate-500">{{ $entry->deal?->deal_number }}</span> {{ $entry->label() }}<div class="text-xs text-slate-500">{{ Format::dateTime($entry->created_at) }}{{ $entry->actor ? ' · '.$entry->actor->name : '' }}</div></li>
                    @empty
                        <li class="px-5 py-4 text-sm text-slate-500">No stage change yet.</li>
                    @endforelse
                </ul>
            </section>
        @endif
    </div>

    <section class="card card-body text-sm text-slate-500" aria-labelledby="sec-later">
        <h2 id="sec-later" class="text-sm font-semibold text-slate-700">Not built yet</h2>
        <ul class="mt-1 list-disc space-y-0.5 pl-5">
            @foreach ($data['later'] as $item)<li><strong class="font-medium text-slate-600">{{ $item['title'] }}</strong> (Phase {{ $item['phase'] }}) — {{ $item['why'] }}</li>@endforeach
        </ul>
        <p class="mt-2">Bank details are never shown here.</p>
    </section>

    @can('comment', $ledger)
        <livewire:shared.comments type="ledger" :id="$ledger->id" :key="'cm-'.$ledger->id" />
    @endcan
</div>

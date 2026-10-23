@php
    use App\Modules\CRM\Enums\LeadStage;
    use App\Modules\Shared\Support\Format;

    $field = fn (string $label, $value, bool $mono = false) => $value === null || $value === '' ? null : ['label' => $label, 'value' => $value, 'mono' => $mono];
    $place = collect([$lead->block?->name, $lead->district?->name, $lead->state?->name])->filter()->implode(', ');
    $details = array_values(array_filter([
        $field('Kind', $lead->lead_type->label()),
        $field('Customer', $lead->customer ? $lead->customer->code.' · '.$lead->customer->name : null),
        $field('Source', $lead->source?->name),
        $field('Lead date', $lead->lead_date->format('j M Y')),
        $field('Contact person', $lead->contact_person),
        $field('Mobile', $lead->mobile, true),
        $field('Other phone', $lead->phone, true),
        $field('E-mail', $lead->email),
        $field('Address', collect([$lead->address_line1, $lead->address_line2, $lead->city])->filter()->implode(', ')),
        $field('Block, district, state', $place),
        $field('PIN code', $lead->pin_code, true),
        $field('Expected value', $lead->expected_value !== null ? Format::inr($lead->expected_value) : null),
        $field('Branch', $lead->location?->name),
        $field('Team', $lead->team?->name),
        $field('Entered', $lead->entry_channel->label().' · '.Format::dateTime($lead->created_at)),
        $field('Last contacted', $lead->last_contacted_at ? Format::dateTime($lead->last_contacted_at) : null),
    ]));
    $needNext = $outcomes->where('needs_next_followup', true)->pluck('name')->map(fn ($n) => "“{$n}”")->implode(', ');
    $soonest = $now->copy()->addMinutes(5)->format('Y-m-d\TH:i');
@endphp

<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('crm.leads.index') }}" wire:navigate class="hover:text-brand-700">Leads</a> › {{ $lead->location?->name }} @can('task.create')· <a href="{{ route('workspace.tasks.create', ['type' => 'lead', 'id' => $lead->id]) }}" wire:navigate class="hover:text-brand-700">Add a task</a>@endcan @if ($lead->customer_ledger_id) · <a href="{{ route('crm.customers.show', $lead->customer_ledger_id) }}" wire:navigate class="hover:text-brand-700">Customer 360</a>@endif @if ($lead->campaign_id) @can('campaign.view')· <a href="{{ route('marketing.campaigns.show', $lead->campaign_id) }}" wire:navigate class="hover:text-brand-700">From a campaign ({{ $lead->attribution }})</a>@endcan @endif</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $lead->name }} <span class="font-mono text-base font-normal text-slate-500">{{ $lead->lead_number }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span class="badge {{ $lead->stage->badge() }}">{{ $lead->stage->label() }}</span>
                @if ($lead->isExisting())
                    <span class="badge {{ $lead->lead_type->badge() }}">{{ $lead->lead_type->label() }}</span>
                    <span class="badge {{ $lead->approval_status->badge() }}">{{ $lead->approval_status->label() }}</span>
                @endif
                @if (! $lead->is_complete)<span class="badge bg-amber-100 text-amber-800">Incomplete</span>@endif
                <span class="text-slate-600">{{ $lead->assignee ? 'With '.$lead->assignee->name : 'Unassigned' }}</span>
                @if ($lead->isOpen())
                    @if ($lead->next_followup_at)
                        <span @class(['text-xs', 'font-medium text-rose-700' => $lead->isOverdue(), 'text-slate-500' => ! $lead->isOverdue()])>
                            {{ $lead->isOverdue() ? 'Follow-up overdue since' : 'Next follow-up' }} {{ $lead->next_followup_at->format('j M, H:i') }}
                        </span>
                    @else
                        <span class="text-xs text-amber-700">Nothing is planned for this lead</span>
                    @endif
                @endif
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canFollowUp)
                <button type="button" wire:click="openDialog('plan')" class="btn-primary">Plan a follow-up</button>
                <button type="button" wire:click="openDialog('log')" class="btn-secondary">Log what was done</button>
            @endif
            @foreach ($moves as $move)
                <button type="button" wire:click="moveToStage('{{ $move->value }}')" class="btn-secondary" wire:key="mv-{{ $move->value }}">Move to {{ $move->label() }}</button>
            @endforeach
            @if ($canStage && $lead->stage->canMoveTo(LeadStage::Lost))<button type="button" wire:click="openDialog('lost')" class="btn-secondary">Mark as lost…</button>@endif
            @if ($canStage && $lead->stage === LeadStage::Lost)<button type="button" wire:click="openDialog('reopen')" class="btn-secondary">Re-open…</button>@endif
            @if ($canTransfer)<button type="button" wire:click="openDialog('transfer')" class="btn-secondary">Transfer…</button>@endif
            @if ($canConvert)<a href="{{ route('crm.deals.convert', $lead) }}" wire:navigate class="btn-primary">Convert to a deal</a>@endif
            @if ($canQuote)<a href="{{ route('sales.quotations.from-inquiry', $lead) }}" wire:navigate class="btn-secondary">Quote directly</a>@endif
            @if ($canReopenApproval)<button type="button" wire:click="openDialog('reopen-approval')" class="btn-secondary">Re-open for editing…</button>@endif
            @if ($canEdit)<a href="{{ $lead->isExisting() ? route('crm.leads.edit-inquiry', $lead) : route('crm.leads.edit', $lead) }}" wire:navigate class="btn-secondary">Edit</a>@endif
            @if ($canDelete)<button type="button" wire:click="delete" wire:confirm="Delete this lead? Its history is kept, and its number is not used again." class="btn-ghost text-rose-600">Delete</button>@endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif
    @if ($dialog === '')
        @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @endif

    @if ($lead->stage === LeadStage::Lost)
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
            Lost{{ $lead->lost_at ? ' on '.$lead->lost_at->format('j M Y') : '' }}: <strong>{{ $lead->lostReason?->name ?? 'no reason recorded' }}</strong>@if ($lead->lost_remarks) — “{{ $lead->lost_remarks }}”@endif
        </div>
    @elseif ($lead->stage->isSetBySystem())
        <div class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900">
            This lead has been converted, so its details and stage are no longer changed here.
            @if ($deal)
                Its deal is
                @if ($canViewDeal)<a href="{{ route('crm.deals.show', $deal) }}" wire:navigate class="font-mono font-medium underline">{{ $deal->deal_number }}</a>@else<span class="font-mono">{{ $deal->deal_number }}</span>@endif.
            @endif
        </div>
    @endif
    @if ($lead->stage === LeadStage::Lost && $deal)
        <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
            This lead’s deal
            @if ($canViewDeal)<a href="{{ route('crm.deals.show', $deal) }}" wire:navigate class="font-mono font-medium text-brand-700 underline">{{ $deal->deal_number }}</a>@else<span class="font-mono">{{ $deal->deal_number }}</span>@endif
            was lost, and the lead with it. It comes back when the deal is re-opened.
        </div>
    @endif

    <section class="card" aria-labelledby="s-details">
        <div class="card-header"><h2 id="s-details" class="text-sm font-semibold text-slate-900">Details</h2></div>
        <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-3">
            @foreach ($details as $d)
                <div>
                    <dt class="text-xs text-slate-500">{{ $d['label'] }}</dt>
                    <dd @class(['text-sm text-slate-900', 'font-mono' => $d['mono']])>{{ $d['value'] }}</dd>
                </div>
            @endforeach
            @if ($lead->requirement)
                <div class="sm:col-span-3"><dt class="text-xs text-slate-500">What they are looking for</dt><dd class="whitespace-pre-line text-sm text-slate-900">{{ $lead->requirement }}</dd></div>
            @endif
            @if ($lead->remarks)
                <div class="sm:col-span-3"><dt class="text-xs text-slate-500">Remarks</dt><dd class="whitespace-pre-line text-sm text-slate-900">{{ $lead->remarks }}</dd></div>
            @endif
        </dl>
    </section>

    @if ($lead->interests->isNotEmpty())
        <section class="card" aria-labelledby="s-items">
            <div class="card-header"><h2 id="s-items" class="text-sm font-semibold text-slate-900">{{ $lead->isExisting() ? 'Items the customer asked for' : 'Items of interest' }}</h2></div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Item</th><th class="text-right">Quantity</th><th>Unit</th>@if ($lead->isExisting())<th class="text-right">Rate expected (₹)</th><th class="text-right">Amount (₹)</th>@endif</tr></thead>
                    <tbody>
                        @foreach ($lead->interests as $interest)
                            <tr wire:key="int-{{ $interest->id }}">
                                <td>
                                    <span class="font-medium text-slate-900">{{ $interest->label() }}</span>
                                    @if ($interest->item)<span class="ml-1 font-mono text-xs text-slate-500">{{ $interest->item->code }}</span>@else<span class="badge ml-1 bg-slate-100 text-slate-600">Not in the item master</span>@endif
                                </td>
                                <td class="text-right tabular-nums">{{ $interest->quantity !== null ? rtrim(rtrim((string) $interest->quantity, '0'), '.') : '—' }}</td>
                                <td class="text-slate-600">{{ $interest->unit?->name ?? '—' }}</td>
                                @if ($lead->isExisting())
                                    <td class="text-right tabular-nums">{{ $interest->expected_rate !== null ? Format::number($interest->expected_rate, 2) : '—' }}</td>
                                    <td class="text-right tabular-nums">{{ $interest->expected_rate !== null && $interest->quantity !== null ? Format::number(bcadd(bcmul((string) $interest->quantity, (string) $interest->expected_rate, 5), '0.005', 2), 2) : '—' }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($lead->isExisting())
        <livewire:shared.approval-panel type="lead" :id="$lead->id" :key="'ap-'.$lead->id" />
    @endif

    @if ($quotations->isNotEmpty())
        <section class="card" aria-labelledby="s-quotations" id="quotations">
            <div class="card-header"><h2 id="s-quotations" class="text-sm font-semibold text-slate-900">Quotations</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($quotations as $quotation)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2.5" wire:key="qt-{{ $quotation->id }}">
                        <span>
                            <a href="{{ route('sales.quotations.show', $quotation) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $quotation->quotation_number }}</a>
                            <span class="text-slate-500">Rev {{ $quotation->revision_no }}</span>
                            <span class="badge ml-2 {{ $quotation->state->badge() }}">{{ $quotation->state->label() }}</span>
                        </span>
                        <span class="text-xs tabular-nums text-slate-500">₹ {{ \App\Modules\Shared\Support\Format::number($quotation->grand_total, 2) }} · valid until {{ $quotation->valid_until->format('j M Y') }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="card" aria-labelledby="s-followups" id="follow-ups">
        <div class="card-header">
            <h2 id="s-followups" class="text-sm font-semibold text-slate-900">Follow-ups</h2>
            <span class="text-xs text-slate-500">{{ $planned->count() }} planned · {{ $past->count() }} on record</span>
        </div>
        @if ($planned->isEmpty() && $past->isEmpty())
            <p class="card-body text-sm text-slate-500">Nothing yet. {{ $canFollowUp ? 'Plan the first follow-up so this lead is not forgotten.' : '' }}</p>
        @else
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($planned as $f)
                    <li class="flex flex-wrap items-start justify-between gap-3 px-5 py-3" wire:key="fu-{{ $f->id }}">
                        <div class="min-w-0">
                            <p>
                                <span class="font-medium text-slate-900">{{ $f->type->label() }}</span>
                                <span @class(['tabular-nums', 'font-medium text-rose-700' => $f->isOverdue(), 'text-slate-700' => ! $f->isOverdue()])>· {{ $f->scheduled_at->format('D, j M Y, H:i') }}</span>
                                @if ($f->isOverdue())<span class="badge ml-1 bg-rose-100 text-rose-800">Overdue</span>@else<span class="badge ml-1 {{ $f->status->badge() }}">{{ $f->status->label() }}</span>@endif
                            </p>
                            <p class="text-xs text-slate-500">For {{ $f->assignee?->name ?? 'nobody' }}</p>
                            @if ($f->notes)<p class="mt-1 whitespace-pre-line text-slate-600">{{ $f->notes }}</p>@endif
                        </div>
                        @if (isset($actionable[$f->id]))
                            <div class="flex shrink-0 flex-wrap gap-1">
                                <button type="button" wire:click="openDialog('done', {{ $f->id }})" class="btn-secondary btn-sm">Done…</button>
                                <button type="button" wire:click="openDialog('move', {{ $f->id }})" class="btn-ghost btn-sm">Move…</button>
                                <button type="button" wire:click="openDialog('calloff', {{ $f->id }})" class="btn-ghost btn-sm text-rose-600">Call off…</button>
                            </div>
                        @endif
                    </li>
                @endforeach
                @foreach ($past as $f)
                    <li class="px-5 py-3" wire:key="fu-{{ $f->id }}">
                        <p>
                            <span class="font-medium text-slate-900">{{ $f->type->label() }}</span>
                            <span class="tabular-nums text-slate-600">· {{ ($f->completed_at ?? $f->scheduled_at)->format('D, j M Y, H:i') }}</span>
                            <span class="badge ml-1 {{ $f->status->badge() }}">{{ $f->status->label() }}</span>
                            @if ($f->outcome)<span class="badge ml-1 bg-indigo-100 text-indigo-800">{{ $f->outcome->name }}</span>@endif
                        </p>
                        <p class="text-xs text-slate-500">{{ $f->completer ? 'By '.$f->completer->name : 'For '.($f->assignee?->name ?? 'nobody') }}</p>
                        @if ($f->notes)<p class="mt-1 whitespace-pre-line text-slate-600">{{ $f->notes }}</p>@endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card" aria-labelledby="s-stages">
            <div class="card-header"><h2 id="s-stages" class="text-sm font-semibold text-slate-900">Stage history</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($stages as $entry)
                    <li class="px-5 py-2.5" wire:key="st-{{ $entry->id }}">
                        <p class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-medium text-slate-900">{{ $entry->label() }}</span>
                            <span class="text-xs text-slate-500">{{ $entry->actor?->name ?? 'System' }} · {{ Format::dateTime($entry->created_at) }}</span>
                        </p>
                        @if ($entry->lostReason || $entry->comment)
                            <p class="text-slate-600">{{ $entry->lostReason?->name }}{{ $entry->lostReason && $entry->comment ? ' — ' : '' }}{{ $entry->comment }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="card" aria-labelledby="s-transfers">
            <div class="card-header"><h2 id="s-transfers" class="text-sm font-semibold text-slate-900">Ownership history</h2></div>
            @if ($transfers->isEmpty())
                <p class="card-body text-sm text-slate-500">This lead has not been assigned to anyone yet.</p>
            @else
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($transfers as $t)
                        <li class="px-5 py-2.5" wire:key="tr-{{ $t->id }}">
                            <p class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="font-medium text-slate-900">{{ $t->from?->name ?? 'Unassigned' }} → {{ $t->to?->name }}</span>
                                <span class="text-xs text-slate-500">{{ $t->actor?->name ?? 'System' }} · {{ Format::dateTime($t->created_at) }}</span>
                            </p>
                            <p class="text-slate-600">{{ $t->reason }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <livewire:shared.comments type="lead" :id="$lead->id" :key="'cm-'.$lead->id" />

    @if ($history->isNotEmpty())
        <section class="card" aria-labelledby="s-history">
            <div class="card-header"><h2 id="s-history" class="text-sm font-semibold text-slate-900">Audit trail</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($history as $entry)
                    @php
                        $changed = collect($entry->properties['attributes'] ?? [])->keys()->reject(fn ($k) => in_array($k, ['updated_by', 'created_by'], true))->map(fn ($k) => str_replace('_', ' ', $k))->implode(', ');
                    @endphp
                    <li class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-2.5" wire:key="h-{{ $entry->id }}">
                        <span>
                            <span class="font-medium text-slate-900">{{ str_replace('_', ' ', ucfirst((string) ($entry->event ?? $entry->description))) }}</span>
                            @if ($changed !== '')<span class="text-slate-500"> — {{ $changed }}</span>@endif
                        </span>
                        <span class="text-xs text-slate-500">{{ $entry->causer?->name ?? 'System' }} · {{ $entry->created_at?->format(config('formezy.display.datetime')) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- ---- dialogs ---- --}}
    @if ($dialog !== '')
        @php
            [$title, $submit, $action] = match ($dialog) {
                'plan' => ['Plan a follow-up', 'Plan it', 'planFollowup'],
                'log' => ['Log what was done', 'Record it', 'logFollowup'],
                'done' => ['What came of it?', 'Record it', 'completeFollowup'],
                'move' => ['Move this follow-up', 'Move it', 'moveFollowup'],
                'calloff' => ['Call this follow-up off', 'Call it off', 'callOffFollowup'],
                'lost' => ['Mark this lead as lost', 'Mark as lost', 'markLost'],
                'reopen' => ['Re-open this lead', 'Re-open', 'reopen'],
                'reopen-approval' => ['Re-open this inquiry for editing', 'Re-open', 'reopenApproval'],
                default => ['Transfer this lead', 'Transfer', 'transfer'],
            };
        @endphp
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="dialog-title">
            <form wire:submit="{{ $action }}" class="card w-full max-w-lg" novalidate wire:key="dialog-{{ $dialog }}">
                <div class="card-header">
                    <h2 id="dialog-title" class="text-sm font-semibold text-slate-900">{{ $title }}</h2>
                    <button type="button" wire:click="closeDialog" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="card-body space-y-3">
                    @error('workflow')<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror

                    @if ($target && in_array($dialog, ['done', 'move', 'calloff'], true))
                        <p class="text-sm text-slate-600">{{ $target->type->label() }} planned for <strong>{{ $target->scheduled_at->format('D, j M Y, H:i') }}</strong>, for {{ $target->assignee?->name ?? 'nobody' }}.</p>
                    @endif

                    @if ($dialog === 'plan')
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="p-when" class="label">When</label>
                                <input id="p-when" type="datetime-local" wire:model="plan.scheduled_at" min="{{ $soonest }}" class="input">
                                @error('plan.scheduled_at') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="p-type" class="label">How</label>
                                <select id="p-type" wire:model="plan.type" class="input">
                                    @foreach ($types as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach
                                </select>
                                @error('plan.type') <p class="error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div>
                            <label for="p-who" class="label">Who will do it</label>
                            <select id="p-who" wire:model="plan.assigned_to" class="input">
                                @foreach ($assignable as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                            <p class="help">They are reminded shortly before it is due, in the app and by e-mail.</p>
                            @error('plan.assigned_to') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="p-notes" class="label">Notes <span class="font-normal text-slate-500">(optional)</span></label>
                            <textarea id="p-notes" rows="2" wire:model="plan.notes" class="input" maxlength="2000"></textarea>
                            @error('plan.notes') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($dialog === 'log' || $dialog === 'done')
                        @php $form = $dialog; @endphp
                        @if ($dialog === 'log')
                            <div>
                                <label for="o-type" class="label">How did you follow up?</label>
                                <select id="o-type" wire:model="log.type" class="input">
                                    @foreach ($types as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach
                                </select>
                                @error('log.type') <p class="error">{{ $message }}</p> @enderror
                            </div>
                        @endif
                        <div>
                            <label for="o-outcome" class="label">Outcome</label>
                            <select id="o-outcome" wire:model="{{ $form }}.outcome_id" class="input">
                                <option value="">Select…</option>
                                @foreach ($outcomes as $outcome)<option value="{{ $outcome->id }}">{{ $outcome->name }}</option>@endforeach
                            </select>
                            @error("$form.outcome_id") <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="o-notes" class="label">Notes <span class="font-normal text-slate-500">(optional)</span></label>
                            <textarea id="o-notes" rows="3" wire:model="{{ $form }}.notes" class="input" maxlength="2000"></textarea>
                            @error("$form.notes") <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="o-next" class="label">Next follow-up</label>
                                <input id="o-next" type="datetime-local" wire:model="{{ $form }}.next_at" min="{{ $soonest }}" class="input">
                                @error("$form.next_at") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="o-next-type" class="label">How</label>
                                <select id="o-next-type" wire:model="{{ $form }}.next_type" class="input">
                                    <option value="">Same as this one</option>
                                    @foreach ($types as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach
                                </select>
                            </div>
                        </div>
                        <p class="help">Leave the next follow-up empty if there is none{{ $needNext !== '' ? '. It is required for '.$needNext : '' }}.</p>
                    @endif

                    @if ($dialog === 'move')
                        <div>
                            <label for="m-when" class="label">New date and time</label>
                            <input id="m-when" type="datetime-local" wire:model="when" min="{{ $soonest }}" class="input">
                            <p class="help">The reminder is sent again for the new time.</p>
                            @error('when') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($dialog === 'calloff')
                        <div>
                            <label for="c-reason" class="label">Why is it being called off?</label>
                            <textarea id="c-reason" rows="2" wire:model="reason" class="input" maxlength="500"></textarea>
                            <p class="help">It stays on the lead’s record as cancelled, with this reason.</p>
                            @error('reason') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($dialog === 'lost')
                        <p class="text-sm text-slate-600">The lead leaves the open list and whatever is planned for it is called off. It can be re-opened later.</p>
                        <div>
                            <label for="x-reason" class="label">Why was it lost?</label>
                            <select id="x-reason" wire:model="lostReasonId" class="input">
                                <option value="">Select…</option>
                                @foreach ($lostReasons as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                            @error('lost_reason_id') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="x-comment" class="label">Anything to add? <span class="font-normal text-slate-500">(optional)</span></label>
                            <textarea id="x-comment" rows="2" wire:model="comment" class="input" maxlength="1000"></textarea>
                            @error('comment') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($dialog === 'reopen')
                        <div>
                            <label for="r-to" class="label">Re-open as</label>
                            <select id="r-to" wire:model="reopenTo" class="input">
                                @foreach ($reopenStages as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label for="r-comment" class="label">Why is it being re-opened?</label>
                            <textarea id="r-comment" rows="2" wire:model="comment" class="input" maxlength="1000"></textarea>
                            @error('comment') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($dialog === 'reopen-approval')
                        <p class="text-sm text-slate-600">The inquiry goes back to a draft so it can be corrected. It cannot be quoted until it is approved again.</p>
                        <div>
                            <label for="a-reason" class="label">Why is it being re-opened?</label>
                            <textarea id="a-reason" rows="2" wire:model="reason" class="input" maxlength="1000"></textarea>
                            @error('reason') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($dialog === 'transfer')
                        <p class="text-sm text-slate-600">The new owner takes over the lead and whatever is planned for it, and is told. The transfer is kept in the lead’s history.</p>
                        @error('leads')<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror
                        <div>
                            <label for="t-to" class="label">Hand over to</label>
                            <select id="t-to" wire:model="transferTo" class="input">
                                <option value="">Select…</option>
                                @foreach ($transferTargets as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                            <p class="help">Yourself or someone on a team you manage, who works in {{ $lead->location?->name ?? 'the lead’s branch' }}.</p>
                            @error('to_user_id') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="t-reason" class="label">Why?</label>
                            <textarea id="t-reason" rows="2" wire:model="reason" class="input" maxlength="1000"></textarea>
                            @error('reason') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeDialog" class="btn-secondary">Cancel</button>
                    <button type="submit" @class(['btn-danger' => in_array($dialog, ['lost', 'calloff'], true), 'btn-primary' => ! in_array($dialog, ['lost', 'calloff'], true)]) wire:loading.attr="disabled">{{ $submit }}</button>
                </div>
            </form>
        </div>
    @endif
</div>

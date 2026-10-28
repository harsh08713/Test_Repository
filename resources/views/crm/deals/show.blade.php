@php
    use App\Modules\CRM\Enums\DealStage;
    use App\Modules\Shared\Support\Format;

    $field = fn (string $label, $value, bool $mono = false) => $value === null || $value === '' ? null : ['label' => $label, 'value' => $value, 'mono' => $mono];
    $details = array_values(array_filter([
        $field('Customer', $deal->customer ? $deal->customer->code.' · '.$deal->customer->name : 'Not a customer yet'),
        $field('Contact person', $deal->contact_person),
        $field('Mobile', $deal->mobile, true),
        $field('E-mail', $deal->email),
        $field('Deal date', $deal->deal_date->format('j M Y')),
        $field('Expected to close', $deal->expected_close_date->format('j M Y')),
        $field('Branch', $deal->location?->name),
        $field('Team', $deal->team?->name),
    ]));
@endphp

<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('crm.deals.index') }}" wire:navigate class="hover:text-brand-700">Deals</a> › {{ $deal->location?->name }} @can('task.create')· <a href="{{ route('workspace.tasks.create', ['type' => 'deal', 'id' => $deal->id]) }}" wire:navigate class="hover:text-brand-700">Add a task</a>@endcan @if ($deal->customer_ledger_id) · <a href="{{ route('crm.customers.show', $deal->customer_ledger_id) }}" wire:navigate class="hover:text-brand-700">Customer 360</a>@endif</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $deal->party_name }} <span class="font-mono text-base font-normal text-slate-500">{{ $deal->deal_number }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span class="badge {{ $deal->stage->badge() }}">{{ $deal->stage->label() }}</span>
                <span class="badge {{ $deal->approval_status->badge() }}">{{ $deal->approval_status->label() }}</span>
                <span class="text-slate-600">With {{ $deal->assignee?->name ?? 'nobody' }}</span>
                <span class="tabular-nums text-slate-600">{{ $deal->probability }}% likely</span>
                @if ($deal->isLate())<span class="badge bg-rose-100 text-rose-800">Close date passed</span>@endif
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach ($moves as $move)
                <button type="button" wire:click="moveToStage('{{ $move->value }}')" class="btn-secondary" wire:key="mv-{{ $move->value }}">Move to {{ $move->label() }}</button>
            @endforeach
            @if ($canProgress)<button type="button" wire:click="openDialog('progress')" class="btn-secondary">Update progress…</button>@endif
            @if ($canLose)<button type="button" wire:click="openDialog('lost')" class="btn-secondary">Mark as lost…</button>@endif
            @if ($canReopenLost)<button type="button" wire:click="openDialog('reopen-lost')" class="btn-secondary">Re-open…</button>@endif
            @if ($canReassign)<button type="button" wire:click="openDialog('reassign')" class="btn-secondary">Hand over…</button>@endif
            @if ($canReopenApproval)<button type="button" wire:click="openDialog('reopen-approval')" class="btn-secondary">Re-open for editing…</button>@endif
            @if ($canQuote)<a href="{{ route('sales.quotations.from-deal', $deal) }}" wire:navigate class="btn-primary">Make a quotation</a>@endif
            @if ($canOrder)<a href="{{ route('sales.orders.from-deal', $deal) }}" wire:navigate class="btn-secondary">Make a sales order</a>@endif
            @if ($canEdit)<a href="{{ route('crm.deals.edit', $deal) }}" wire:navigate class="btn-secondary">Edit lines</a>@endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif
    @if ($dialog === '')
        @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @endif

    @if ($deal->stage === DealStage::Lost)
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
            Lost{{ $deal->lost_at ? ' on '.$deal->lost_at->format('j M Y') : '' }}: <strong>{{ $deal->lostReason?->name ?? 'no reason recorded' }}</strong>@if ($deal->lost_remarks) — “{{ $deal->lost_remarks }}”@endif
        </div>
    @elseif ($deal->stage->isSetBySystem())
        <div class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900">A {{ strtolower($deal->stage->label()) }} has been made from this deal, so its customer and lines are no longer changed here.</div>
    @elseif ($deal->isPendingApproval())
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">This deal is waiting for approval, so its customer and lines are locked. Where it stands (stage, probability, close date) can still be updated.</div>
    @endif

    <section class="card" aria-labelledby="s-value">
        <div class="card-header"><h2 id="s-value" class="text-sm font-semibold text-slate-900">Value</h2></div>
        <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-3">
            <div><dt class="text-xs text-slate-500">Expected value</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">₹ {{ Format::number($deal->expected_value, 2) }}</dd></div>
            <div><dt class="text-xs text-slate-500">Probability</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">{{ $deal->probability }}%</dd></div>
            <div><dt class="text-xs text-slate-500">Weighted by probability</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">₹ {{ Format::number($deal->weightedValue(), 2) }}</dd></div>
        </dl>
    </section>

    @if ($dealForecast)
        <section class="card" aria-labelledby="s-forecast" id="forecast">
            <div class="card-header flex-wrap">
                <h2 id="s-forecast" class="text-sm font-semibold text-slate-900">Forecast from history <span class="font-normal text-slate-500">(advice)</span></h2>
                <span class="badge {{ $dealForecast->badge() }}">{{ $dealForecast->confidenceLabel() }}</span>
            </div>
            <div class="card-body space-y-3">
                <dl class="grid gap-x-8 gap-y-3 sm:grid-cols-3">
                    <div><dt class="text-xs text-slate-500">History says</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">{{ $dealForecast->percent }}%</dd></div>
                    <div><dt class="text-xs text-slate-500">Your figure</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">{{ $deal->probability }}%</dd></div>
                    <div><dt class="text-xs text-slate-500">Weighted by history</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">₹ {{ Format::number($dealForecast->weighted, 2) }}</dd></div>
                </dl>
                <p class="text-sm text-slate-700">
                    @if ($dealForecast->samples === 0)
                        No closed deal has reached {{ $dealForecast->stage->label() }} yet, so this is just the usual {{ $dealForecast->prior }}% for the stage.
                    @else
                        Of {{ $dealForecast->samples }} closed {{ $dealForecast->samples === 1 ? 'deal' : 'deals' }} that reached {{ $dealForecast->stage->label() }}, {{ $dealForecast->won }} {{ $dealForecast->won === 1 ? 'was' : 'were' }} won. The figure starts from the usual {{ $dealForecast->prior }}% for the stage and moves towards that result as more deals close.
                    @endif
                    @if ($dealForecast->confidence === 'thin')
                        There is too little history to lean on yet.
                    @endif
                </p>
                @if (abs($dealForecast->percent - $deal->probability) >= 20)
                    <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">The history-based figure is {{ $dealForecast->percent > $deal->probability ? 'much higher' : 'much lower' }} than yours — worth a second look at this deal.</p>
                @endif
                <p class="text-xs text-slate-500">Worked out from this company’s own closed deals, not by an AI. It is shown beside your figure and never replaces it: the deal’s probability, the pipeline totals and the dashboards still use the figure a person set.</p>
            </div>
        </section>
    @endif

    <section class="card" aria-labelledby="s-details">
        <div class="card-header"><h2 id="s-details" class="text-sm font-semibold text-slate-900">Details</h2></div>
        <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-3">
            @foreach ($details as $d)
                <div>
                    <dt class="text-xs text-slate-500">{{ $d['label'] }}</dt>
                    <dd @class(['text-sm text-slate-900', 'font-mono' => $d['mono']])>
                        @if ($d['label'] === 'Customer' && $canViewCustomer)
                            <a href="{{ route('masters.ledgers.show', $deal->customer) }}" wire:navigate class="text-brand-700 hover:underline">{{ $d['value'] }}</a>
                        @else
                            {{ $d['value'] }}
                        @endif
                    </dd>
                </div>
            @endforeach
            <div>
                <dt class="text-xs text-slate-500">Made from lead</dt>
                <dd class="text-sm text-slate-900">
                    @if ($lead && $canViewLead)
                        <a href="{{ route('crm.leads.show', $lead) }}" wire:navigate class="font-mono text-xs text-brand-700 hover:underline">{{ $lead->lead_number }}</a>
                    @elseif ($lead)
                        <span class="font-mono text-xs">{{ $lead->lead_number }}</span>
                    @endif
                    @if ($lead) · {{ $lead->lead_type->label() }}@endif
                </dd>
            </div>
            @if ($deal->remarks)
                <div class="sm:col-span-3"><dt class="text-xs text-slate-500">Remarks</dt><dd class="whitespace-pre-line text-sm text-slate-900">{{ $deal->remarks }}</dd></div>
            @endif
        </dl>
    </section>

    <section class="card" aria-labelledby="s-items">
        <div class="card-header"><h2 id="s-items" class="text-sm font-semibold text-slate-900">Item lines</h2><span class="text-xs text-slate-500">{{ $deal->items->count() }} {{ $deal->items->count() === 1 ? 'line' : 'lines' }}</span></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>#</th><th>Item</th><th class="text-right">Quantity</th><th>Unit</th><th class="text-right">Rate expected (₹)</th><th class="text-right">Amount (₹)</th></tr></thead>
                <tbody>
                    @foreach ($deal->items as $line)
                        <tr wire:key="ln-{{ $line->id }}">
                            <td class="tabular-nums text-slate-500">{{ $line->line_no }}</td>
                            <td><span class="font-medium text-slate-900">{{ $line->item?->name }}</span> <span class="ml-1 font-mono text-xs text-slate-500">{{ $line->item?->code }}</span></td>
                            <td class="text-right tabular-nums">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                            <td class="text-slate-600">{{ $line->unit?->name }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($line->expected_rate, 2) }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($line->amount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr><td colspan="5" class="text-right text-sm font-medium text-slate-700">Total</td><td class="text-right font-semibold tabular-nums text-slate-900">{{ Format::number($deal->expected_value, 2) }}</td></tr></tfoot>
            </table>
        </div>
    </section>

    <section class="card" aria-labelledby="s-quotations" id="quotations">
        <div class="card-header">
            <h2 id="s-quotations" class="text-sm font-semibold text-slate-900">Quotations</h2>
            @if ($canQuote)<a href="{{ route('sales.quotations.from-deal', $deal) }}" wire:navigate class="text-xs text-brand-700 hover:underline">Make a quotation</a>@endif
        </div>
        @if ($quotations->isEmpty())
            <p class="card-body text-sm text-slate-500">
                No quotation has been made from this deal.
                @if (! $deal->isApproved())The deal has to be approved first.@else A quotation is optional: an order can also be taken from an approved deal directly.@endif
            </p>
        @else
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($quotations as $quotation)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2.5" wire:key="qt-{{ $quotation->id }}">
                        <span>
                            <a href="{{ route('sales.quotations.show', $quotation) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $quotation->quotation_number }}</a>
                            <span class="text-slate-500">Rev {{ $quotation->revision_no }}</span>
                            <span class="badge ml-2 {{ $quotation->state->badge() }}">{{ $quotation->state->label() }}</span>
                        </span>
                        <span class="text-xs tabular-nums text-slate-500">₹ {{ Format::number($quotation->grand_total, 2) }} · valid until {{ $quotation->valid_until->format('j M Y') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($orders->isNotEmpty())
        <section class="card" aria-labelledby="s-orders">
            <div class="card-header"><h2 id="s-orders" class="text-sm font-semibold text-slate-900">Sales orders</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($orders as $so)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2.5" wire:key="so-{{ $so->id }}">
                        <span>
                            <a href="{{ route('sales.orders.show', $so) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $so->so_number }}</a>
                            <span class="badge ml-2 {{ $so->state->badge() }}">{{ $so->state->label() }}</span>
                            <span class="badge ml-1 {{ $so->approval_status->badge() }}">{{ $so->approval_status->label() }}</span>
                        </span>
                        <span class="text-xs tabular-nums text-slate-500">₹ {{ Format::number($so->grand_total, 2) }} · {{ $so->order_date->format('j M Y') }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <livewire:shared.approval-panel type="deal" :id="$deal->id" :key="'ap-'.$deal->id" />

    <section class="card" aria-labelledby="s-followups" id="follow-ups">
        <div class="card-header">
            <h2 id="s-followups" class="text-sm font-semibold text-slate-900">Follow-ups</h2>
            @if ($lead && $canViewLead)<a href="{{ route('crm.leads.show', $lead) }}#follow-ups" wire:navigate class="text-xs text-brand-700 hover:underline">Plan or record one on the lead</a>@endif
        </div>
        @if ($followups->isEmpty())
            <p class="card-body text-sm text-slate-500">Nothing has been planned or recorded for the lead this deal was made from. Follow-ups are kept on the lead, which stays open while the deal is.</p>
        @else
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($followups as $f)
                    <li class="px-5 py-3" wire:key="fu-{{ $f->id }}">
                        <p>
                            <span class="font-medium text-slate-900">{{ $f->type->label() }}</span>
                            <span class="tabular-nums text-slate-600">· {{ ($f->completed_at ?? $f->scheduled_at)->format('D, j M Y, H:i') }}</span>
                            @if ($f->isOverdue())<span class="badge ml-1 bg-rose-100 text-rose-800">Overdue</span>@else<span class="badge ml-1 {{ $f->status->badge() }}">{{ $f->status->label() }}</span>@endif
                            @if ($f->outcome)<span class="badge ml-1 bg-indigo-100 text-indigo-800">{{ $f->outcome->name }}</span>@endif
                        </p>
                        <p class="text-xs text-slate-500">For {{ $f->assignee?->name ?? 'nobody' }}</p>
                        @if ($f->notes)<p class="mt-1 whitespace-pre-line text-slate-600">{{ $f->notes }}</p>@endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

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

    <livewire:shared.comments type="deal" :id="$deal->id" :key="'cm-'.$deal->id" />

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
                'lost' => ['Mark this deal as lost', 'Mark as lost', 'markLost'],
                'reopen-lost' => ['Re-open this deal', 'Re-open', 'reopenLost'],
                'reopen-approval' => ['Re-open this deal for editing', 'Re-open', 'reopenApproval'],
                'reassign' => ['Hand this deal over', 'Hand over', 'reassign'],
                default => ['Where the deal stands', 'Save', 'saveProgress'],
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

                    @if ($dialog === 'progress')
                        <p class="text-sm text-slate-600">These can change while the deal is open, even after it is approved. The customer and the lines cannot.</p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="sm:col-span-2">
                                <label for="p-close" class="label">Expected to close on</label>
                                <input id="p-close" type="date" wire:model="progress.expected_close_date" class="input">
                                @error('deal.expected_close_date') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="p-prob" class="label">Probability %</label>
                                <input id="p-prob" type="number" min="1" max="99" step="1" wire:model="progress.probability" class="input tabular-nums">
                                @error('deal.probability') <p class="error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div>
                            <label for="p-remarks" class="label">Remarks <span class="font-normal text-slate-500">(optional)</span></label>
                            <textarea id="p-remarks" rows="3" wire:model="progress.remarks" class="input" maxlength="2000"></textarea>
                            @error('deal.remarks') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($dialog === 'lost')
                        <p class="text-sm text-slate-600">The deal leaves the open pipeline and its lead is marked lost with it; what was planned for the lead is called off. A lost deal can be re-opened by someone who is allowed to.</p>
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

                    @if ($dialog === 'reopen-lost')
                        <div>
                            <label for="r-to" class="label">Re-open in</label>
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
                        <p class="text-sm text-slate-600">The deal goes back to a draft so its customer and lines can be corrected. It cannot be quoted or ordered from until it is approved again.</p>
                        <div>
                            <label for="a-reason" class="label">Why is it being re-opened?</label>
                            <textarea id="a-reason" rows="2" wire:model="reason" class="input" maxlength="1000"></textarea>
                            @error('reason') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($dialog === 'reassign')
                        <p class="text-sm text-slate-600">The new owner takes over the deal and its lead — with whatever is planned for the lead — and is told. The hand-over is kept in the lead’s history.</p>
                        @error('leads')<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror
                        <div>
                            <label for="h-to" class="label">Hand over to</label>
                            <select id="h-to" wire:model="reassignTo" class="input">
                                <option value="">Select…</option>
                                @foreach ($reassignTargets as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                            <p class="help">Yourself or someone on a team you manage, who works in {{ $deal->location?->name ?? 'the deal’s branch' }}.</p>
                            @error('to_user_id') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="h-reason" class="label">Why?</label>
                            <textarea id="h-reason" rows="2" wire:model="reason" class="input" maxlength="1000"></textarea>
                            @error('reason') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeDialog" class="btn-secondary">Cancel</button>
                    <button type="submit" @class(['btn-danger' => $dialog === 'lost', 'btn-primary' => $dialog !== 'lost']) wire:loading.attr="disabled">{{ $submit }}</button>
                </div>
            </form>
        </div>
    @endif
</div>

<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Leads</h1>
            <p class="mt-1 text-sm text-slate-500">People and companies who might buy: where each came from, who is working it and what happens next. You see your own leads, and those of the people on the teams you manage.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canImport)
                <a href="{{ route('crm.leads.import') }}" wire:navigate class="btn-secondary"><x-icon name="upload" class="size-4" /> Import from Excel</a>
            @endif
            @if ($canCreateInquiry)
                <a href="{{ route('crm.leads.create-inquiry') }}" wire:navigate class="btn-secondary">Add an inquiry from a customer</a>
            @endif
            @if ($canCreate)
                <a href="{{ route('crm.leads.create') }}" wire:navigate class="btn-primary">Add a lead</a>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif
    @if (! $transferOpen)
        @error('leads')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @endif

    <nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Lead stages">
        @foreach ($tabs as [$value, $label, $count])
            <button type="button" wire:click="setStage('{{ $value }}')" wire:key="tab-{{ $value === '' ? 'all' : $value }}"
                    @class([
                        'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
                        'border-brand-600 text-brand-700' => $stage === $value,
                        'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $stage !== $value,
                    ])
                    @if ($stage === $value) aria-current="page" @endif>{{ $label }} <span class="ml-1 tabular-nums text-xs text-slate-400">{{ number_format($count) }}</span></button>
        @endforeach
    </nav>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="l-search" class="sr-only">Search</label>
                <input id="l-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Name, number, mobile, city…" class="input max-w-xs">

                <label for="l-owner" class="sr-only">Owner</label>
                <select id="l-owner" wire:model.live="owner" class="input w-48">
                    <option value="">Any owner</option>
                    @if ($showPool)<option value="none">Unassigned</option>@endif
                    @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                <label for="l-due" class="sr-only">Next follow-up</label>
                <select id="l-due" wire:model.live="due" class="input w-48">
                    <option value="">Any follow-up</option>
                    <option value="overdue">Follow-up overdue</option>
                    <option value="today">Follow-up today</option>
                    <option value="week">Follow-up within 7 days</option>
                    <option value="none">Nothing planned</option>
                </select>

                <label for="l-type" class="sr-only">Kind</label>
                <select id="l-type" wire:model.live="type" class="input w-44">
                    <option value="">New leads and customers</option>
                    <option value="new">New leads only</option>
                    <option value="existing">Existing customers only</option>
                </select>

                <label for="l-source" class="sr-only">Source</label>
                <select id="l-source" wire:model.live="source" class="input w-44">
                    <option value="">All sources</option>
                    @foreach ($sources as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                <label for="l-branch" class="sr-only">Branch</label>
                <select id="l-branch" wire:model.live="branch" class="input w-44">
                    <option value="">All branches</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                <label for="l-from" class="text-sm text-slate-600">Lead date</label>
                <input id="l-from" type="date" wire:model.live="from" class="input w-40" aria-label="Lead date from">
                <label for="l-to" class="text-sm text-slate-600">to</label>
                <input id="l-to" type="date" wire:model.live="to" class="input w-40" aria-label="Lead date to">
            </div>
        </div>

        @if ($canTransfer)
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 bg-slate-50 px-5 py-2.5 text-sm">
                <span class="text-slate-600" aria-live="polite"><strong class="tabular-nums text-slate-900">{{ $selectedCount }}</strong> selected</span>
                <button type="button" wire:click="selectPage" class="btn-ghost btn-sm">Select this page</button>
                @if ($selectedCount > 0)
                    <button type="button" wire:click="clearSelection" class="btn-ghost btn-sm">Clear</button>
                    <button type="button" wire:click="openTransfer" class="btn-secondary btn-sm">Transfer selected…</button>
                @endif
                <span class="text-xs text-slate-500">Tick leads to hand them to someone else, up to {{ $maxTransfer }} at a time.</span>
            </div>
        @endif

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        @if ($canTransfer)<th class="w-10"><span class="sr-only">Select</span></th>@endif
                        <th>Lead</th><th>Contact</th><th>Source</th><th>Owner</th><th>Stage</th><th>Next follow-up</th><th class="text-right">Expected (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($leads as $lead)
                        <tr wire:key="lead-{{ $lead->id }}">
                            @if ($canTransfer)
                                <td>
                                    @if ($lead->isOpen())
                                        <input type="checkbox" wire:model.live="selected" value="{{ $lead->id }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" aria-label="Select {{ $lead->lead_number }}">
                                    @endif
                                </td>
                            @endif
                            <td>
                                <a href="{{ route('crm.leads.show', $lead) }}" wire:navigate class="font-medium text-slate-900 hover:text-brand-700">{{ $lead->name }}</a>
                                @if (! $lead->is_complete)<span class="badge ml-1 bg-amber-100 text-amber-800">Incomplete</span>@endif
                                @if ($lead->isExisting())<span class="badge ml-1 {{ $lead->lead_type->badge() }}">Customer</span>@endif
                                <div class="font-mono text-xs text-slate-500">{{ $lead->lead_number }} · {{ $lead->lead_date->format('j M Y') }} · {{ $lead->location?->code }}</div>
                            </td>
                            <td class="text-slate-600">
                                {{ $lead->contact_person ?: '—' }}
                                <div class="text-xs tabular-nums text-slate-500">{{ $lead->mobile ?: 'no mobile' }}@if ($lead->city) · {{ $lead->city }}@endif</div>
                            </td>
                            <td class="text-slate-600">{{ $lead->source?->name }}</td>
                            <td>{{ $lead->assignee?->name ?? 'Unassigned' }}</td>
                            <td><span class="badge {{ $lead->stage->badge() }}">{{ $lead->stage->label() }}</span></td>
                            <td class="whitespace-nowrap">
                                @if ($lead->next_followup_at && $lead->isOpen())
                                    <span @class(['tabular-nums', 'font-medium text-rose-700' => $lead->next_followup_at->lt($now), 'text-slate-700' => ! $lead->next_followup_at->lt($now)])>{{ $lead->next_followup_at->format('j M, H:i') }}</span>
                                    @if ($lead->next_followup_at->lt($now))<span class="badge ml-1 bg-rose-100 text-rose-800">Overdue</span>@endif
                                @elseif ($lead->isOpen())
                                    <span class="text-slate-400">Nothing planned</span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="text-right tabular-nums">{{ $lead->expected_value !== null ? \App\Modules\Shared\Support\Format::number($lead->expected_value, 0) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canTransfer ? 8 : 7 }}" class="py-10 text-center text-slate-500">No leads match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($leads->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $leads->links() }}</div>
        @endif
    </div>

    @if ($transferOpen)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="transfer-title">
            <form wire:submit="transfer" class="card w-full max-w-lg" novalidate>
                <div class="card-header">
                    <h2 id="transfer-title" class="text-sm font-semibold text-slate-900">Transfer {{ $selectedCount }} {{ $selectedCount === 1 ? 'lead' : 'leads' }}</h2>
                    <button type="button" wire:click="closeTransfer" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="card-body space-y-3">
                    <p class="text-sm text-slate-600">The new owner takes over the leads and whatever is planned for them, and is told. Every transfer is kept in each lead’s history. <strong>All or nothing</strong>: if one of the leads cannot be moved, none is.</p>
                    @error('leads')<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror
                    <div>
                        <label for="t-to" class="label">Hand over to</label>
                        <select id="t-to" wire:model="transferTo" class="input">
                            <option value="">Select…</option>
                            @foreach ($transferTargets as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                        @error('to_user_id') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="t-reason" class="label">Why?</label>
                        <textarea id="t-reason" rows="3" wire:model="reason" class="input" maxlength="1000" placeholder="e.g. Territory re-allocated"></textarea>
                        @error('reason') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeTransfer" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">Transfer</button>
                </div>
            </form>
        </div>
    @endif
</div>

@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Deals</h1>
            <p class="mt-1 text-sm text-slate-500">Qualified leads taken up as business: what is wanted, for whom, how likely and by when. A deal is made by converting a qualified lead, from the lead’s page. You see your own deals, and those of the people on the teams you manage.</p>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif

    <dl class="grid gap-3 sm:grid-cols-3">
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Open deals</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format($pipelineCount) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Open value</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($pipelineValue, 0) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Weighted by probability</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($pipelineWeighted, 0) }}</dd></div>
    </dl>

    <nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Deal stages">
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
                <label for="d-search" class="sr-only">Search</label>
                <input id="d-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Name, deal number, mobile…" class="input max-w-xs">

                <label for="d-owner" class="sr-only">Owner</label>
                <select id="d-owner" wire:model.live="owner" class="input w-48">
                    <option value="">Any owner</option>
                    @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                <label for="d-approval" class="sr-only">Approval</label>
                <select id="d-approval" wire:model.live="approval" class="input w-44">
                    <option value="">Any approval state</option>
                    @foreach ($approvals as $a)<option value="{{ $a->value }}">{{ $a->label() }}</option>@endforeach
                </select>

                <label for="d-closing" class="sr-only">Expected close</label>
                <select id="d-closing" wire:model.live="closing" class="input w-48">
                    <option value="">Any close date</option>
                    <option value="late">Close date passed</option>
                    <option value="month">Closing this month</option>
                </select>

                <label for="d-branch" class="sr-only">Branch</label>
                <select id="d-branch" wire:model.live="branch" class="input w-44">
                    <option value="">All branches</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Deal</th><th>Customer</th><th>Owner</th><th>Stage</th><th>Approval</th><th class="text-right">Value (₹)</th><th class="text-right">Likely</th><th>Expected close</th></tr>
                </thead>
                <tbody>
                    @forelse ($deals as $deal)
                        <tr wire:key="deal-{{ $deal->id }}">
                            <td>
                                <a href="{{ route('crm.deals.show', $deal) }}" wire:navigate class="font-medium text-slate-900 hover:text-brand-700">{{ $deal->party_name }}</a>
                                <div class="font-mono text-xs text-slate-500">{{ $deal->deal_number }} · {{ $deal->items_count }} {{ $deal->items_count === 1 ? 'line' : 'lines' }}</div>
                            </td>
                            <td class="text-slate-600">{{ $deal->customer ? $deal->customer->name : 'Prospect' }}</td>
                            <td>{{ $deal->assignee?->name }}</td>
                            <td><span class="badge {{ $deal->stage->badge() }}">{{ $deal->stage->label() }}</span></td>
                            <td><span class="badge {{ $deal->approval_status->badge() }}">{{ $deal->approval_status->label() }}</span></td>
                            <td class="text-right tabular-nums">{{ Format::number($deal->expected_value, 0) }}</td>
                            <td class="text-right tabular-nums">{{ $deal->probability }}%</td>
                            <td class="whitespace-nowrap">
                                <span @class(['tabular-nums', 'font-medium text-rose-700' => $deal->isOpen() && $deal->expected_close_date->lt($today), 'text-slate-700' => ! ($deal->isOpen() && $deal->expected_close_date->lt($today))])>{{ $deal->expected_close_date->format('j M Y') }}</span>
                                @if ($deal->isOpen() && $deal->expected_close_date->lt($today))<span class="badge ml-1 bg-rose-100 text-rose-800">Passed</span>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-10 text-center text-slate-500">No deals match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($deals->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $deals->links() }}</div>
        @endif
    </div>
</div>

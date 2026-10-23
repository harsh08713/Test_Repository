@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-5">
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">Quotations</h1>
        <p class="mt-1 text-sm text-slate-500">Offers made to customers, priced from the rate master with GST worked out by place of supply. A quotation is made from an approved deal (or an approved inquiry), from that page. Each change after sending is a new revision. You see those of your own leads, and of the people on the teams you manage.</p>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif

    <dl class="grid gap-3 sm:grid-cols-2">
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Out with customers</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format($outCount) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Value out with customers</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($outValue, 0) }}</dd></div>
    </dl>

    <nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Quotation states">
        @foreach ($tabs as [$value, $label, $count])
            <button type="button" wire:click="setState('{{ $value }}')" wire:key="tab-{{ $value === '' ? 'all' : $value }}"
                    @class([
                        'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
                        'border-brand-600 text-brand-700' => $state === $value,
                        'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $state !== $value,
                    ])
                    @if ($state === $value) aria-current="page" @endif>{{ $label }} <span class="ml-1 tabular-nums text-xs text-slate-400">{{ number_format($count) }}</span></button>
        @endforeach
    </nav>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="q-search" class="sr-only">Search</label>
                <input id="q-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Customer, quotation number…" class="input max-w-xs">

                <label for="q-approval" class="sr-only">Approval</label>
                <select id="q-approval" wire:model.live="approval" class="input w-44">
                    <option value="">Any approval state</option>
                    @foreach ($approvals as $a)<option value="{{ $a->value }}">{{ $a->label() }}</option>@endforeach
                </select>

                <label for="q-validity" class="sr-only">Validity</label>
                <select id="q-validity" wire:model.live="validity" class="input w-48">
                    <option value="">Any validity</option>
                    <option value="expiring">Expiring within a week</option>
                </select>

                <label for="q-branch" class="sr-only">Branch</label>
                <select id="q-branch" wire:model.live="branch" class="input w-44">
                    <option value="">All branches</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Quotation</th><th>Customer</th><th>Status</th><th>Approval</th><th class="text-right">Total (₹)</th><th>Date</th><th>Valid until</th></tr>
                </thead>
                <tbody>
                    @forelse ($quotations as $q)
                        <tr wire:key="quo-{{ $q->id }}">
                            <td>
                                <a href="{{ route('sales.quotations.show', $q) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $q->quotation_number }}</a>
                                <div class="text-xs text-slate-500">Rev {{ $q->revision_no }} · {{ $q->location?->name }}</div>
                            </td>
                            <td class="font-medium text-slate-900">{{ $q->party_name }}</td>
                            <td><span class="badge {{ $q->state->badge() }}">{{ $q->state->label() }}</span></td>
                            <td><span class="badge {{ $q->approval_status->badge() }}">{{ $q->approval_status->label() }}</span></td>
                            <td class="text-right tabular-nums">{{ Format::number($q->grand_total, 2) }}</td>
                            <td class="whitespace-nowrap tabular-nums text-slate-600">{{ $q->quotation_date->format('j M Y') }}</td>
                            <td class="whitespace-nowrap">
                                <span @class(['tabular-nums', 'font-medium text-rose-700' => $q->state->isLive() && $q->valid_until->lt($today), 'text-slate-700' => ! ($q->state->isLive() && $q->valid_until->lt($today))])>{{ $q->valid_until->format('j M Y') }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-slate-500">No quotations match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($quotations->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $quotations->links() }}</div>
        @endif
    </div>
</div>

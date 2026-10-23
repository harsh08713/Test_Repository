@php
    use App\Modules\Shared\Support\Format;

    $sevClass = ['low' => 'bg-slate-100 text-slate-700', 'medium' => 'bg-amber-100 text-amber-800', 'high' => 'bg-orange-100 text-orange-800', 'critical' => 'bg-rose-100 text-rose-800'];
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Loss incidents</h1>
            <p class="mt-1 text-sm text-slate-500">The loss register. Actual loss, estimated exposure, recoverable opportunity and verified savings are separate figures: they are shown side by side and never added together.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('loss.actions.index') }}" wire:navigate class="btn-secondary">Corrective actions</a>
            @if ($canReport)<a href="{{ route('loss.incidents.create') }}" wire:navigate class="btn-primary">Report a loss</a>@endif
        </div>
    </div>

    <x-status />

    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Actual loss (validated)</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($figures['actual_loss'], 2) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Estimated exposure</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($figures['estimated_exposure'], 2) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Recoverable opportunity</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($figures['recoverable_opportunity'], 2) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Verified savings</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($figures['realised_savings'], 2) }}</dd></div>
    </dl>
    <p class="text-xs text-slate-500">{{ number_format($figures['incidents']) }} incidents in the period (duplicates and rejected ones are not counted) · {{ number_format($figures['open_actions']) }} actions open · <span @class(['font-medium text-rose-700' => $figures['overdue_actions'] > 0])>{{ number_format($figures['overdue_actions']) }} overdue</span></p>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="li-search" class="sr-only">Search</label>
                <input id="li-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Number or title…" class="input max-w-xs">
                <label for="li-from" class="sr-only">From</label>
                <input id="li-from" type="date" wire:model.live="from" class="input w-40">
                <label for="li-to" class="sr-only">To</label>
                <input id="li-to" type="date" wire:model.live="to" class="input w-40">
                <label for="li-ind" class="sr-only">Industry</label>
                <select id="li-ind" wire:model.live="industry" class="input w-48">
                    <option value="">All industries</option>
                    @foreach ($templates as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                </select>
                <label for="li-state" class="sr-only">State</label>
                <select id="li-state" wire:model.live="state" class="input w-44">
                    <option value="">Any state</option>
                    @foreach ($stateOptions as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach
                </select>
                <label for="li-sev" class="sr-only">Severity</label>
                <select id="li-sev" wire:model.live="severity" class="input w-36">
                    <option value="">Any severity</option>
                    @foreach ($severities as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
                <label for="li-branch" class="sr-only">Branch</label>
                <select id="li-branch" wire:model.live="branch" class="input w-44">
                    <option value="">All my branches</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Incident</th><th>Title</th><th>Industry · category</th><th>Branch</th><th>Severity</th><th>State</th><th class="text-right">Actual (₹)</th><th>Owner</th><th>When</th></tr></thead>
                <tbody>
                    @forelse ($incidents as $i)
                        <tr wire:key="li-{{ $i->id }}">
                            <td><a href="{{ route('loss.incidents.show', $i) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $i->incident_number }}</a>@if ($i->isDuplicate()) <span class="badge bg-slate-100 text-slate-600">duplicate</span>@endif</td>
                            <td class="font-medium text-slate-900">{{ $i->title }}</td>
                            <td class="text-xs">{{ $i->template?->name }} · {{ $i->category?->name }}</td>
                            <td>{{ $i->location?->name }}</td>
                            <td><span class="badge {{ $sevClass[$i->severity] ?? '' }}">{{ ucfirst($i->severity) }}</span></td>
                            <td>{{ $stateNames[$i->id] ?? $i->state }}</td>
                            <td class="text-right tabular-nums">{{ $i->isDuplicate() ? '—' : Format::number((string) ($i->actual_amount ?? '0'), 2) }}</td>
                            <td>{{ $i->owner?->name ?? '—' }}</td>
                            <td class="tabular-nums text-slate-600">{{ $i->occurred_at->format('d-m-Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-10 text-center text-slate-500">No loss incidents in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($incidents->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $incidents->links() }}</div>@endif
    </div>
</div>

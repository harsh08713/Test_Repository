@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Budgets</h1>
            <p class="mt-1 text-sm text-slate-500">What a cost centre may lose in a month, overall or for one loss category. Only approved budgets are compared with actual losses.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('finance.variance') }}" wire:navigate class="btn-secondary">Budget against actual</a>
            @if ($canCreate)<a href="{{ route('finance.budgets.create') }}" wire:navigate class="btn-primary">New budget</a>@endif
        </div>
    </div>

    <x-status />

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="b-cc" class="sr-only">Cost centre</label>
                <select id="b-cc" wire:model.live="costCenter" class="input w-52"><option value="">All cost centres</option>@foreach ($costCenters as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                <label for="b-year" class="sr-only">Year</label>
                <input id="b-year" type="text" inputmode="numeric" maxlength="4" wire:model.live.debounce.400ms="year" placeholder="Year, e.g. 2026" class="input w-36">
                <label for="b-appr" class="sr-only">Approval</label>
                <select id="b-appr" wire:model.live="approval" class="input w-44">
                    <option value="">Any state</option><option value="draft">Draft</option><option value="pending">Waiting for approval</option><option value="approved">Approved</option><option value="rejected">Rejected</option>
                </select>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Month</th><th>Cost centre</th><th>Category</th><th class="text-right">Budget (₹)</th><th>Approval</th></tr></thead>
                <tbody>
                    @forelse ($budgets as $b)
                        <tr wire:key="bg-{{ $b->id }}">
                            <td><a href="{{ route('finance.budgets.show', $b) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $b->period_start->format('M Y') }}</a></td>
                            <td>{{ $b->costCenter?->name }}</td>
                            <td>{{ $b->category?->name ?? 'All categories' }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($b->amount, 2) }}</td>
                            <td><span class="badge {{ $b->approval_status->badge() }}">{{ $b->approval_status->label() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-10 text-center text-slate-500">No budgets yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($budgets->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $budgets->links() }}</div>@endif
    </div>
</div>

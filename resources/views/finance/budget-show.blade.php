@php use App\Modules\Shared\Support\Format; @endphp
<div class="mx-auto max-w-3xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-slate-500"><a href="{{ route('finance.budgets.index') }}" wire:navigate class="hover:text-brand-700">Budgets</a></p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $budget->costCenter?->name }} <span class="text-base font-normal text-slate-500">· {{ $budget->period_start->format('F Y') }}</span></h1>
            <p class="mt-2"><span class="badge {{ $budget->approval_status->badge() }}">{{ $budget->approval_status->label() }}</span></p>
        </div>
        @if ($canEdit)<a href="{{ route('finance.budgets.edit', $budget) }}" wire:navigate class="btn-primary">Edit</a>@endif
    </div>

    <x-status />

    <dl class="card card-body grid gap-4 sm:grid-cols-3">
        <div><dt class="text-xs text-slate-500">Budget</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($budget->amount, 2) }}</dd></div>
        <div><dt class="text-xs text-slate-500">Loss category</dt><dd class="text-sm text-slate-900">{{ $budget->category?->name ?? 'All categories' }}</dd></div>
        <div><dt class="text-xs text-slate-500">Cost centre</dt><dd class="text-sm text-slate-900">{{ $budget->costCenter?->code }} · {{ $budget->costCenter?->name }}</dd></div>
        @if ($budget->remarks)<div class="sm:col-span-3"><dt class="text-xs text-slate-500">Remarks</dt><dd class="text-sm text-slate-900">{{ $budget->remarks }}</dd></div>@endif
    </dl>

    <livewire:shared.approval-panel type="budget" :id="$budget->id" :key="'ap-'.$budget->id" />
</div>

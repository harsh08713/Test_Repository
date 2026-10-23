@php
    use App\Modules\Shared\Support\Format;

    $stateClass = ['open' => 'bg-slate-100 text-slate-700', 'in_progress' => 'bg-sky-100 text-sky-800', 'done' => 'bg-violet-100 text-violet-800', 'verified' => 'bg-emerald-100 text-emerald-800', 'rejected' => 'bg-rose-100 text-rose-800'];
@endphp
<div class="space-y-5">
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">Corrective and preventive actions</h1>
        <p class="mt-1 text-sm text-slate-500">What is being done about losses, who owns it and by when. An action's saving counts only once someone other than its owner has verified it.</p>
    </div>

    <nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Which actions">
        @foreach ($tabs as $value => $label)
            <button type="button" wire:click="$set('show', '{{ $value }}')" wire:key="tab-{{ $value }}"
                    @class(['whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium', 'border-brand-600 text-brand-700' => $show === $value, 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $show !== $value])
                    @if ($show === $value) aria-current="page" @endif>{{ $label }}</button>
        @endforeach
    </nav>

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Action</th><th>Kind</th><th>Incident</th><th>Owner</th><th>Due</th><th>State</th><th class="text-right">Expected (₹)</th><th class="text-right">Verified (₹)</th></tr></thead>
                <tbody>
                    @forelse ($actions as $a)
                        <tr wire:key="ca-{{ $a->id }}">
                            <td><span class="font-mono text-xs text-slate-500">{{ $a->action_number }}</span> <span class="font-medium text-slate-900">{{ $a->title }}</span></td>
                            <td>{{ ucfirst($a->action_type) }}</td>
                            <td>@if ($canSeeIncidents)<a href="{{ route('loss.incidents.show', $a->loss_incident_id) }}" wire:navigate class="font-mono text-xs text-brand-700 hover:underline">{{ $a->incident?->incident_number }}</a>@else<span class="font-mono text-xs">{{ $a->incident?->incident_number }}</span>@endif</td>
                            <td>{{ $a->owner?->name }}</td>
                            <td @class(['tabular-nums', 'font-medium text-rose-700' => $a->isOverdue()])>{{ $a->due_date->format('d-m-Y') }}{{ $a->isOverdue() ? ' · overdue' : '' }}</td>
                            <td><span class="badge {{ $stateClass[$a->state] ?? '' }}">{{ $a->stateLabel() }}</span></td>
                            <td class="text-right tabular-nums">{{ $a->expected_benefit_amount !== null ? Format::number($a->expected_benefit_amount, 2) : '—' }}</td>
                            <td class="text-right tabular-nums">{{ $a->verified_result_amount !== null ? Format::number($a->verified_result_amount, 2) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-10 text-center text-slate-500">Nothing here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($actions->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $actions->links() }}</div>@endif
    </div>
</div>

<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Follow-ups</h1>
            <p class="mt-1 text-sm text-slate-500">What is overdue, what is due today and what is coming — yours, and for a manager, your teams’. Open the lead to record what came of a follow-up, move it or call it off.</p>
        </div>
        <a href="{{ route('crm.followups.calendar') }}" wire:navigate class="btn-secondary"><x-icon name="calendar" class="size-4" /> Calendar</a>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif

    <nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Which follow-ups">
        @foreach ($tabs as [$value, $label, $count])
            <button type="button" wire:click="setShow('{{ $value }}')" wire:key="tab-{{ $value === '' ? 'all' : $value }}"
                    @class([
                        'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
                        'border-brand-600 text-brand-700' => $show === $value,
                        'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $show !== $value,
                    ])
                    @if ($show === $value) aria-current="page" @endif>{{ $label }}@if ($count !== null) <span @class(['ml-1 tabular-nums text-xs', 'font-semibold text-rose-600' => $value === 'overdue' && $count > 0, 'text-slate-400' => ! ($value === 'overdue' && $count > 0)])>{{ number_format($count) }}</span>@endif</button>
        @endforeach
    </nav>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="f-search" class="sr-only">Search</label>
                <input id="f-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Lead name, number or mobile…" class="input max-w-xs">

                <label for="f-person" class="sr-only">Person</label>
                <select id="f-person" wire:model.live="person" class="input w-52">
                    <option value="">Everyone I can see</option>
                    @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                @if ($teams !== [])
                    <label for="f-team" class="sr-only">Team</label>
                    <select id="f-team" wire:model.live="team" class="input w-48">
                        <option value="">All teams</option>
                        @foreach ($teams as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                @endif

                <label for="f-type" class="sr-only">Type</label>
                <select id="f-type" wire:model.live="type" class="input w-40">
                    <option value="">Any type</option>
                    @foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>When</th><th>Type</th><th>Lead</th><th>For</th><th>State</th><th>Notes</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($rows as $f)
                        @php $lead = $f->subject instanceof \App\Modules\CRM\Models\Lead ? $f->subject : null; @endphp
                        <tr wire:key="fu-{{ $f->id }}">
                            <td class="whitespace-nowrap">
                                <span @class(['tabular-nums', 'font-medium text-rose-700' => $f->isOverdue(), 'text-slate-900' => ! $f->isOverdue()])>{{ $f->scheduled_at->format('D, j M Y, H:i') }}</span>
                            </td>
                            <td>{{ $f->type->label() }}</td>
                            <td>
                                @if ($lead === null)
                                    <span class="text-slate-400">Record no longer available</span>
                                @elseif (isset($openable[$lead->id]))
                                    <a href="{{ route('crm.leads.show', $lead) }}#follow-ups" wire:navigate class="font-medium text-slate-900 hover:text-brand-700">{{ $lead->name }}</a>
                                @else
                                    <span class="font-medium text-slate-900">{{ $lead->name }}</span>
                                @endif
                                @if ($lead)<div class="text-xs text-slate-500"><span class="font-mono">{{ $lead->lead_number }}</span>@if ($lead->mobile) · <span class="tabular-nums">{{ $lead->mobile }}</span>@endif</div>@endif
                            </td>
                            <td>{{ $f->assignee?->name }}</td>
                            <td class="whitespace-nowrap">
                                @if ($f->isOverdue())<span class="badge bg-rose-100 text-rose-800">Overdue</span>@else<span class="badge {{ $f->status->badge() }}">{{ $f->status->label() }}</span>@endif
                                @if ($f->outcome)<span class="badge ml-1 bg-indigo-100 text-indigo-800">{{ $f->outcome->name }}</span>@endif
                            </td>
                            <td class="max-w-xs truncate text-slate-600" title="{{ $f->notes }}">{{ \Illuminate\Support\Str::limit((string) $f->notes, 80) ?: '—' }}</td>
                            <td class="whitespace-nowrap text-right">
                                @if ($lead && isset($openable[$lead->id]))
                                    <a href="{{ route('crm.leads.show', $lead) }}#follow-ups" wire:navigate class="btn-ghost btn-sm">Open lead</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-slate-500">{{ $show === 'overdue' ? 'Nothing is overdue.' : 'No follow-ups match.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $rows->links() }}</div>
        @endif
    </div>
</div>

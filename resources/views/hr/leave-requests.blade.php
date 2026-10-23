<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Leave requests</h1>
            <p class="mt-1 text-sm text-slate-500">Requests from the people you manage (everyone, for HR). You cannot decide your own. Approving takes the days from the person’s balance.</p>
        </div>
        <div class="flex gap-2">
            @can('leave.manage_balances')<a href="{{ route('hr.leave-balances') }}" wire:navigate class="btn-secondary">Balances</a>@endcan
            <a href="{{ route('hr.my-leave') }}" wire:navigate class="btn-secondary">My leave</a>
        </div>
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('balance')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('state')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="lr-state" class="sr-only">Status</label>
                <select id="lr-state" wire:model.live="state" class="input w-44"><option value="pending">Waiting</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="cancelled">Cancelled</option><option value="">Any</option></select>
                <label for="lr-q" class="sr-only">Person</label>
                <input id="lr-q" type="search" wire:model.live.debounce.400ms="search" placeholder="Person’s name" class="input w-56" maxlength="80">
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Request</th><th>Person</th><th>Kind</th><th>Dates</th><th class="text-right">Days</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($requests as $r)
                        <tr wire:key="lr-{{ $r->id }}">
                            <td class="font-mono text-xs">{{ $r->number() }}<div class="max-w-[14rem] truncate font-sans text-slate-500" title="{{ $r->reason }}">{{ $r->reason }}</div></td>
                            <td><a href="{{ route('hr.employees.show', $r->employee_id) }}" wire:navigate class="text-brand-700 hover:underline">{{ $r->employee?->name }}</a></td>
                            <td>{{ $r->type?->name }}</td>
                            <td>{{ $r->from_date->format('d M Y') }}{{ $r->from_date->equalTo($r->to_date) ? '' : ' – '.$r->to_date->format('d M Y') }}{{ $r->half_day ? ' ('.$r->half_day.' half)' : '' }}</td>
                            <td class="text-right tabular-nums">{{ rtrim(rtrim($r->days, '0'), '.') }}</td>
                            <td>
                                <span @class(['badge', 'bg-amber-100 text-amber-800' => $r->state === 'pending', 'bg-emerald-100 text-emerald-800' => $r->state === 'approved', 'bg-rose-100 text-rose-800' => $r->state === 'rejected', 'bg-slate-100 text-slate-600' => $r->state === 'cancelled'])>{{ $states[$r->state] ?? $r->state }}</span>
                                @if ($r->decider)<div class="text-xs text-slate-500">by {{ $r->decider->name }}{{ $r->decision_note ? ': '.$r->decision_note : '' }}</div>@endif
                            </td>
                            <td class="whitespace-nowrap text-right">
                                @if ($can[$r->id] ?? false)
                                    @if ($r->state === 'pending')
                                        <button type="button" wire:click="approveRequest({{ $r->id }})" class="btn-primary btn-sm">Approve</button>
                                        <button type="button" wire:click="startNote({{ $r->id }}, 'reject')" class="btn-secondary btn-sm">Reject</button>
                                    @elseif ($r->state === 'approved')
                                        <button type="button" wire:click="startNote({{ $r->id }}, 'cancel')" class="text-xs text-rose-600 hover:underline">Cancel</button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                        @if ($noting === $r->id)
                            <tr wire:key="note-{{ $r->id }}"><td colspan="7" class="bg-slate-50">
                                <form wire:submit="confirmNote" class="flex flex-wrap items-end gap-3" novalidate>
                                    <div class="min-w-64 flex-1"><label for="lr-note" class="label">{{ $noteKind === 'reject' ? 'Why is it turned down?' : 'Why is it cancelled?' }}</label><input id="lr-note" type="text" wire:model="note" class="input" maxlength="500">@error('note')<p class="error">{{ $message }}</p>@enderror @error('cancel_reason')<p class="error">{{ $message }}</p>@enderror</div>
                                    <button type="submit" class="btn-danger">{{ $noteKind === 'reject' ? 'Reject' : 'Cancel leave' }}</button>
                                </form>
                            </td></tr>
                        @endif
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-slate-500">Nothing here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($requests->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $requests->links() }}</div>@endif
    </div>
</div>

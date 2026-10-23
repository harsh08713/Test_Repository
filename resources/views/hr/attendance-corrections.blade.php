<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Attendance corrections</h1>
            <p class="mt-1 text-sm text-slate-500">People asking to put a day’s punches right. Approving adds a punch in and a punch out, marked as regularised, and closes the day again. You cannot decide your own.</p>
        </div>
        <a href="{{ route('hr.team-attendance') }}" wire:navigate class="btn-secondary">Team attendance</a>
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('state')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <div class="card">
        <div class="card-header">
            <label for="ac-state" class="sr-only">Status</label>
            <select id="ac-state" wire:model.live="state" class="input w-44"><option value="pending">Waiting</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="cancelled">Cancelled</option><option value="">Any</option></select>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Person</th><th>Day</th><th>Times</th><th>Reason</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="co-{{ $r->id }}">
                            <td><a href="{{ route('hr.employees.show', $r->employee_id) }}" wire:navigate class="text-brand-700 hover:underline">{{ $r->employee?->name }}</a></td>
                            <td>{{ $r->work_date->format('d M Y') }}</td>
                            <td class="tabular-nums">{{ substr($r->in_time, 0, 5) }} – {{ substr($r->out_time, 0, 5) }}</td>
                            <td class="max-w-xs truncate" title="{{ $r->reason }}">{{ $r->reason }}</td>
                            <td>
                                <span @class(['badge', 'bg-amber-100 text-amber-800' => $r->state === 'pending', 'bg-emerald-100 text-emerald-800' => $r->state === 'approved', 'bg-rose-100 text-rose-800' => $r->state === 'rejected', 'bg-slate-100 text-slate-600' => $r->state === 'cancelled'])>{{ ['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'][$r->state] }}</span>
                                @if ($r->decider && $r->state !== 'cancelled')<div class="text-xs text-slate-500">by {{ $r->decider->name }}{{ $r->decision_note ? ': '.$r->decision_note : '' }}</div>@endif
                            </td>
                            <td class="whitespace-nowrap text-right">
                                @if ($r->state === 'pending' && ($can[$r->id] ?? false))
                                    <button type="button" wire:click="approveCorrection({{ $r->id }})" class="btn-primary btn-sm">Approve</button>
                                    <button type="button" wire:click="startReject({{ $r->id }})" class="btn-secondary btn-sm">Reject</button>
                                @endif
                            </td>
                        </tr>
                        @if ($rejecting === $r->id)
                            <tr wire:key="corr-note-{{ $r->id }}"><td colspan="6" class="bg-slate-50">
                                <form wire:submit="confirmReject" class="flex flex-wrap items-end gap-3" novalidate>
                                    <div class="min-w-64 flex-1"><label for="ac-note" class="label">Why is it turned down?</label><input id="ac-note" type="text" wire:model="note" class="input" maxlength="500">@error('note')<p class="error">{{ $message }}</p>@enderror</div>
                                    <button type="submit" class="btn-danger">Reject</button>
                                </form>
                            </td></tr>
                        @endif
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-slate-500">Nothing here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($rows->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $rows->links() }}</div>@endif
    </div>
</div>

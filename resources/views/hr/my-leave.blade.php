<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">My leave</h1>
            <p class="mt-1 text-sm text-slate-500">Your balances for the year {{ $year }}–{{ substr((string) ($year + 1), 2) }}, and your requests. A request goes to your manager.</p>
        </div>
        @if ($employee)<button type="button" wire:click="openForm" class="btn-primary">{{ $applying ? 'Close the form' : 'Apply for leave' }}</button>@endif
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('employee')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if (! $employee)
        <p class="card card-body text-sm text-slate-600">Your login is not linked to an employee file yet, so there is no leave to show. Ask HR to link it.</p>
    @else
        @if ($applying)
            <form wire:submit="submitRequest" class="card" novalidate>
                <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Apply for leave</h2></div>
                <div class="card-body grid gap-4 sm:grid-cols-4">
                    <div class="sm:col-span-2">
                        <label for="lv-type" class="label">Kind of leave</label>
                        <select id="lv-type" wire:model.live="form.leave_type_id" class="input"><option value="">Select…</option>@foreach ($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select>
                        @error('leave_type_id')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div><label for="lv-from" class="label">From</label><input id="lv-from" type="date" wire:model.live="form.from_date" class="input">@error('from_date')<p class="error">{{ $message }}</p>@enderror</div>
                    <div><label for="lv-to" class="label">To</label><input id="lv-to" type="date" wire:model.live="form.to_date" class="input">@error('to_date')<p class="error">{{ $message }}</p>@enderror</div>
                    <div>
                        <label for="lv-half" class="label">Half day</label>
                        <select id="lv-half" wire:model.live="form.half_day" class="input"><option value="">No, full days</option><option value="first">First half</option><option value="second">Second half</option></select>
                        @error('half_day')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-3"><label for="lv-why" class="label">Reason</label><input id="lv-why" type="text" wire:model="form.reason" class="input" maxlength="500">@error('reason')<p class="error">{{ $message }}</p>@enderror</div>
                    <div class="flex items-end"><p class="text-sm text-slate-600" data-testid="leave-preview">@if ($preview)<span class="font-semibold text-slate-900">{{ rtrim(rtrim(number_format($preview['days'], 1), '0'), '.') ?: '0' }}</span> working day(s)@if ($preview['skipped'] > 0) <span class="text-xs text-slate-400">({{ $preview['skipped'] }} holiday / off day not counted)</span>@endif @endif</p></div>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><button type="button" wire:click="openForm" class="btn-secondary">Cancel</button><button type="submit" class="btn-primary" wire:loading.attr="disabled">Send request</button></div>
            </form>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="leave-balances">
            @forelse ($summary as $row)
                <div class="card card-body" wire:key="bal-{{ $row['type']->id }}">
                    <p class="text-sm font-medium text-slate-500">{{ $row['type']->name }} <span class="text-xs text-slate-400">{{ $row['type']->is_paid ? '' : '· unpaid' }}</span></p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900">{{ rtrim(rtrim($row['balance'], '0'), '.') ?: '0' }} <span class="text-sm font-normal text-slate-500">left</span></p>
                    <p class="mt-1 text-xs text-slate-500">Granted {{ rtrim(rtrim($row['granted'], '0'), '.') ?: '0' }} · taken {{ rtrim(rtrim(ltrim($row['taken'], '-'), '0'), '.') ?: '0' }}@if (bccomp($row['pending'], '0', 1) > 0) · {{ rtrim(rtrim($row['pending'], '0'), '.') }} waiting @endif</p>
                </div>
            @empty
                <p class="card card-body text-sm text-slate-600 sm:col-span-3">No kind of leave has been set up yet.</p>
            @endforelse
        </div>

        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">My requests</h2></div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Request</th><th>Kind</th><th>Dates</th><th class="text-right">Days</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($requests as $r)
                            <tr wire:key="req-{{ $r->id }}">
                                <td class="font-mono text-xs">{{ $r->number() }}<div class="max-w-[14rem] truncate font-sans text-slate-500">{{ $r->reason }}</div></td>
                                <td>{{ $r->type?->name }}</td>
                                <td>{{ $r->from_date->format('d M Y') }}{{ $r->from_date->equalTo($r->to_date) ? '' : ' – '.$r->to_date->format('d M Y') }}{{ $r->half_day ? ' ('.$r->half_day.' half)' : '' }}</td>
                                <td class="text-right tabular-nums">{{ rtrim(rtrim($r->days, '0'), '.') }}</td>
                                <td>
                                    <span @class(['badge', 'bg-amber-100 text-amber-800' => $r->state === 'pending', 'bg-emerald-100 text-emerald-800' => $r->state === 'approved', 'bg-rose-100 text-rose-800' => $r->state === 'rejected', 'bg-slate-100 text-slate-600' => $r->state === 'cancelled'])>{{ $states[$r->state] ?? $r->state }}</span>
                                    @if ($r->decision_note)<div class="text-xs text-slate-500">{{ $r->decision_note }}</div>@endif
                                </td>
                                <td class="text-right">
                                    @if ($r->state === 'pending' || ($r->state === 'approved' && $r->from_date->isFuture()))
                                        <button type="button" wire:click="startCancel({{ $r->id }})" class="text-xs text-rose-600 hover:underline">Cancel</button>
                                    @endif
                                </td>
                            </tr>
                            @if ($cancelling === $r->id)
                                <tr wire:key="cancel-{{ $r->id }}"><td colspan="6" class="bg-slate-50">
                                    <form wire:submit="confirmCancel" class="flex flex-wrap items-end gap-3" novalidate>
                                        <div class="min-w-64 flex-1"><label for="lv-cwhy" class="label">Why are you cancelling?</label><input id="lv-cwhy" type="text" wire:model="cancelReason" class="input" maxlength="500">@error('cancel_reason')<p class="error">{{ $message }}</p>@enderror @error('state')<p class="error">{{ $message }}</p>@enderror</div>
                                        <button type="submit" class="btn-danger">Cancel the request</button>
                                    </form>
                                </td></tr>
                            @endif
                        @empty
                            <tr><td colspan="6" class="py-10 text-center text-slate-500">You have not asked for leave yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

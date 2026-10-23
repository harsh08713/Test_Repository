<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Leave balances</h1>
            <p class="mt-1 text-sm text-slate-500">Days left for the year {{ $year }}–{{ substr((string) ($year + 1), 2) }}, from the leave ledger. Days are granted by the daily accrual and taken when leave is approved.</p>
        </div>
        @if ($canAdjust)<button type="button" wire:click="openAdjust" class="btn-primary">{{ $adjusting ? 'Close' : 'Give or take away days' }}</button>@endif
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('employee')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if ($adjusting)
        <form wire:submit="saveAdjustment" class="card" novalidate>
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Change a balance</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div><label for="ba-emp" class="label">Person</label><select id="ba-emp" wire:model="form.employee_id" class="input"><option value="">Select…</option>@foreach ($all as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>@error('employee_id')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="ba-type" class="label">Kind of leave</label><select id="ba-type" wire:model="form.leave_type_id" class="input"><option value="">Select…</option>@foreach ($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select>@error('leave_type_id')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="ba-days" class="label">Days (minus to take away)</label><input id="ba-days" type="text" inputmode="decimal" wire:model="form.days" class="input tabular-nums">@error('days')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="sm:col-span-2"><label for="ba-why" class="label">Why</label><input id="ba-why" type="text" wire:model="form.reason" class="input" maxlength="300">@error('reason')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="ba-on" class="label">As of</label><input id="ba-on" type="date" wire:model="form.on" class="input">@error('on')<p class="error">{{ $message }}</p>@enderror</div>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><button type="button" wire:click="openAdjust" class="btn-secondary">Cancel</button><button type="submit" class="btn-primary">Save</button></div>
        </form>
    @endif

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Person</th>@foreach ($types as $t)<th class="text-right">{{ $t->name }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($people as $p)
                        <tr wire:key="bp-{{ $p->id }}">
                            <td><a href="{{ route('hr.employees.show', $p->id) }}" wire:navigate class="text-brand-700 hover:underline">{{ $p->name }}</a> <span class="text-xs text-slate-400">{{ $p->code }}</span></td>
                            @foreach ($types as $t)
                                @php $v = $sums[$p->id.'-'.$t->id] ?? '0.0'; @endphp
                                <td class="text-right tabular-nums {{ bccomp($v, '0', 1) < 0 ? 'text-rose-600' : '' }}">{{ rtrim(rtrim($v, '0'), '.') ?: '0' }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ $types->count() + 1 }}" class="py-10 text-center text-slate-500">Nobody to show.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

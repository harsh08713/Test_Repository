@php
    $badge = ['present' => 'bg-emerald-100 text-emerald-800', 'half_day' => 'bg-amber-100 text-amber-800', 'absent' => 'bg-rose-100 text-rose-800', 'leave' => 'bg-sky-100 text-sky-800', 'holiday' => 'bg-violet-100 text-violet-800', 'weekly_off' => 'bg-slate-100 text-slate-600'];
    $hm = fn (int $m) => intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m';
@endphp
<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">My attendance</h1>
            <p class="mt-1 text-sm text-slate-500">Punch in when you start and out when you finish. The day is closed overnight: full time is present, half time a half day.</p>
        </div>
        @if ($employee)<button type="button" wire:click="openCorrection" class="btn-secondary">{{ $correcting ? 'Close' : 'Missed a punch?' }}</button>@endif
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('direction')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if (! $employee)
        <p class="card card-body text-sm text-slate-600">Your login is not linked to an employee file yet, so there is no attendance to show. Ask HR to link it.</p>
    @else
        <div class="card card-body flex flex-wrap items-center justify-between gap-4" data-testid="punch-card">
            <div>
                <p class="text-sm text-slate-500">Today, {{ now()->format('l d M Y') }} · shift {{ $shift['start'] }} – {{ $shift['end'] }} (grace {{ $shift['grace'] }} min)</p>
                <p class="mt-1 text-lg font-semibold text-slate-900">
                    @if ($today['state'] === 'none')Not punched in yet
                    @elseif ($today['state'] === 'in')Working since {{ $today['first_in']->format('H:i') }} · {{ $hm($today['worked_minutes']) }} so far
                    @else Punched out at {{ $today['last_punch']->format('H:i') }} · {{ $hm($today['worked_minutes']) }}@endif
                </p>
            </div>
            <div class="flex gap-2">
                <button type="button" wire:click="punchIn" wire:loading.attr="disabled" @disabled($today['state'] === 'in') class="btn-primary">Punch in</button>
                <button type="button" wire:click="punchOut" wire:loading.attr="disabled" @disabled($today['state'] !== 'in') class="btn-secondary">Punch out</button>
            </div>
        </div>

        @if ($correcting)
            <form wire:submit="submitCorrection" class="card" novalidate>
                <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Ask for a correction</h2></div>
                <div class="card-body grid gap-4 sm:grid-cols-4">
                    <div><label for="ac-date" class="label">Day</label><input id="ac-date" type="date" wire:model="form.work_date" class="input">@error('work_date')<p class="error">{{ $message }}</p>@enderror</div>
                    <div><label for="ac-in" class="label">In</label><input id="ac-in" type="time" wire:model="form.in_time" class="input">@error('in_time')<p class="error">{{ $message }}</p>@enderror</div>
                    <div><label for="ac-out" class="label">Out</label><input id="ac-out" type="time" wire:model="form.out_time" class="input">@error('out_time')<p class="error">{{ $message }}</p>@enderror</div>
                    <div class="sm:col-span-4"><label for="ac-why" class="label">What happened</label><input id="ac-why" type="text" wire:model="form.reason" class="input" maxlength="500">@error('reason')<p class="error">{{ $message }}</p>@enderror</div>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><button type="submit" class="btn-primary">Send to my manager</button></div>
            </form>
        @endif

        <div class="card">
            <div class="card-header flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-900">{{ $first->format('F Y') }}</h2>
                <div class="flex gap-2">
                    <button type="button" wire:click="$set('month', '{{ $prev }}')" class="btn-secondary btn-sm" aria-label="Previous month">‹</button>
                    @unless ($isCurrent)<button type="button" wire:click="$set('month', '{{ now()->format('Y-m') }}')" class="btn-secondary btn-sm">This month</button>@endunless
                    <button type="button" wire:click="$set('month', '{{ $next }}')" class="btn-secondary btn-sm" aria-label="Next month">›</button>
                </div>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Day</th><th>Status</th><th>In</th><th>Out</th><th class="text-right">Worked</th><th class="text-right">Late</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($days as $d)
                            @php $row = $d['row']; @endphp
                            <tr wire:key="day-{{ $d['date']->toDateString() }}" @class(['bg-slate-50/60' => $d['off'] || $d['holiday']])>
                                <td>{{ $d['date']->format('d M') }} <span class="text-xs text-slate-400">{{ $d['date']->format('D') }}</span></td>
                                <td>
                                    @if ($row)<span class="badge {{ $badge[$row->status] ?? '' }}">{{ $statuses[$row->status] ?? $row->status }}</span>@if ($row->missing_out) <span class="badge bg-rose-50 text-rose-700">no punch out</span>@endif
                                    @elseif ($d['holiday'])<span class="badge {{ $badge['holiday'] }}">{{ $d['holiday'] }}</span>
                                    @elseif ($d['off'])<span class="badge {{ $badge['weekly_off'] }}">Weekly off</span>
                                    @elseif ($d['future'])<span class="text-xs text-slate-300">—</span>
                                    @else<span class="text-xs text-slate-400">not closed yet</span>@endif
                                </td>
                                <td>{{ $row?->first_in?->format('H:i') ?? '—' }}</td>
                                <td>{{ $row?->last_out?->format('H:i') ?? '—' }}</td>
                                <td class="text-right tabular-nums">{{ $row && $row->worked_minutes > 0 ? $hm($row->worked_minutes) : '—' }}</td>
                                <td class="text-right tabular-nums">{{ $row && $row->late_minutes > 0 ? $row->late_minutes.' min' : '' }}</td>
                                <td class="text-right">@if ($row && ! $d['future'] && ($row->missing_out || $row->status === 'absent'))<button type="button" wire:click="openCorrection('{{ $d['date']->toDateString() }}')" class="text-xs text-brand-700 hover:underline">Correct</button>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if ($corrections->isNotEmpty())
            <div class="card">
                <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">My correction requests</h2></div>
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($corrections as $c)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2.5" wire:key="corr-{{ $c->id }}">
                            <span>{{ $c->work_date->format('d M Y') }} · {{ substr($c->in_time, 0, 5) }} – {{ substr($c->out_time, 0, 5) }} <span class="text-slate-500">· {{ $c->reason }}</span></span>
                            <span class="flex items-center gap-3">
                                <span @class(['badge', 'bg-amber-100 text-amber-800' => $c->state === 'pending', 'bg-emerald-100 text-emerald-800' => $c->state === 'approved', 'bg-rose-100 text-rose-800' => $c->state === 'rejected', 'bg-slate-100 text-slate-600' => $c->state === 'cancelled'])>{{ ['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'][$c->state] }}</span>
                                @if ($c->state === 'pending')<button type="button" wire:click="cancelCorrection({{ $c->id }})" class="text-xs text-rose-600 hover:underline">Cancel</button>@endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
</div>

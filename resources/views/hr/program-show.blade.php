<div class="mx-auto max-w-4xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-slate-500"><a href="{{ route('hr.programs') }}" wire:navigate class="hover:text-brand-700">Training programs</a></p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $p->title }} <span class="font-mono text-base font-normal text-slate-500">{{ $p->code }}</span></h1>
            <p class="mt-1 text-sm text-slate-500">{{ $p->starts_on->format('d M Y') }}{{ $p->starts_on->equalTo($p->ends_on) ? '' : ' – '.$p->ends_on->format('d M Y') }} · {{ $modes[$p->mode] ?? $p->mode }}{{ $p->venue ? ' · '.$p->venue : '' }}{{ $p->trainer ? ' · trainer '.$p->trainer : '' }} · {{ $taken }} of {{ $p->capacity }} seats taken</p>
            <p class="mt-2"><span @class(['badge', 'bg-amber-100 text-amber-800' => $p->state === 'planned', 'bg-emerald-100 text-emerald-800' => $p->state === 'completed', 'bg-slate-100 text-slate-600' => $p->state === 'cancelled'])>{{ $programStates[$p->state] }}</span></p>
        </div>
        @if ($canHr && $p->state === 'planned')
            <div class="flex gap-2">
                @if ($started)<button type="button" wire:click="completeIt" wire:confirm="Complete this program?" class="btn-primary">Complete</button>@endif
                <button type="button" wire:click="cancelIt" wire:confirm="Cancel this program? Everyone enrolled is dropped and told." class="btn-danger">Cancel it</button>
            </div>
        @endif
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('state')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('score')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if ($canEnrol)
        <form wire:submit="enrolPerson" class="card card-body flex flex-wrap items-end gap-3" novalidate>
            <div class="min-w-64 flex-1"><label for="en-emp" class="label">Enrol</label><select id="en-emp" wire:model="employeeId" class="input"><option value="">Select a person…</option>@foreach ($people as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('employee_id')<p class="error">{{ $message }}</p>@enderror</div>
            <button type="submit" class="btn-primary">Enrol</button>
        </form>
    @endif

    <div class="card">
        <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Enrolled ({{ $rows->count() }})</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Person</th><th>Outcome</th><th class="text-right">Score</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="en-{{ $r->id }}">
                            <td><a href="{{ route('hr.employees.show', $r->employee_id) }}" wire:navigate class="text-brand-700 hover:underline">{{ $r->employee?->name }}</a></td>
                            <td>
                                @if ($p->state === 'planned' && $started && ($manages[$r->id] ?? false))
                                    <label for="oc-{{ $r->id }}" class="sr-only">Outcome</label>
                                    <select id="oc-{{ $r->id }}" wire:model="outcome.{{ $r->id }}" class="input w-36 py-1 text-xs"><option value="">Choose…</option><option value="completed">Completed</option><option value="absent">Absent</option><option value="dropped">Dropped</option></select>
                                @else
                                    <span @class(['badge', 'bg-sky-100 text-sky-800' => $r->state === 'enrolled', 'bg-emerald-100 text-emerald-800' => $r->state === 'completed', 'bg-rose-100 text-rose-800' => $r->state === 'absent', 'bg-slate-100 text-slate-600' => $r->state === 'dropped'])>{{ $states[$r->state] }}</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @if ($p->state === 'planned' && $started && ($manages[$r->id] ?? false))
                                    <input type="text" inputmode="decimal" wire:model="score.{{ $r->id }}" class="input w-20 py-1 text-right text-xs tabular-nums" aria-label="Score" placeholder="0–100">
                                @else
                                    <span class="tabular-nums">{{ $r->score === null ? '—' : rtrim(rtrim($r->score, '0'), '.') }}</span>
                                @endif
                            </td>
                            <td class="text-right">@if ($p->state === 'planned' && $started && ($manages[$r->id] ?? false))<button type="button" wire:click="recordOutcome({{ $r->id }})" class="btn-secondary btn-sm">Record</button>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-10 text-center text-slate-500">Nobody is enrolled yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

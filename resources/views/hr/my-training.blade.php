<div class="mx-auto max-w-4xl space-y-5">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">My training</h1>
        <p class="mt-1 text-sm text-slate-500">The programs you are enrolled in or have taken.</p>
    </div>

    @if (! $employee)
        <p class="card card-body text-sm text-slate-600">Your login is not linked to an employee file yet. Ask HR to link it.</p>
    @else
        <div class="card">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Program</th><th>Dates</th><th>Outcome</th><th class="text-right">Score</th></tr></thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr wire:key="mt-{{ $r->id }}">
                                <td class="font-medium text-slate-900">{{ $r->program?->title }}@if ($r->program?->state === 'cancelled') <span class="text-xs font-normal text-slate-400">(cancelled)</span>@endif</td>
                                <td>{{ $r->program?->starts_on->format('d M Y') }}{{ $r->program && ! $r->program->starts_on->equalTo($r->program->ends_on) ? ' – '.$r->program->ends_on->format('d M Y') : '' }}{{ $r->program?->venue ? ' · '.$r->program->venue : '' }}</td>
                                <td><span @class(['badge', 'bg-sky-100 text-sky-800' => $r->state === 'enrolled', 'bg-emerald-100 text-emerald-800' => $r->state === 'completed', 'bg-rose-100 text-rose-800' => $r->state === 'absent', 'bg-slate-100 text-slate-600' => $r->state === 'dropped'])>{{ $states[$r->state] }}</span></td>
                                <td class="text-right tabular-nums">{{ $r->score === null ? '—' : rtrim(rtrim($r->score, '0'), '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-10 text-center text-slate-500">You are not enrolled in any training.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

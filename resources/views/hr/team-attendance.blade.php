@php
    $letter = ['present' => ['P', 'text-emerald-700'], 'half_day' => ['½', 'text-amber-700'], 'absent' => ['A', 'text-rose-700 font-semibold'], 'leave' => ['L', 'text-sky-700'], 'holiday' => ['H', 'text-violet-700'], 'weekly_off' => ['·', 'text-slate-400']];
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Team attendance</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $first->format('F Y') }}. P present · ½ half day · A absent · L leave · H holiday · · weekly off. Days are closed overnight, so today and later are blank.</p>
        </div>
        <div class="flex gap-2">
            <button type="button" wire:click="$set('month', '{{ $prev }}')" class="btn-secondary btn-sm" aria-label="Previous month">‹</button>
            <button type="button" wire:click="$set('month', '{{ $current }}')" class="btn-secondary btn-sm">This month</button>
            <button type="button" wire:click="$set('month', '{{ $next }}')" class="btn-secondary btn-sm" aria-label="Next month">›</button>
            <a href="{{ route('hr.corrections') }}" wire:navigate class="btn-secondary btn-sm">Corrections</a>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="data-table text-xs">
                <thead>
                    <tr>
                        <th>Person</th><th class="text-right">P</th><th class="text-right">½</th><th class="text-right">A</th><th class="text-right">L</th><th class="text-right">Late</th>
                        @for ($d = 1; $d <= $daysInMonth; $d++)<th class="px-1 text-center {{ isset($holidays[$first->day($d)->toDateString()]) ? 'text-violet-600' : '' }}">{{ $d }}</th>@endfor
                    </tr>
                </thead>
                <tbody>
                    @forelse ($table as $r)
                        <tr wire:key="ta-{{ $r['person']->id }}">
                            <td class="whitespace-nowrap"><a href="{{ route('hr.employees.show', $r['person']->id) }}" wire:navigate class="text-brand-700 hover:underline">{{ $r['person']->name }}</a></td>
                            <td class="text-right tabular-nums">{{ $r['present'] }}</td><td class="text-right tabular-nums">{{ $r['half'] }}</td>
                            <td class="text-right tabular-nums {{ $r['absent'] > 0 ? 'text-rose-700 font-semibold' : '' }}">{{ $r['absent'] }}</td>
                            <td class="text-right tabular-nums">{{ $r['leave'] }}</td><td class="text-right tabular-nums">{{ $r['late'] }}</td>
                            @for ($d = 1; $d <= $daysInMonth; $d++)
                                @php $cell = $r['days']->get($d); @endphp
                                <td class="px-1 text-center {{ $cell ? $letter[$cell->status][1] : '' }}" title="{{ $cell ? ucfirst(str_replace('_', ' ', $cell->status)).($cell->missing_out ? ' · no punch out' : '') : '' }}">{{ $cell ? $letter[$cell->status][0] : '' }}</td>
                            @endfor
                        </tr>
                    @empty
                        <tr><td colspan="{{ $daysInMonth + 6 }}" class="py-10 text-center text-slate-500">Nobody to show.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

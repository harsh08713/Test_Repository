@php
    $positive = $change !== null && bccomp($change, '0', 2) >= 0;
    $isGood = $change === null ? null : (($good ?? 'up') === 'up' ? $positive : ! $positive);
@endphp
@if ($change === null)
    <span class="text-xs text-slate-400">no earlier figure</span>
@else
    <span @class(['inline-flex items-center gap-1 text-xs font-medium', 'text-emerald-600' => $isGood, 'text-rose-600' => ! $isGood])>
        <x-icon :name="$positive ? 'arrow-trending-up' : 'arrow-trending-down'" class="size-4" />{{ \App\Modules\Analytics\Support\Fmt::change($change) }}
        <span class="font-normal text-slate-400">vs previous period</span>
    </span>
@endif

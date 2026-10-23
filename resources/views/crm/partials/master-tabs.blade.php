@php
    $tabs = [
        ['Lead sources', 'crm.masters.sources'],
        ['Follow-up outcomes', 'crm.masters.outcomes'],
        ['Lost reasons', 'crm.masters.lost-reasons'],
    ];
@endphp
<nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Lead masters">
    @foreach ($tabs as [$label, $route])
        <a href="{{ route($route) }}" wire:navigate
           @class([
               'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
               'border-brand-600 text-brand-700' => request()->routeIs($route),
               'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => ! request()->routeIs($route),
           ])
           @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

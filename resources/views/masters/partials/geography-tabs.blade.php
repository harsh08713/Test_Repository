@php
    $tabs = [
        ['States', 'masters.geography.states', 'geography.view'],
        ['Districts', 'masters.geography.districts', 'geography.view'],
        ['Blocks', 'masters.geography.blocks', 'geography.view'],
        ['PIN codes', 'masters.geography.pin-codes', 'geography.view'],
        ['Import', 'masters.geography.import', 'geography.import'],
    ];
@endphp
<nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Geography">
    @foreach ($tabs as [$label, $route, $permission])
        @if (\Illuminate\Support\Facades\Route::has($route) && auth()->user()->can($permission))
            <a href="{{ route($route) }}" wire:navigate
               @class([
                   'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
                   'border-brand-600 text-brand-700' => request()->routeIs($route),
                   'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => ! request()->routeIs($route),
               ])
               @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
        @endif
    @endforeach
</nav>

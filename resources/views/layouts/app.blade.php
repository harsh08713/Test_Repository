<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans text-slate-800 antialiased" x-data="{ nav: false }" x-on:keydown.escape.window="nav = false">
    @php
        $me = auth()->user();
        $menu = app(\App\Modules\Shared\Services\NavigationMenu::class)->for($me, request());
        $initials = \Illuminate\Support\Str::of($me->name)->explode(' ')->map(fn ($p) => \Illuminate\Support\Str::substr($p, 0, 1))->take(2)->implode('');
        $role = $me->roles->pluck('name')->first() ?? 'No role';
    @endphp

    {{-- Mobile backdrop --}}
    <div x-show="nav" x-transition.opacity x-on:click="nav = false" class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden" x-cloak></div>

    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-navy-900 text-slate-300 transition-transform lg:translate-x-0"
           x-bind:class="{ 'translate-x-0': nav }" aria-label="Main navigation">
        <div class="flex h-16 shrink-0 items-center gap-3 border-b border-white/10 px-5">
            <div class="flex size-9 items-center justify-center rounded-lg bg-brand-600 text-white"><x-icon name="chart-bar" class="size-5" /></div>
            <span class="text-sm font-bold uppercase leading-tight tracking-wide text-white">{{ config('app.name') }}</span>
        </div>

        <nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4">
            @foreach ($menu as $section)
                <div>
                    <p class="px-3 text-xs font-bold uppercase tracking-wider text-slate-400">{{ $section['section'] }}</p>
                    <ul class="mt-2 space-y-0.5">
                        @foreach ($section['items'] as $item)
                            <li>
                                @if ($item['soon'])
                                    <span class="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-600" title="On the roadmap, not built yet">
                                        <x-icon :name="$item['icon']" class="size-5 text-slate-700" />
                                        <span class="flex-1">{{ $item['label'] }}</span>
                                        <span class="rounded bg-white/5 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-slate-500">Soon</span>
                                    </span>
                                @else
                                    <a href="{{ $item['url'] }}" wire:navigate
                                       @class([
                                           'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                                           'border-l-4 border-safety-500 bg-white/10 text-white' => $item['active'],
                                           'border-l-4 border-transparent hover:bg-white/5 hover:text-white' => ! $item['active'],
                                       ])
                                       @if ($item['active']) aria-current="page" @endif>
                                        <x-icon :name="$item['icon']" class="size-5 {{ $item['active'] ? 'text-white' : 'text-slate-500 group-hover:text-slate-300' }}" />
                                        {{ $item['label'] }}
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>
    </aside>

    <div class="lg:pl-64">
        {{-- Top bar --}}
        <header class="sticky top-0 z-20 flex min-h-16 flex-wrap items-center gap-x-3 gap-y-2 py-2 border-b border-slate-300 bg-white px-4 sm:px-6">
            <button type="button" class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" x-on:click="nav = true" aria-label="Open navigation">
                <x-icon name="menu" />
            </button>
            <div class="flex flex-1 justify-center"><livewire:analytics.search-box /></div>
            <div class="order-last w-full lg:order-none lg:w-auto"><x-shared::context-switcher /></div>
            <livewire:shared.notification-bell />

            {{-- User menu --}}
            <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                <button type="button" class="flex items-center gap-2 rounded-lg p-1 pr-2 hover:bg-slate-100" x-on:click="open = ! open" aria-haspopup="menu" x-bind:aria-expanded="open">
                    <span class="flex size-8 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white">{{ $initials }}</span>
                    <span class="hidden text-left leading-tight sm:block">
                        <span class="block max-w-32 truncate text-sm font-medium text-slate-800">{{ $me->name }}</span>
                        <span class="block max-w-32 truncate text-xs text-slate-500">{{ $role }}</span>
                    </span>
                    <x-icon name="chevron-down" class="hidden size-4 text-slate-400 sm:block" />
                </button>
                <div x-show="open" x-cloak x-transition class="absolute right-0 top-full z-30 mt-1 w-56 rounded-lg border border-slate-200 bg-white py-1 shadow-lg" role="menu">
                    <div class="border-b border-slate-100 px-3 py-2 sm:hidden">
                        <p class="truncate text-sm font-medium text-slate-800">{{ $me->name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ $role }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50" role="menuitem">
                            <x-icon name="logout" class="size-4 text-slate-400" /> Sign out
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-[96rem] px-4 py-6 sm:px-6 lg:px-8">
            <x-flash :page="$slot" />
            {{ $slot }}
        </main>
    </div>
</body>
</html>

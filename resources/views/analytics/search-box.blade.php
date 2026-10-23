<div class="relative w-full max-w-xl" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
    <label for="global-search" class="sr-only">Search anything</label>
    <x-icon name="magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
    <input id="global-search" type="search" wire:model.live.debounce.300ms="term" x-on:focus="open = true" x-on:input="open = true" autocomplete="off" maxlength="80"
           placeholder="Search anything (products, orders, leads, incidents, screens…)"
           class="w-full rounded-lg border border-slate-200 bg-slate-50 py-2 pl-9 pr-3 text-sm placeholder:text-slate-400 focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-500/30">

    <div x-show="open && {{ $searched ? 'true' : 'false' }}" x-cloak class="absolute left-0 right-0 top-full z-30 mt-1 max-h-96 overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg" role="listbox">
        @forelse ($groups as $group)
            <div wire:key="sg-{{ $group['group'] }}">
                <p class="px-3 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $group['group'] }}</p>
                @foreach ($group['hits'] as $hit)
                    <a href="{{ $hit['url'] }}" wire:navigate wire:key="sh-{{ md5($hit['url']) }}" class="block px-3 py-1.5 text-sm hover:bg-slate-50" role="option">
                        <span class="text-slate-800">{{ $hit['label'] }}</span>
                        @if ($hit['sub'])<span class="ml-2 text-xs text-slate-400">{{ $hit['sub'] }}</span>@endif
                    </a>
                @endforeach
            </div>
        @empty
            <p class="px-3 py-3 text-sm text-slate-500">Nothing found for “{{ $term }}”.</p>
        @endforelse
    </div>
</div>

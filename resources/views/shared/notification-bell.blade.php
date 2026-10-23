<div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
    <button type="button" x-on:click="open = ! open" class="relative rounded-lg p-2 text-slate-600 hover:bg-slate-100" aria-label="Notifications" aria-haspopup="true" x-bind:aria-expanded="open">
        <x-icon name="bell" />
        @if ($this->unreadCount > 0)
            <span class="absolute right-1 top-1 flex min-w-4 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-semibold leading-4 text-white">{{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}</span>
        @endif
    </button>

    <div x-show="open" x-transition x-cloak class="absolute right-0 mt-2 w-80 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
            <p class="text-sm font-semibold text-slate-800">Notifications</p>
            @if ($this->unreadCount > 0)
                <button type="button" wire:click="markAllRead" class="text-xs font-medium text-brand-600 hover:text-brand-700">Mark all read</button>
            @endif
        </div>
        <ul class="max-h-80 divide-y divide-slate-100 overflow-y-auto">
            @forelse ($this->latest as $notification)
                <li>
                    <button type="button" wire:click="openNotification('{{ $notification->id }}')"
                            class="block w-full px-4 py-3 text-left hover:bg-slate-50 {{ $notification->read_at ? '' : 'bg-brand-50/50' }}">
                        <p class="text-sm text-slate-800">{{ $notification->data['message'] ?? $notification->data['title'] ?? 'Notification' }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ $notification->created_at->diffForHumans() }}</p>
                    </button>
                </li>
            @empty
                <li class="px-4 py-8 text-center text-sm text-slate-500">You are all caught up.</li>
            @endforelse
        </ul>
    </div>
</div>

<div class="mx-auto max-w-3xl space-y-5">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Holidays</h1>
        <p class="mt-1 text-sm text-slate-500">Days the company is closed. Leave does not count them, attendance marks them as holidays, and SLA clocks in business hours skip them. The weekly off is a company setting.</p>
    </div>

    <x-status />

    @if ($canCreate)
        <form wire:submit="addHoliday" class="card card-body flex flex-wrap items-end gap-3" novalidate>
            <div><label for="h-date" class="label">Day</label><input id="h-date" type="date" wire:model="h.holiday_date" class="input">@error('holiday_date')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="min-w-56 flex-1"><label for="h-name" class="label">What is it</label><input id="h-name" type="text" wire:model="h.name" class="input" maxlength="120" placeholder="e.g. Diwali">@error('name')<p class="error">{{ $message }}</p>@enderror</div>
            <button type="submit" class="btn-primary">Add holiday</button>
        </form>
    @endif

    <div class="card">
        <div class="card-header flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-900">{{ $yearNumber }}</h2>
            <div class="flex items-center gap-2"><label for="h-year" class="sr-only">Year</label><input id="h-year" type="text" inputmode="numeric" maxlength="4" wire:model.live.debounce.500ms="year" class="input w-24"></div>
        </div>
        <ul class="divide-y divide-slate-100 text-sm">
            @forelse ($holidays as $h)
                <li class="flex items-center justify-between px-5 py-2.5" wire:key="hol-{{ $h->id }}">
                    <span><span class="font-medium text-slate-900">{{ $h->holiday_date->format('d M Y') }}</span> <span class="text-slate-500">{{ $h->holiday_date->format('l') }}</span> · {{ $h->name }}</span>
                    @if ($canDelete)<button type="button" wire:click="removeHoliday({{ $h->id }})" wire:confirm="Remove this holiday?" class="text-xs text-rose-600 hover:underline">Remove</button>@endif
                </li>
            @empty
                <li class="px-5 py-8 text-center text-slate-500">No holidays for {{ $yearNumber }}.</li>
            @endforelse
        </ul>
    </div>
</div>

@php($max = max(1, collect($items)->max('value') ?? 1))
<ul class="space-y-2">
    @forelse ($items as $i => $item)
        <li wire:key="{{ $key }}-{{ $i }}">
            <div class="flex items-baseline justify-between gap-3 text-sm">
                <span class="truncate text-slate-700">{{ $item['label'] }}</span>
                <span class="tabular-nums font-medium text-slate-900">{{ number_format($item['value']) }}</span>
            </div>
            <div class="mt-1 h-2 rounded-full bg-slate-100" role="img" aria-label="{{ $item['label'] }}: {{ number_format($item['value']) }}">
                <div class="h-2 rounded-full {{ $color ?? 'bg-brand-600' }}" style="width: {{ round(100 * $item['value'] / $max) }}%"></div>
            </div>
        </li>
    @empty
        <li class="text-sm text-slate-500">Nothing in this period.</li>
    @endforelse
</ul>

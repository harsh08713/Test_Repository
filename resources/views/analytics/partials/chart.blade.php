{{-- The outer key is a hash of the figures, so new figures replace the chart; wire:ignore keeps Livewire's morphing from wiping the canvas Chart.js drew on. --}}
<div wire:key="chart-{{ md5(json_encode($spec)) }}" class="relative {{ $height ?? 'h-64' }}" role="img" aria-label="{{ $label ?? 'Chart' }}">
    <div wire:ignore x-data="analyticsChart(@js($spec))" class="size-full">
        <canvas x-ref="canvas"></canvas>
    </div>
</div>

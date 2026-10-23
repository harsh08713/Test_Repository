@php $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
<div class="mx-auto max-w-4xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('production.boms.index') }}" wire:navigate class="hover:text-brand-700">Bills of materials</a></p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $bom->item?->name }} <span class="font-mono text-base font-normal text-slate-500">{{ $bom->code }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span class="badge {{ $bom->approval_status->badge() }}">{{ $bom->approval_status->label() }}</span>
                @if ($bom->status !== 'active')<span class="badge bg-slate-100 text-slate-700">Inactive</span>@endif
                <span class="tabular-nums text-slate-600">Makes {{ $qty($bom->output_quantity) }} {{ $bom->item?->baseUnit?->code }}</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canOrder)<a href="{{ route('production.orders.create', ['bom' => $bom->id]) }}" wire:navigate class="btn-primary">Plan an order</a>@endif
            @if ($canEdit)<a href="{{ route('production.boms.edit', $bom) }}" wire:navigate class="btn-secondary">Edit</a>@endif
            @if ($canToggle)<button type="button" wire:click="toggle" class="btn-ghost">{{ $bom->status === 'active' ? 'Make inactive' : 'Make active' }}</button>@endif
        </div>
    </div>

    <x-status />
    @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <section class="card" aria-labelledby="s-lines">
        <div class="card-header"><h2 id="s-lines" class="text-sm font-semibold text-slate-900">Components</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>#</th><th>Component</th><th class="text-right">Quantity</th><th class="text-right">Wastage</th></tr></thead>
                <tbody>
                    @foreach ($bom->components as $line)
                        <tr wire:key="bl-{{ $line->id }}">
                            <td class="tabular-nums text-slate-500">{{ $line->line_no }}</td>
                            <td class="font-medium text-slate-900">{{ $line->item?->name }}</td>
                            <td class="text-right tabular-nums">{{ $qty($line->quantity) }} {{ $line->item?->baseUnit?->code }}</td>
                            <td class="text-right tabular-nums">{{ $qty($line->scrap_percent) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($bom->remarks)<p class="border-t border-slate-100 px-5 py-3 text-sm text-slate-700">{{ $bom->remarks }}</p>@endif
    </section>

    <section class="card" aria-labelledby="s-raw">
        <div class="card-header"><h2 id="s-raw" class="text-sm font-semibold text-slate-900">Down to raw materials</h2></div>
        <div class="card-body text-sm text-slate-600">
            <p class="mb-2">What {{ $qty($bom->output_quantity) }} {{ $bom->item?->baseUnit?->code }} takes in all, following any component that has a bill of its own (wastage included).</p>
            <ul class="divide-y divide-slate-100">
                @foreach ($needs as $need)
                    <li class="flex justify-between gap-3 py-1.5" wire:key="rw-{{ $need['item']->id }}"><span class="text-slate-800">{{ $need['item']->name }}</span><span class="tabular-nums">{{ $qty($need['quantity']) }} {{ $need['item']->baseUnit?->code }}</span></li>
                @endforeach
            </ul>
        </div>
    </section>

    <livewire:shared.approval-panel type="bom" :id="$bom->id" :key="'ap-'.$bom->id" />

    @if ($history->isNotEmpty())
        <section class="card" aria-labelledby="s-hist">
            <div class="card-header"><h2 id="s-hist" class="text-sm font-semibold text-slate-900">Audit trail</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($history as $entry)
                    <li class="flex justify-between gap-3 px-5 py-2" wire:key="h-{{ $entry->id }}"><span>{{ str_replace('_', ' ', ucfirst((string) ($entry->event ?? $entry->description))) }} · {{ $entry->causer?->name ?? 'System' }}</span><span class="tabular-nums text-xs text-slate-500">{{ $entry->created_at?->format('d-m-Y H:i') }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif
</div>

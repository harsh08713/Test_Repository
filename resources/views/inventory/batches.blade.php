@php use App\Modules\Shared\Support\Format; $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
<div class="space-y-5">
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">Batches and expiry</h1>
        <p class="mt-1 text-sm text-slate-500">Batches with stock, soonest expiry first. An expired batch cannot be issued or moved — only scrapped.</p>
    </div>

    <x-status />

    <dl class="grid gap-3 sm:grid-cols-2">
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Stock expiring within {{ $days }} days, or already expired</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($exposure, 2) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Batches shown</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format($batches->total()) }}</dd></div>
    </dl>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="bt-search" class="sr-only">Search</label>
                <input id="bt-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Batch, lot, item…" class="input max-w-xs">
                <label for="bt-show" class="sr-only">Show</label>
                <select id="bt-show" wire:model.live="show" class="input w-56">
                    <option value="">Batches with stock</option>
                    <option value="expiring">Expiring within {{ $days }} days</option>
                    <option value="expired">Expired</option>
                    <option value="all">All batches</option>
                </select>
            </div>
            @if ($canEnter)<a href="{{ route('inventory.entry') }}" wire:navigate class="btn-secondary btn-sm">Scrap or move stock</a>@endif
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Item</th><th>Batch</th><th>Lot</th><th>Made</th><th>Expires</th><th>Status</th><th class="text-right">On hand</th><th class="text-right">Value (₹)</th><th></th></tr></thead>
                <tbody>
                    @forelse ($batches as $b)
                        <tr wire:key="b-{{ $b->id }}">
                            <td><span class="font-medium text-slate-900">{{ $b->item?->name }}</span> <span class="font-mono text-xs text-slate-500">{{ $b->item?->code }}</span></td>
                            <td class="font-mono text-xs">{{ $b->batch_no }}</td>
                            <td class="font-mono text-xs">{{ $b->lot_no }}</td>
                            <td class="tabular-nums text-slate-600">{{ $b->mfg_date?->format('d-m-Y') }}</td>
                            <td class="tabular-nums">{{ $b->expiry_date?->format('d-m-Y') ?? '—' }}</td>
                            <td><span @class(['badge', 'bg-emerald-100 text-emerald-800' => $b->isUsable(), 'bg-rose-100 text-rose-800' => $b->isExpired(), 'bg-amber-100 text-amber-800' => ! $b->isExpired() && ! $b->isUsable()])>{{ $b->isExpired() ? 'Expired' : ucfirst($b->status) }}</span></td>
                            <td class="text-right tabular-nums">{{ $qty($b->on_hand ?? 0) }} <span class="text-slate-500">{{ $b->item?->baseUnit?->code }}</span></td>
                            <td class="text-right tabular-nums">{{ Format::number((string) ($b->stock_value ?? 0), 2) }}</td>
                            <td class="whitespace-nowrap text-right">@if ($canReportLoss && $b->isExpired() && bccomp((string) ($b->on_hand ?? 0), '0', 3) > 0)<a href="{{ route('loss.incidents.create', ['source' => 'batch', 'id' => $b->id]) }}" wire:navigate class="btn-ghost btn-sm">Report as loss</a>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-10 text-center text-slate-500">No batches to show.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($batches->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $batches->links() }}</div>@endif
    </div>
</div>

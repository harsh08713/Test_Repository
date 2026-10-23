@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-5">
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">Profitability</h1>
        <p class="mt-1 text-sm text-slate-500">Net revenue (issued invoices less approved returns, before tax), the cost copied onto those lines, and the profit left. Actual loss is shown beside the profit, not taken off it.</p>
    </div>

    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Net revenue</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($totals['net_revenue'], 2) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Cost of sales</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($totals['cogs'], 2) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Gross profit</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($totals['gross_profit'], 2) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Margin</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ $totals['margin_pct'] === null ? '—' : $totals['margin_pct'].'%' }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Actual loss (validated)</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($totals['actual_loss'], 2) }}</dd></div>
    </dl>
    @if ($incomplete > 0)
        <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $incomplete }} line(s) had no known cost, so the profit shown is not complete.</p>
    @endif

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="p-by" class="sr-only">By</label>
                <select id="p-by" wire:model.live="by" class="input w-40">@foreach ($dimensions as $k => $label)<option value="{{ $k }}">By {{ strtolower($label) }}</option>@endforeach</select>
                <label for="p-from" class="sr-only">From</label><input id="p-from" type="date" wire:model.live="from" class="input w-40">
                <label for="p-to" class="sr-only">To</label><input id="p-to" type="date" wire:model.live="to" class="input w-40">
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>{{ $dimensions[$by] }}</th><th class="text-right">Gross sales</th><th class="text-right">Discounts</th><th class="text-right">Returns</th><th class="text-right">Net revenue</th><th class="text-right">Cost</th><th class="text-right">Gross profit</th><th class="text-right">Margin</th><th class="text-right">Actual loss</th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="pf-{{ $by }}-{{ $r['key'] }}">
                            <td class="font-medium text-slate-900">{{ $r['name'] }}@if ($r['lines_without_cost'] > 0) <span class="badge bg-amber-100 text-amber-800" title="Some lines have no known cost">cost incomplete</span>@endif</td>
                            <td class="text-right tabular-nums">{{ Format::number($r['gross_sales'], 2) }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($r['discounts'], 2) }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($r['returns'], 2) }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($r['net_revenue'], 2) }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($r['cogs'], 2) }}</td>
                            <td class="text-right font-medium tabular-nums">{{ Format::number($r['gross_profit'], 2) }}</td>
                            <td class="text-right tabular-nums">{{ $r['margin_pct'] === null ? '—' : $r['margin_pct'].'%' }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($r['actual_loss'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-10 text-center text-slate-500">No sales or losses in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

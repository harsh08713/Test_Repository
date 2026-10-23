@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-5">
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">Budget against actual</h1>
        <p class="mt-1 text-sm text-slate-500">Approved budgets against validated actual losses charged to each cost centre, for the months in the period. Over budget is shown in red. Exposure and opportunity are not actual losses and are left out.</p>
    </div>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="v-from" class="sr-only">From</label><input id="v-from" type="date" wire:model.live="from" class="input w-40">
                <label for="v-to" class="sr-only">To</label><input id="v-to" type="date" wire:model.live="to" class="input w-40">
                <label for="v-cc" class="sr-only">Cost centre</label>
                <select id="v-cc" wire:model.live="costCenter" class="input w-52"><option value="">All cost centres</option>@foreach ($costCenters as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Cost centre</th><th>Category</th><th class="text-right">Budget (₹)</th><th class="text-right">Actual (₹)</th><th class="text-right">Variance (₹)</th><th class="text-right">Variance %</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="v-{{ $r['cost_center_id'] }}-{{ $r['category_id'] ?? 0 }}">
                            <td class="font-medium text-slate-900">{{ $r['cost_center'] }}</td>
                            <td>{{ $r['category'] }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($r['budget'], 2) }}</td>
                            <td class="text-right tabular-nums">{{ Format::number($r['actual'], 2) }}</td>
                            <td @class(['text-right tabular-nums', 'font-medium text-rose-700' => $r['status'] === 'over'])>{{ Format::number($r['variance'], 2) }}</td>
                            <td @class(['text-right tabular-nums', 'font-medium text-rose-700' => $r['status'] === 'over'])>{{ $r['variance_pct'] === null ? '—' : $r['variance_pct'].'%' }}</td>
                            <td><span @class(['badge', 'bg-rose-100 text-rose-800' => $r['status'] === 'over', 'bg-emerald-100 text-emerald-800' => $r['status'] === 'within', 'bg-slate-100 text-slate-600' => $r['status'] === 'unbudgeted'])>{{ ['over' => 'Over budget', 'within' => 'Within budget', 'unbudgeted' => 'No budget'][$r['status']] }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-slate-500">No approved budgets or charged losses in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@use('App\Modules\Analytics\Support\Fmt')
<div class="space-y-6">
    <div>
        <nav class="mb-2 flex flex-wrap gap-1.5 text-sm" aria-label="Analytics pages">
            <a href="{{ route('analytics.dashboard', $query) }}" wire:navigate class="rounded-md px-2.5 py-1 text-slate-600 hover:bg-slate-100">Dashboard</a>
            @foreach ($links as $s => $d)
                <a href="{{ route('analytics.page', ['page' => $s] + $query) }}" wire:navigate @class(['rounded-md px-2.5 py-1', 'bg-brand-600 font-medium text-white' => $s === $slug, 'text-slate-600 hover:bg-slate-100' => $s !== $slug])>{{ $d['title'] }}</a>
            @endforeach
        </nav>
        <h1 class="text-2xl font-semibold text-slate-900">{{ $def['title'] }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ $def['blurb'] }}</p>
    </div>

    @include('analytics.partials.filters')

    @if ($slug === 'revenue-loss')
        @php
            $t = $data['trend'];
            $series = [];
            if ($data['can']['revenue']) {
                $series[] = ['type' => 'bar', 'label' => 'Net revenue', 'data' => $t['revenue'], 'color' => '#2563eb'];
                $series[] = ['type' => 'line', 'label' => 'Revenue, previous period', 'data' => $t['prior_revenue'], 'color' => '#93c5fd', 'dashed' => true];
            }
            if ($data['can']['loss']) {
                $series[] = ['type' => 'line', 'label' => 'Actual loss', 'data' => $t['loss'], 'color' => '#ef4444'];
                $series[] = ['type' => 'line', 'label' => 'Loss, previous period', 'data' => $t['prior_loss'], 'color' => '#fca5a5', 'dashed' => true];
            }
        @endphp
        <div class="card">
            <div class="card-header flex justify-end">
                <div class="inline-flex rounded-lg border border-slate-200 p-0.5 text-xs" role="group" aria-label="Group by">
                    @foreach (['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly'] as $g => $name)
                        <button type="button" wire:click="$set('grain', '{{ $g }}')" @class(['rounded-md px-3 py-1.5 font-medium', 'bg-brand-600 text-white' => $t['granularity'] === $g, 'text-slate-600 hover:bg-slate-100' => $t['granularity'] !== $g])>{{ $name }}</button>
                    @endforeach
                </div>
            </div>
            <div class="card-body">@include('analytics.partials.chart', ['spec' => ['kind' => 'trend', 'labels' => $t['labels'], 'series' => $series], 'height' => 'h-80', 'label' => 'Revenue and loss over time'])</div>
        </div>
        <div class="card"><div class="table-wrap"><table class="data-table">
            <thead><tr><th>Period</th>@if ($data['can']['revenue'])<th class="text-right">Revenue</th><th class="text-right">Previous</th>@endif @if ($data['can']['loss'])<th class="text-right">Loss</th><th class="text-right">Previous</th>@endif</tr></thead>
            <tbody>
                @foreach ($t['labels'] as $i => $label)
                    <tr wire:key="row-{{ $i }}"><td>{{ $label }}</td>
                        @if ($data['can']['revenue'])<td class="text-right">{{ Fmt::inr($t['revenue'][$i]) }}</td><td class="text-right text-slate-500">{{ Fmt::inr($t['prior_revenue'][$i]) }}</td>@endif
                        @if ($data['can']['loss'])<td class="text-right">{{ Fmt::inr($t['loss'][$i]) }}</td><td class="text-right text-slate-500">{{ Fmt::inr($t['prior_loss'][$i]) }}</td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table></div></div>

    @elseif (in_array($slug, ['industry-revenue', 'industry-losses'], true))
        @php $rows = $data['rows']; @endphp
        <div class="card">
            <div class="card-body">
                @if ($rows === [])
                    <p class="py-10 text-center text-sm text-slate-500">Nothing in this period.</p>
                @else
                    <div class="grid items-center gap-6 md:grid-cols-2">
                        @include('analytics.partials.chart', ['spec' => ['kind' => 'donut', 'labels' => array_column($rows, 'name'), 'values' => array_column($rows, 'amount')], 'height' => 'h-64', 'label' => $def['title']])
                        <table class="data-table">
                            <thead><tr><th>Industry</th><th class="text-right">Amount</th><th class="text-right">Share</th></tr></thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr wire:key="ind-{{ $row['key'] }}">
                                        <td>
                                            @if ($slug === 'industry-losses' && $row['key'] > 0)
                                                <a href="{{ route('analytics.page', ['page' => 'industry-losses'] + array_merge($query, ['industry' => $row['key']])) }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ $row['name'] }}</a>
                                            @else
                                                {{ $row['name'] }}
                                            @endif
                                        </td>
                                        <td class="text-right">{{ Fmt::inr($row['amount']) }}</td><td class="text-right">{{ Fmt::pct($row['share_pct']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        @if ($slug === 'industry-losses' && $f->industryId !== null)
            <div class="card">
                <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Categories in this industry</h2></div>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>Category</th><th class="text-right">Loss</th><th class="text-right">Incidents</th></tr></thead>
                    <tbody>
                        @forelse ($data['categories'] as $c)
                            <tr wire:key="cat-{{ $c['category_id'] }}"><td><a href="{{ route('analytics.page', ['page' => 'industry-losses'] + array_merge($query, ['category' => $c['category_id']])) }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ $c['category'] }}</a></td><td class="text-right">{{ Fmt::inr($c['amount']) }}</td><td class="text-right">{{ $c['incidents'] }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="py-6 text-center text-slate-500">No losses.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>
        @endif

        @if ($slug === 'industry-losses' && $data['sites'] !== [])
            <div class="card">
                <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">By branch</h2></div>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>Branch</th><th class="text-right">Loss</th><th class="text-right">Incidents</th></tr></thead>
                    <tbody>
                        @foreach ($data['sites'] as $s)
                            <tr wire:key="site-{{ $s['key'] }}"><td>{{ $s['name'] }}</td><td class="text-right">{{ Fmt::inr($s['amount']) }}</td><td class="text-right">{{ $s['incidents'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table></div>
            </div>
        @endif

        @if ($slug === 'industry-losses' && $data['incidents'] !== [])
            <div class="card">
                <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Incidents behind these figures</h2></div>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>Incident</th><th>Category</th><th>Branch</th><th>Severity</th><th class="text-right">Actual loss</th></tr></thead>
                    <tbody>
                        @foreach ($data['incidents'] as $i)
                            <tr wire:key="inc-{{ $i['id'] }}"><td><a href="{{ route('loss.incidents.show', $i['id']) }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ $i['number'] }}</a><div class="text-xs text-slate-500">{{ $i['title'] }}</div></td><td>{{ $i['category'] ?? '—' }}</td><td>{{ $i['branch'] ?? '—' }}</td><td>{{ ucfirst($i['severity']) }}</td><td class="text-right">{{ Fmt::inr($i['actual_loss']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table></div>
            </div>
        @endif

    @elseif ($slug === 'top-products')
        <div class="card">
            <div class="card-body">
                @if ($data['rows'] === [])
                    <p class="py-10 text-center text-sm text-slate-500">No sales in this period.</p>
                @else
                    @include('analytics.partials.chart', ['spec' => ['kind' => 'bars', 'labels' => array_column($data['rows'], 'name'), 'values' => array_column($data['rows'], 'net_revenue'), 'color' => '#2563eb'], 'height' => 'h-96', 'label' => 'Top products by revenue'])
                @endif
            </div>
        </div>
        <div class="card"><div class="table-wrap"><table class="data-table">
            <thead><tr><th>Product</th><th class="text-right">Net revenue</th><th class="text-right">Units</th><th class="text-right">Gross profit</th><th class="text-right">Margin</th><th class="text-right">vs previous</th></tr></thead>
            <tbody>
                @foreach ($data['rows'] as $r)
                    <tr wire:key="p-{{ $r['key'] }}"><td class="font-medium">{{ $r['name'] }}</td><td class="text-right">{{ Fmt::inr($r['net_revenue']) }}</td><td class="text-right">{{ rtrim(rtrim($r['units'], '0'), '.') ?: '0' }}</td><td class="text-right">{{ Fmt::inr($r['gross_profit']) }}</td><td class="text-right">{{ Fmt::pct($r['margin_pct']) }}</td><td class="text-right">{{ Fmt::change($r['change_pct']) }}</td></tr>
                @endforeach
            </tbody>
        </table></div></div>

    @elseif ($slug === 'loss-categories')
        <div class="card">
            <div class="card-body">
                @if ($data['rows'] === [])
                    <p class="py-10 text-center text-sm text-slate-500">No losses in this period.</p>
                @else
                    @include('analytics.partials.chart', ['spec' => ['kind' => 'bars', 'labels' => array_column($data['rows'], 'category'), 'values' => array_column($data['rows'], 'amount'), 'color' => '#ef4444'], 'height' => 'h-96', 'label' => 'Loss by category'])
                @endif
            </div>
        </div>
        <div class="card"><div class="table-wrap"><table class="data-table">
            <thead><tr><th>Category</th><th class="text-right">Loss</th><th class="text-right">Incidents</th><th class="text-right">Share</th><th class="text-right">Running share</th><th class="text-right" title="Incidents with an earlier one of the same category, branch and item in the 90 days before">Recurring</th></tr></thead>
            <tbody>
                @foreach ($data['rows'] as $r)
                    <tr wire:key="c-{{ $r['category_id'] }}"><td class="font-medium">{{ $r['category'] }}</td><td class="text-right">{{ Fmt::inr($r['amount']) }}</td><td class="text-right">{{ $r['incidents'] }}</td><td class="text-right">{{ Fmt::pct($r['share_pct']) }}</td><td class="text-right">{{ Fmt::pct($r['cumulative_pct']) }}</td><td class="text-right">{{ $r['recurrence'] }}</td></tr>
                @endforeach
            </tbody>
        </table></div></div>

    @else
        <div class="card">
            <div class="card-header flex justify-end">
                <select wire:model.live="sort" class="input w-auto py-1 pr-8 text-xs" aria-label="Rank brands by">
                    <option value="net_revenue">By revenue</option><option value="gross_profit">By profit</option><option value="margin_pct">By margin</option><option value="actual_loss">By loss</option><option value="cogs">By cost</option>
                </select>
            </div>
            <div class="table-wrap"><table class="data-table">
                <thead><tr><th>Brand</th><th class="text-right">Revenue</th><th class="text-right">Cost</th><th class="text-right">Gross profit</th><th class="text-right">Loss</th><th class="text-right">Margin</th></tr></thead>
                <tbody>
                    @forelse ($data['rows'] as $r)
                        <tr wire:key="b-{{ $r['key'] }}"><td class="font-medium">{{ $r['name'] }}</td><td class="text-right">{{ Fmt::inr($r['net_revenue']) }}</td><td class="text-right">{{ Fmt::inr($r['cogs']) }}</td><td class="text-right">{{ Fmt::inr($r['gross_profit']) }}</td><td class="text-right">{{ Fmt::inr($r['actual_loss']) }}</td><td class="text-right">{{ Fmt::pct($r['margin_pct']) }}</td></tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-slate-500">Nothing in this period.</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </div>
    @endif
</div>

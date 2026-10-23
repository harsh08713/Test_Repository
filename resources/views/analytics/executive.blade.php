@use('App\Modules\Analytics\Support\Fmt')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-semibold text-slate-900">
                Executive dashboard
                @if ($demo)<span class="badge bg-amber-100 text-amber-800">Demo data</span>@endif
            </h1>
            <p class="mt-1 text-sm text-slate-500">Revenue, losses and profitability across your industries. Figures follow your role and your branches.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach ($links as $slug)
                <a href="{{ route('analytics.page', ['page' => $slug] + $query) }}" wire:navigate class="btn-secondary btn-sm">{{ \App\Modules\Analytics\Services\AnalyticsPages::PAGES[$slug]['title'] }}</a>
            @endforeach
        </div>
    </div>

    @include('analytics.partials.filters')

    {{-- KPI cards --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5" data-testid="kpi-cards">
        @foreach ($cards as $key => $card)
            <div class="card card-body" wire:key="kpi-{{ $key }}" title="{{ $card['formula'] }}">
                <p class="text-sm font-medium text-slate-500">{{ $card['label'] }}</p>
                @php
                    $isMoney = in_array($key, ['revenue', 'losses'], true);
                @endphp
                <p class="mt-2 truncate text-2xl font-semibold text-slate-900">{{ $isMoney ? Fmt::compact($card['value']) : $card['value'] }}</p>
                @if ($key === 'brand' && ! empty($card['sub']))<p class="text-xs text-slate-500">{{ Fmt::compact($card['sub']) }} net revenue</p>@endif
                <div class="mt-1">@include('analytics.partials.delta', ['change' => $card['change_pct'], 'good' => $card['good']])</div>
            </div>
        @endforeach
    </div>

    @if ($leakage['radar'] !== null || $leakage['savings'] !== null)
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="leakage-cards">
            @if ($leakage['radar'] !== null)
                <div class="card card-body" title="Findings the Leakage radar is waiting for a person to report or dismiss. Estimates only: none is a loss until reported and validated.">
                    <p class="text-sm font-medium text-slate-500">Radar findings waiting</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($leakage['radar']['open']) }}</p>
                    <p class="text-xs text-slate-500">{{ number_format($leakage['radar']['high']) }} high · <a href="{{ route('loss.radar') }}" wire:navigate class="font-medium text-brand-600 hover:underline">review</a></p>
                </div>
                <div class="card card-body" title="The sum of the open findings' estimates. An estimate by the Radar, not an actual loss and not added to any loss figure.">
                    <p class="text-sm font-medium text-slate-500">Estimated, not yet reported</p>
                    <p class="mt-2 truncate text-2xl font-semibold text-slate-900">{{ Fmt::compact($leakage['radar']['estimate']) }}</p>
                    <p class="text-xs text-slate-500">estimate · not a loss</p>
                </div>
            @endif
            @if ($leakage['savings'] !== null)
                <div class="card card-body" title="Net saving of measurements Finance verified in this period. Different from a corrective action's own verified result; the two are never added.">
                    <p class="text-sm font-medium text-slate-500">Finance-verified savings</p>
                    <p class="mt-2 truncate text-2xl font-semibold text-slate-900">{{ Fmt::compact($leakage['savings']['net_saving']) }}</p>
                    <p class="text-xs text-slate-500">{{ number_format($leakage['savings']['count']) }} measurements · <a href="{{ route('loss.savings', ['show' => 'verified']) }}" wire:navigate class="font-medium text-brand-600 hover:underline">open</a></p>
                </div>
                <div class="card card-body" title="Verified savings whose loss has come back at the same branch.">
                    <p class="text-sm font-medium text-slate-500">Savings that recurred</p>
                    <p @class(['mt-2 text-2xl font-semibold', 'text-rose-700' => $leakage['savings']['recurred'] > 0, 'text-slate-900' => $leakage['savings']['recurred'] === 0])>{{ number_format($leakage['savings']['recurred']) }}</p>
                    <p class="text-xs text-slate-500">of {{ number_format($leakage['savings']['count']) }} verified</p>
                </div>
            @endif
        </div>
    @endif

    @if ($trend !== null)
        <div class="card">
            <div class="card-header flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-900">{{ $canSales && $canLoss ? 'Revenue vs loss' : ($canSales ? 'Revenue' : 'Loss') }} trend</h2>
                <div class="inline-flex rounded-lg border border-slate-200 p-0.5 text-xs" role="group" aria-label="Group by">
                    @foreach (['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly'] as $g => $name)
                        <button type="button" wire:click="$set('grain', '{{ $g }}')" @class(['rounded-md px-2.5 py-1 font-medium', 'bg-brand-600 text-white' => $trend['granularity'] === $g, 'text-slate-600 hover:bg-slate-100' => $trend['granularity'] !== $g])>{{ $name }}</button>
                    @endforeach
                </div>
            </div>
            <div class="card-body">
                @php
                    $series = [];
                    if ($canSales) {
                        $series[] = ['type' => 'bar', 'label' => 'Net revenue', 'data' => $trend['revenue'], 'color' => '#2563eb'];
                        $series[] = ['type' => 'line', 'label' => 'Revenue, previous period', 'data' => $trend['prior_revenue'], 'color' => '#93c5fd', 'dashed' => true];
                    }
                    if ($canLoss) {
                        $series[] = ['type' => 'line', 'label' => 'Actual loss', 'data' => $trend['loss'], 'color' => '#ef4444'];
                        $series[] = ['type' => 'line', 'label' => 'Loss, previous period', 'data' => $trend['prior_loss'], 'color' => '#fca5a5', 'dashed' => true];
                    }
                @endphp
                @include('analytics.partials.chart', ['spec' => ['kind' => 'trend', 'labels' => $trend['labels'], 'series' => $series], 'height' => 'h-72', 'label' => 'Revenue and loss over time'])
            </div>
        </div>
    @endif

    {{-- Donuts --}}
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ([['Revenue by industry', $revenueByIndustry, $canSales, 'industry-revenue'], ['Losses by industry', $lossesByIndustry, $canLoss, 'industry-losses']] as [$title, $rows, $allowed, $slug])
            @if ($allowed)
                <div class="card" wire:key="donut-{{ $slug }}">
                    <div class="card-header flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-slate-900">{{ $title }}</h2>
                        <a href="{{ route('analytics.page', ['page' => $slug] + $query) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">Details</a>
                    </div>
                    <div class="card-body">
                        @if ($rows === [])
                            <p class="py-10 text-center text-sm text-slate-500">Nothing in this period.</p>
                        @else
                            <div class="grid items-center gap-4 sm:grid-cols-2">
                                @include('analytics.partials.chart', ['spec' => ['kind' => 'donut', 'labels' => array_column($rows, 'name'), 'values' => array_column($rows, 'amount')], 'height' => 'h-48', 'label' => $title])
                                <ul class="space-y-1.5 text-sm">
                                    @foreach (array_slice($rows, 0, 7) as $i => $row)
                                        <li class="flex items-center justify-between gap-2">
                                            <span class="flex min-w-0 items-center gap-2"><span class="size-2.5 shrink-0 rounded-full" style="background: {{ ['#2563eb', '#16a34a', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#ec4899'][$i % 7] }}"></span><span class="truncate text-slate-700">{{ $row['name'] }}</span></span>
                                            <span class="shrink-0 text-slate-500">{{ Fmt::compact($row['amount']) }} <span class="text-xs">({{ Fmt::pct($row['share_pct']) }})</span></span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    {{-- Bars --}}
    <div class="grid gap-4 lg:grid-cols-2">
        @if ($canSales)
            <div class="card">
                <div class="card-header flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-900">Top products by revenue</h2>
                    <a href="{{ route('analytics.page', ['page' => 'top-products'] + $query) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">Details</a>
                </div>
                <div class="card-body">
                    @if ($products === [])
                        <p class="py-10 text-center text-sm text-slate-500">No sales in this period.</p>
                    @else
                        @include('analytics.partials.chart', ['spec' => ['kind' => 'bars', 'labels' => array_column($products, 'name'), 'values' => array_column($products, 'net_revenue'), 'color' => '#2563eb'], 'height' => 'h-60', 'label' => 'Top products by revenue'])
                    @endif
                </div>
            </div>
        @endif
        @if ($canLoss)
            <div class="card">
                <div class="card-header flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-900">Top loss categories</h2>
                    <a href="{{ route('analytics.page', ['page' => 'loss-categories'] + $query) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">Details</a>
                </div>
                <div class="card-body">
                    @if ($categories === [])
                        <p class="py-10 text-center text-sm text-slate-500">No losses in this period.</p>
                    @else
                        @include('analytics.partials.chart', ['spec' => ['kind' => 'bars', 'labels' => array_column($categories, 'category'), 'values' => array_column($categories, 'amount'), 'color' => '#ef4444'], 'height' => 'h-60', 'label' => 'Top loss categories'])
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- Brand profitability --}}
    @if ($canSales)
        <div class="card">
            <div class="card-header flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-900">Profitability by brand</h2>
                <div class="flex gap-2">
                    <select wire:model.live="rank" class="input w-auto py-1 pr-8 text-xs" aria-label="Rank brands by">
                        <option value="net_revenue">By revenue</option>
                        <option value="gross_profit">By profit</option>
                        <option value="margin_pct">By margin</option>
                        <option value="actual_loss">By loss</option>
                    </select>
                    <select wire:model.live="top" class="input w-auto py-1 pr-8 text-xs" aria-label="How many brands">
                        <option value="5">Top 5</option><option value="10">Top 10</option><option value="15">Top 15</option>
                    </select>
                </div>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Brand</th><th class="text-right">Revenue</th><th class="text-right">Losses</th><th class="text-right">Profit</th><th class="text-right">Margin</th></tr></thead>
                    <tbody>
                        @forelse ($brands as $row)
                            <tr wire:key="brand-{{ $row['key'] }}">
                                <td class="font-medium">{{ $row['name'] }}</td>
                                <td class="text-right">{{ Fmt::inr($row['net_revenue']) }}</td>
                                <td class="text-right">{{ Fmt::inr($row['actual_loss']) }}</td>
                                <td class="text-right">{{ Fmt::inr($row['gross_profit']) }}</td>
                                <td class="text-right">{{ Fmt::pct($row['margin_pct']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-8 text-center text-slate-500">No brand has sales in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Industry overview --}}
    <div class="card">
        <div class="card-header flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-900">Industry performance overview</h2>
            @can('industry_template.view')<a href="{{ route('industries.index') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">View all industries</a>@endcan
        </div>
        <div class="card-body grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            @foreach ($industries as $row)
                @php
                    $tag = $row['enabled'] ? 'a' : 'div';
                @endphp
                <{{ $tag }} @if ($row['enabled']) href="{{ route('industries.dashboard', ['industry' => $row['code']]) }}" wire:navigate @endif wire:key="ind-{{ $row['id'] }}"
                    @class(['block rounded-lg border p-3', 'border-slate-200 transition hover:border-brand-500' => $row['enabled'], 'border-dashed border-slate-200 bg-slate-50 opacity-60' => ! $row['enabled']])>
                    <p class="truncate text-sm font-semibold text-slate-800">{{ $row['name'] }}</p>
                    @if ($row['enabled'])
                        <p class="mt-1 text-xs text-slate-500">Revenue <span class="font-medium text-slate-800">{{ $canSales ? Fmt::compact($row['net_revenue']) : '—' }}</span></p>
                        <p class="text-xs text-slate-500">Loss <span class="font-medium text-slate-800">{{ $canLoss ? Fmt::compact($row['actual_loss']) : '—' }}</span></p>
                        <div class="mt-1">@include('analytics.partials.delta', ['change' => $canSales ? $row['change_pct'] : null, 'good' => 'up'])</div>
                    @else
                        <p class="mt-1 text-xs text-slate-500">Not switched on</p>
                    @endif
                </{{ $tag }}>
            @endforeach
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        @if ($canLoss)
            <div class="card lg:col-span-2">
                <div class="card-header flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-900">Latest loss incidents</h2>
                    <a href="{{ route('loss.incidents.index') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">All incidents</a>
                </div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Incident</th><th>Industry</th><th>Branch</th><th>Severity</th><th class="text-right">Actual loss</th></tr></thead>
                        <tbody>
                            @forelse ($incidents as $i)
                                <tr wire:key="inc-{{ $i->id }}">
                                    <td><a href="{{ route('loss.incidents.show', $i) }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ $i->incident_number }}</a><div class="max-w-xs truncate text-xs text-slate-500">{{ $i->title }}</div></td>
                                    <td>{{ $i->template?->name ?? '—' }}</td>
                                    <td>{{ $i->location?->name ?? '—' }}</td>
                                    <td><span @class(['badge', 'bg-rose-100 text-rose-800' => in_array($i->severity, ['high', 'critical'], true), 'bg-amber-100 text-amber-800' => $i->severity === 'medium', 'bg-slate-100 text-slate-700' => $i->severity === 'low'])>{{ ucfirst($i->severity) }}</span></td>
                                    <td class="text-right">{{ Fmt::inr(bcadd((string) ($i->actual_amount ?? '0'), '0', 2)) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-8 text-center text-slate-500">No incidents in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="space-y-4">
            @if ($actions !== [])
                <div class="card">
                    <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Quick actions</h2></div>
                    <div class="card-body grid grid-cols-2 gap-2">
                        @foreach ($actions as $a)
                            <a href="{{ $a['url'] }}" wire:navigate class="flex flex-col items-center gap-1 rounded-lg border border-slate-200 p-3 text-center text-xs font-medium text-slate-700 transition hover:border-brand-500 hover:text-brand-600">
                                <x-icon :name="$a['icon']" class="size-5" />{{ $a['label'] }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
            @if ($activity->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Recent activity</h2></div>
                    <ul class="divide-y divide-slate-100">
                        @foreach ($activity as $log)
                            <li class="px-4 py-2.5" wire:key="act-{{ $log->id }}">
                                <p class="truncate text-sm text-slate-700">{{ $log->description }}</p>
                                <p class="text-xs text-slate-400">{{ $log->causer?->name ?? 'System' }} · {{ $log->created_at->diffForHumans() }}</p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</div>

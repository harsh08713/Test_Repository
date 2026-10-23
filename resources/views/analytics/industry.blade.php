@use('App\Modules\Analytics\Support\Fmt')
<div class="space-y-6">
    <div>
        <a href="{{ route('industries.show', $template) }}" wire:navigate class="text-sm text-brand-600 hover:underline">← {{ $template->name }}</a>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $template->name }} dashboard</h1>
        <p class="mt-1 text-sm text-slate-500">This month so far. Each measure is worked out now from what you can see; ones with no data source yet say so.</p>
    </div>

    @if (! $enabled)
        <p class="card card-body text-sm text-slate-600">This industry is not switched on for your company, so it has no KPIs here.</p>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-testid="industry-kpis">
            @foreach ($tiles as $tile)
                @php
                    $kpi = $tile['kpi'];
                    $edge = ['green' => 'border-l-emerald-500', 'amber' => 'border-l-amber-500', 'red' => 'border-l-rose-500'][$tile['status'] ?? ''] ?? 'border-l-slate-200';
                @endphp
                <div class="card card-body border-l-4 {{ $edge }}" wire:key="kpi-{{ $kpi->id }}">
                    <p class="text-sm font-medium text-slate-500">{{ $kpi->name }}</p>
                    @if ($tile['measured'])
                        <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $tile['value'] === null ? '—' : rtrim(rtrim($tile['value'], '0'), '.') }} <span class="text-sm font-normal text-slate-500">{{ $kpi->unit }}</span></p>
                        @if ($tile['status'])
                            <span @class(['badge mt-1', 'bg-emerald-100 text-emerald-800' => $tile['status'] === 'green', 'bg-amber-100 text-amber-800' => $tile['status'] === 'amber', 'bg-rose-100 text-rose-800' => $tile['status'] === 'red'])>{{ ucfirst($tile['status']) }}</span>
                        @endif
                        @if ($tile['snapshot_at'])
                            <p class="mt-2 text-xs text-slate-400">Last snapshot {{ $tile['snapshot_at']->diffForHumans() }}: {{ $tile['snapshot'] === null ? '—' : rtrim(rtrim($tile['snapshot'], '0'), '.') }}</p>
                        @endif
                    @else
                        <p class="mt-2 text-lg font-medium text-slate-400">Not measured yet</p>
                        <p class="mt-1 text-xs text-slate-400">This needs data the system does not capture yet.</p>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            @if ($revenue !== [])
                <div class="card card-body">
                    <h2 class="text-sm font-semibold text-slate-900">Revenue this month</h2>
                    <p class="mt-2 text-2xl font-semibold">{{ Fmt::compact(collect($revenue)->firstWhere('key', $template->id)['amount'] ?? '0.00') }}</p>
                </div>
            @endif
            @if ($categories !== [])
                <div class="card">
                    <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Top loss categories</h2></div>
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach ($categories as $c)
                            <li class="flex justify-between px-4 py-2" wire:key="c-{{ $c['category_id'] }}"><span>{{ $c['category'] }}</span><span>{{ Fmt::inr($c['amount']) }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif
</div>

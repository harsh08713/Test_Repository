@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">Sales dashboard</h2>
            <p class="text-sm text-slate-500">{{ $periodLabel }}: {{ Format::date($data['period']['from']) }} to {{ Format::date($data['period']['to']) }}. Shows what you may see — your own work, your team’s, or the company’s.</p>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label for="d-period" class="block text-xs text-slate-500">Period</label>
                <select id="d-period" wire:model.live="period" class="input w-40">
                    @foreach ($periods as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </div>
            @if (count($branches) > 1)
                <div>
                    <label for="d-branch" class="block text-xs text-slate-500">Branch</label>
                    <select id="d-branch" wire:model.live="branch" class="input w-44">
                        <option value="">All my branches</option>
                        @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </div>
            @endif
            @if ($canReports)
                <a href="{{ route('reports.index') }}" wire:navigate class="btn-secondary">All reports</a>
            @endif
        </div>
    </div>

    {{-- headline figures: actual results first, then forecast, kept apart --}}
    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" aria-label="Headline figures">
        @if ($data['orders'])
            <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Approved sales, net of tax <span class="badge bg-emerald-100 text-emerald-800">Actual</span></dt><dd class="text-xl font-semibold tabular-nums text-slate-900" data-kpi="sales">₹ {{ Format::number($data['orders']['net'], 0) }}</dd><dd class="text-xs text-slate-500">{{ number_format($data['orders']['count']) }} approved orders</dd></div>
        @endif
        @if ($data['deals'])
            <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Open pipeline, weighted <span class="badge bg-amber-100 text-amber-800">Forecast</span></dt><dd class="text-xl font-semibold tabular-nums text-slate-900" data-kpi="forecast">₹ {{ Format::number($data['deals']['weighted'], 0) }}</dd><dd class="text-xs text-slate-500">{{ number_format($data['deals']['count']) }} open deals worth ₹ {{ Format::number($data['deals']['value'], 0) }}</dd></div>
        @endif
        @if ($data['followups'])
            <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Follow-ups</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ $data['followups']['today'] }} <span class="text-sm font-normal text-slate-500">still to do today</span></dd><dd class="text-xs {{ $data['followups']['overdue'] > 0 ? 'font-medium text-rose-700' : 'text-slate-500' }}">{{ $data['followups']['overdue'] }} overdue</dd></div>
        @endif
        @if ($data['tasks'])
            <a href="{{ route('workspace.tasks.index') }}" wire:navigate class="card px-5 py-3 transition hover:border-brand-500"><dt class="text-xs text-slate-500">My open tasks</dt><dd class="text-xl font-semibold tabular-nums text-slate-900" data-kpi="tasks">{{ $data['tasks']['mine'] }} <span class="text-sm font-normal text-slate-500">{{ $data['tasks']['due_today'] }} due today</span></dd><dd class="text-xs {{ $data['tasks']['mine_overdue'] > 0 ? 'font-medium text-rose-700' : 'text-slate-500' }}">{{ $data['tasks']['mine_overdue'] }} overdue{{ $data['tasks']['all_overdue'] > $data['tasks']['mine_overdue'] ? ' · '.$data['tasks']['all_overdue'].' on the team' : '' }}</dd></a>
        @endif
        @if ($data['sla'])
            <a href="{{ route('workspace.sla.breaches') }}" wire:navigate class="card px-5 py-3 transition hover:border-brand-500"><dt class="text-xs text-slate-500">Service levels met <span class="badge bg-emerald-100 text-emerald-800">Actual</span></dt><dd class="text-xl font-semibold tabular-nums text-slate-900" data-kpi="sla">{{ $data['sla']['percent'] === null ? '—' : $data['sla']['percent'].' %' }}</dd><dd class="text-xs text-slate-500">{{ $data['sla']['on_time'] }} on time · {{ $data['sla']['missed'] }} missed · {{ $data['sla']['running'] }} running</dd></a>
        @endif
        <a href="{{ route('approvals.index') }}" wire:navigate class="card px-5 py-3 transition hover:border-brand-500"><dt class="text-xs text-slate-500">Waiting for your approval</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ $data['approvals'] }}</dd></a>
    </dl>

    <div class="grid gap-5 lg:grid-cols-2">
        @if ($data['leads'])
            <section class="card" aria-labelledby="sec-stage">
                <div class="card-header"><h3 id="sec-stage" class="text-sm font-semibold text-slate-900">Leads by stage <span class="font-normal text-slate-500">({{ number_format($data['leads']['total']) }} made in the period)</span></h3></div>
                <div class="card-body">@include('reports.partials.bars', ['items' => $data['leads']['stages'], 'key' => 'stage'])</div>
            </section>
            <section class="card" aria-labelledby="sec-source">
                <div class="card-header"><h3 id="sec-source" class="text-sm font-semibold text-slate-900">Leads by source</h3></div>
                <div class="card-body">@include('reports.partials.bars', ['items' => $data['leads']['sources'], 'key' => 'source', 'color' => 'bg-sky-600'])</div>
            </section>
            <section class="card" aria-labelledby="sec-funnel">
                <div class="card-header"><h3 id="sec-funnel" class="text-sm font-semibold text-slate-900">Conversion funnel <span class="font-normal text-slate-500">(by the stage each lead is at today)</span></h3></div>
                <div class="card-body">@include('reports.partials.bars', ['items' => $data['leads']['funnel'], 'key' => 'funnel', 'color' => 'bg-emerald-600'])</div>
            </section>
        @endif

        @if ($data['quotes'])
            <section class="card" aria-labelledby="sec-quotes">
                <div class="card-header"><h3 id="sec-quotes" class="text-sm font-semibold text-slate-900">Quotation conversion <span class="font-normal text-slate-500">(quotations dated in the period)</span></h3></div>
                <div class="card-body">
                    @include('reports.partials.bars', ['items' => [
                        ['label' => 'Sent to customers', 'value' => $data['quotes']['sent']],
                        ['label' => 'Accepted', 'value' => $data['quotes']['accepted']],
                        ['label' => 'Became sales orders', 'value' => $data['quotes']['ordered']],
                    ], 'key' => 'quote', 'color' => 'bg-violet-600'])
                    <p class="mt-3 text-sm text-slate-600">
                        @if ($data['quotes']['sent'] > 0)
                            {{ number_format(100 * $data['quotes']['accepted'] / $data['quotes']['sent'], 1) }} % accepted · {{ number_format(100 * $data['quotes']['ordered'] / $data['quotes']['sent'], 1) }} % ordered
                        @else
                            No quotation was sent in this period.
                        @endif
                    </p>
                </div>
            </section>
        @endif

        @if ($data['followups'])
            <section class="card" aria-labelledby="sec-fu">
                <div class="card-header"><h3 id="sec-fu" class="text-sm font-semibold text-slate-900">Today’s and overdue follow-ups</h3></div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($data['followups']['next'] as $i => $f)
                        <li class="flex items-center justify-between gap-3 px-5 py-2 text-sm" wire:key="fu-{{ $i }}">
                            <span class="truncate text-slate-800">{{ $f['what'] }} <span class="text-xs text-slate-500">· {{ $f['who'] }}</span></span>
                            <span @class(['whitespace-nowrap tabular-nums text-xs', 'font-medium text-rose-700' => $f['overdue'], 'text-slate-600' => ! $f['overdue']])>{{ $f['overdue'] ? 'Overdue · ' : '' }}{{ Format::dateTime($f['when']) }}</span>
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-slate-500">Nothing is due today and nothing is overdue.</li>
                    @endforelse
                </ul>
            </section>
        @endif

        @if ($data['targets'])
            <section class="card" aria-labelledby="sec-targets">
                <div class="card-header"><h3 id="sec-targets" class="text-sm font-semibold text-slate-900">Target vs achievement <span class="badge bg-emerald-100 text-emerald-800">Actual</span></h3></div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($data['targets']['rows'] as $i => $t)
                        <li class="px-5 py-2 text-sm" wire:key="tg-{{ $i }}">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="truncate text-slate-800">{{ $t['subject'] }} <span class="text-xs text-slate-500">· {{ $t['period'] }}, {{ $t['item_group'] }}</span></span>
                                <span class="tabular-nums font-medium text-slate-900">{{ $t['value_percent'] === null ? '—' : $t['value_percent'].' %' }}</span>
                            </div>
                            <div class="text-xs text-slate-500">₹ {{ Format::number($t['achieved_value'], 0) }} of {{ $t['target_value'] === null ? 'no value target' : '₹ '.Format::number($t['target_value'], 0) }}</div>
                            @if ($t['value_percent'] !== null)
                                <div class="mt-1 h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-emerald-600" style="width: {{ min(100, (float) $t['value_percent']) }}%"></div></div>
                            @endif
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-slate-500">No target is running now.</li>
                    @endforelse
                </ul>
            </section>
        @endif

        @if ($data['orders'])
            <section class="card" aria-labelledby="sec-people">
                <div class="card-header"><h3 id="sec-people" class="text-sm font-semibold text-slate-900">Top salespeople <span class="badge bg-emerald-100 text-emerald-800">Actual</span></h3></div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($data['orders']['people'] as $i => $p)
                        <li class="flex items-center justify-between gap-3 px-5 py-2 text-sm" wire:key="pp-{{ $i }}"><span class="text-slate-800">{{ $i + 1 }}. {{ $p['label'] }} <span class="text-xs text-slate-500">· {{ $p['orders'] }} orders</span></span><span class="tabular-nums font-medium text-slate-900">₹ {{ Format::number($p['value'], 0) }}</span></li>
                    @empty
                        <li class="px-5 py-4 text-sm text-slate-500">No approved order in this period.</li>
                    @endforelse
                </ul>
            </section>
            <section class="card" aria-labelledby="sec-teams">
                <div class="card-header"><h3 id="sec-teams" class="text-sm font-semibold text-slate-900">Top teams <span class="badge bg-emerald-100 text-emerald-800">Actual</span></h3></div>
                <ul class="divide-y divide-slate-100">
                    @forelse ($data['orders']['teams'] as $i => $p)
                        <li class="flex items-center justify-between gap-3 px-5 py-2 text-sm" wire:key="tm-{{ $i }}"><span class="text-slate-800">{{ $i + 1 }}. {{ $p['label'] }} <span class="text-xs text-slate-500">· {{ $p['orders'] }} orders</span></span><span class="tabular-nums font-medium text-slate-900">₹ {{ Format::number($p['value'], 0) }}</span></li>
                    @empty
                        <li class="px-5 py-4 text-sm text-slate-500">No approved order by a team member in this period.</li>
                    @endforelse
                </ul>
            </section>
        @endif

        @if ($data['deals'])
            <section class="card" aria-labelledby="sec-prob">
                <div class="card-header"><h3 id="sec-prob" class="text-sm font-semibold text-slate-900">Open deals by probability <span class="badge bg-amber-100 text-amber-800">Forecast</span></h3></div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Probability</th><th class="text-right">Deals</th><th class="text-right">Value (₹)</th><th class="text-right">Weighted (₹)</th></tr></thead>
                        <tbody>
                            @foreach ($data['deals']['bands'] as $b)
                                <tr wire:key="pb-{{ $loop->index }}"><td>{{ $b['label'] }}</td><td class="text-right tabular-nums">{{ $b['count'] }}</td><td class="text-right tabular-nums">{{ Format::number($b['value'], 0) }}</td><td class="text-right tabular-nums">{{ Format::number($b['weighted'], 0) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="card" aria-labelledby="sec-close">
                <div class="card-header"><h3 id="sec-close" class="text-sm font-semibold text-slate-900">Open deals by expected close date <span class="badge bg-amber-100 text-amber-800">Forecast</span></h3></div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Expected close</th><th class="text-right">Deals</th><th class="text-right">Value (₹)</th><th class="text-right">Weighted (₹)</th></tr></thead>
                        <tbody>
                            @foreach ($data['deals']['closes'] as $b)
                                <tr wire:key="cl-{{ $loop->index }}"><td>{{ $b['label'] }}</td><td class="text-right tabular-nums">{{ $b['count'] }}</td><td class="text-right tabular-nums">{{ Format::number($b['value'], 0) }}</td><td class="text-right tabular-nums">{{ Format::number($b['weighted'], 0) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>

    @if ($data['branches'] !== [])
        <section class="card" aria-labelledby="sec-branch">
            <div class="card-header"><h3 id="sec-branch" class="text-sm font-semibold text-slate-900">Branch performance</h3></div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Branch</th><th class="text-right">Leads made</th><th class="text-right">Open deals</th><th class="text-right">Approved sales, net of tax (₹)</th></tr></thead>
                    <tbody>
                        @foreach ($data['branches'] as $b)
                            <tr wire:key="br-{{ $loop->index }}"><td class="font-medium text-slate-900">{{ $b['label'] }}</td><td class="text-right tabular-nums">{{ $b['leads'] }}</td><td class="text-right tabular-nums">{{ $b['deals'] }}</td><td class="text-right tabular-nums">{{ Format::number($b['sales'], 0) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($data['campaigns'])
        <section class="card" aria-labelledby="sec-campaign">
            <div class="card-header"><h3 id="sec-campaign" class="text-sm font-semibold text-slate-900">Campaign attribution <span class="badge bg-emerald-100 text-emerald-800">Actual</span></h3><a href="{{ route('marketing.campaigns.index') }}" wire:navigate class="text-xs text-brand-700 hover:underline">All campaigns</a></div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Campaign</th><th class="text-right">People</th><th class="text-right">Responded</th><th class="text-right">Leads</th><th class="text-right">Orders</th><th class="text-right">Approved sales, net (₹)</th><th class="text-right">Budget (₹)</th></tr></thead>
                    <tbody>
                        @forelse ($data['campaigns']['rows'] as $c)
                            <tr wire:key="dc-{{ $c['id'] }}"><td><a href="{{ route('marketing.campaigns.show', $c['id']) }}" wire:navigate class="text-brand-700 hover:underline">{{ $c['name'] }}</a> <span class="font-mono text-xs text-slate-500">{{ $c['code'] }}</span></td><td class="text-right tabular-nums">{{ $c['people'] }}</td><td class="text-right tabular-nums">{{ $c['responded'] }}</td><td class="text-right tabular-nums">{{ $c['leads'] }}</td><td class="text-right tabular-nums">{{ $c['orders'] }}</td><td class="text-right tabular-nums">{{ Format::number($c['sales'], 0) }}</td><td class="text-right tabular-nums">{{ $c['budget'] === null ? '—' : Format::number($c['budget'], 0) }}</td></tr>
                        @empty
                            <tr><td colspan="7" class="py-6 text-center text-slate-500">No campaign has been launched yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="px-5 pb-3 text-xs text-slate-500">Leads and sales are credited to the campaign that wrote to the person or whose link they came through. Delivery and click figures need a message provider and are not shown.</p>
        </section>
    @endif
</div>

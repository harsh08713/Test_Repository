<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Reports</h1>
            <p class="mt-1 text-sm text-slate-500">Every report shows only what you may already see on its own screen: your records, your team’s, or the whole company’s, and the branches you work in. Results that are approved are kept apart from forecasts, and each report says which is which.</p>
        </div>
        @if ($canExport)
            <div class="flex gap-2">
                <a href="{{ route('reports.schedules') }}" wire:navigate class="btn-secondary">Scheduled reports</a>
                <a href="{{ route('reports.exports') }}" wire:navigate class="btn-secondary">My exports</a>
            </div>
        @endif
    </div>

    @forelse ($groups as $group => $reports)
        <section class="card" aria-labelledby="grp-{{ \Illuminate\Support\Str::slug($group) }}">
            <div class="card-header"><h2 id="grp-{{ \Illuminate\Support\Str::slug($group) }}" class="text-sm font-semibold text-slate-900">{{ $group }}</h2></div>
            <ul class="divide-y divide-slate-100">
                @foreach ($reports as $report)
                    <li wire:key="rep-{{ $report->key() }}" class="px-5 py-3">
                        <a href="{{ route('reports.run', $report->key()) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $report->title() }}</a>
                        <p class="mt-0.5 text-sm text-slate-500">{{ $report->description() }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <div class="card card-body text-sm text-slate-500">No reports are open to you yet. Ask an administrator to give you the right to view the records a report is about.</div>
    @endforelse

    <section class="card" aria-labelledby="grp-later">
        <div class="card-header"><h2 id="grp-later" class="text-sm font-semibold text-slate-900">Not built yet</h2></div>
        <ul class="divide-y divide-slate-100">
            @foreach ($planned as $item)
                <li class="px-5 py-3">
                    <span class="font-medium text-slate-700">{{ $item['title'] }}</span> <span class="badge bg-slate-100 text-slate-600">Phase {{ $item['phase'] }}</span>
                    <p class="mt-0.5 text-sm text-slate-500">{{ $item['why'] }}</p>
                </li>
            @endforeach
        </ul>
    </section>
</div>

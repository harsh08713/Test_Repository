@php
    $cards = [
        ['Headcount', $s['headcount'], 'People working here, including those serving notice.'],
        ['Serving notice', $s['on_notice'], 'Notice recorded, last day not yet come.'],
        ['Joined in the last '.$window.' days', $s['joiners'], 'By joining date.'],
        ['Left in the last '.$window.' days', $s['leavers'], 'By last working day.'],
    ];
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">HR dashboard</h1>
            <p class="mt-1 text-sm text-slate-500">People, joiners and leavers, and what needs HR’s attention. Counted from the employee files you may see.</p>
        </div>
        <div class="flex gap-2">
            @can('employee.view')<a href="{{ route('hr.employees.index') }}" wire:navigate class="btn-secondary">Employees</a>@endcan
            <a href="{{ route('hr.org-chart') }}" wire:navigate class="btn-secondary">Org chart</a>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="hr-cards">
        @foreach ($cards as [$label, $value, $help])
            <div class="card card-body" title="{{ $help }}">
                <p class="text-sm font-medium text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        @foreach ([['By department', $s['by_department']], ['By branch', $s['by_branch']], ['By employment type', $s['by_type']]] as [$title, $rows])
            <div class="card">
                <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">{{ $title }}</h2></div>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse ($rows as $row)
                        <li class="flex justify-between px-4 py-2"><span>{{ $row['name'] }}</span><span class="font-medium tabular-nums">{{ $row['count'] }}</span></li>
                    @empty
                        <li class="px-4 py-6 text-center text-slate-500">Nobody yet.</li>
                    @endforelse
                </ul>
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Birthdays in the next {{ $window }} days</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($s['birthdays'] as $b)
                    <li class="flex justify-between px-4 py-2" wire:key="bd-{{ $b['id'] }}-{{ $b['on'] }}"><a href="{{ route('hr.employees.show', $b['id']) }}" wire:navigate class="-my-1.5 py-1.5 text-brand-700 hover:underline">{{ $b['name'] }}</a><span class="text-slate-500">{{ \Illuminate\Support\Carbon::parse($b['on'])->format('d M') }}</span></li>
                @empty
                    <li class="px-4 py-6 text-center text-slate-500">None.</li>
                @endforelse
            </ul>
        </div>
        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Work anniversaries</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($s['anniversaries'] as $a)
                    <li class="flex justify-between px-4 py-2" wire:key="an-{{ $a['id'] }}-{{ $a['on'] }}"><a href="{{ route('hr.employees.show', $a['id']) }}" wire:navigate class="-my-1.5 py-1.5 text-brand-700 hover:underline">{{ $a['name'] }}</a><span class="text-slate-500">{{ $a['years'] }} {{ $a['years'] === 1 ? 'year' : 'years' }} · {{ \Illuminate\Support\Carbon::parse($a['on'])->format('d M') }}</span></li>
                @empty
                    <li class="px-4 py-6 text-center text-slate-500">None.</li>
                @endforelse
            </ul>
        </div>
        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Probation ending</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($s['probation_ending'] as $p)
                    <li class="flex justify-between px-4 py-2" wire:key="pr-{{ $p['id'] }}"><a href="{{ route('hr.employees.show', $p['id']) }}" wire:navigate class="-my-1.5 py-1.5 text-brand-700 hover:underline">{{ $p['name'] }}</a><span class="text-slate-500">{{ \Illuminate\Support\Carbon::parse($p['on'])->format('d M Y') }}</span></li>
                @empty
                    <li class="px-4 py-6 text-center text-slate-500">Nobody.</li>
                @endforelse
            </ul>
        </div>
    </div>

    @if ($s['orphans'] > 0 || $s['documents_due'] !== null)
        <div class="grid gap-4 lg:grid-cols-2">
            @if ($s['orphans'] > 0)
                <div class="card card-body border-l-4 border-l-amber-500">
                    <h2 class="text-sm font-semibold text-slate-900">Reporting to someone who has left</h2>
                    <p class="mt-1 text-sm text-slate-600">{{ $s['orphans'] }} {{ $s['orphans'] === 1 ? 'person reports' : 'people report' }} to a manager who is no longer with the company. Give them a new manager with “Change job”.</p>
                </div>
            @endif
            @if ($s['documents_due'] !== null)
                <div class="card">
                    <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Papers expired or expiring in {{ $window }} days</h2></div>
                    <ul class="divide-y divide-slate-100 text-sm">
                        @forelse ($s['documents_due'] as $d)
                            <li class="flex justify-between px-4 py-2" wire:key="dd-{{ $d['id'] }}">
                                <span><a href="{{ route('hr.employees.show', ['employee' => $d['employee_id'], 'tab' => 'papers']) }}" wire:navigate class="text-brand-700 hover:underline">{{ $d['employee'] }}</a> <span class="text-slate-500">· {{ $d['title'] }}</span></span>
                                <span @class(['badge', 'bg-rose-100 text-rose-800' => $d['expired'], 'bg-amber-100 text-amber-800' => ! $d['expired']])>{{ \Illuminate\Support\Carbon::parse($d['on'])->format('d M Y') }}</span>
                            </li>
                        @empty
                            <li class="px-4 py-6 text-center text-slate-500">Nothing is due.</li>
                        @endforelse
                    </ul>
                </div>
            @endif
        </div>
    @endif
</div>

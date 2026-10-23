@php use App\Modules\Maintenance\Services\MaintenanceFigures; use App\Modules\Shared\Support\Format; @endphp
<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('maintenance.assets.index') }}" wire:navigate class="hover:text-brand-700">Assets</a> › {{ $asset->location?->name }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $asset->name }} <span class="font-mono text-base font-normal text-slate-500">{{ $asset->code }}</span></h1>
            <p class="mt-1 text-sm text-slate-600">{{ $categories[$asset->category] }}{{ $asset->make_model ? ' · '.$asset->make_model : '' }}{{ $asset->serial_no ? ' · serial '.$asset->serial_no : '' }}{{ $asset->installed_on ? ' · installed '.$asset->installed_on->format('d-m-Y') : '' }} · {{ $criticality[$asset->criticality] }}{{ $asset->costCenter ? ' · '.$asset->costCenter->name : '' }}
                @if ($asset->status !== 'active') <span class="badge bg-slate-100 text-slate-700">Inactive</span>@endif</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canReport)<a href="{{ route('maintenance.orders.create', ['asset' => $asset->id, 'type' => 'breakdown']) }}" wire:navigate class="btn-primary">Report a breakdown</a>@endif
            @if ($canEdit)<a href="{{ route('maintenance.assets.edit', $asset) }}" wire:navigate class="btn-secondary">Edit</a><button type="button" wire:click="toggle" class="btn-ghost">{{ $asset->status === 'active' ? 'Make inactive' : 'Make active' }}</button>@endif
        </div>
    </div>

    <x-status />
    @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <section class="grid gap-4 sm:grid-cols-4" aria-label="The last twelve months">
        <div class="card card-body"><p class="text-xs text-slate-500">Breakdowns (12 months)</p><p class="text-xl font-semibold tabular-nums text-slate-900">{{ $f['breakdowns'] }}</p></div>
        <div class="card card-body"><p class="text-xs text-slate-500">Downtime</p><p class="text-xl font-semibold tabular-nums text-slate-900">{{ MaintenanceFigures::duration($f['downtime_minutes']) }}</p></div>
        <div class="card card-body"><p class="text-xs text-slate-500">Mean time to repair</p><p class="text-xl font-semibold tabular-nums text-slate-900">{{ MaintenanceFigures::duration($f['mttr_minutes']) }}</p></div>
        <div class="card card-body"><p class="text-xs text-slate-500">Hours worked · parts</p><p class="text-xl font-semibold tabular-nums text-slate-900">{{ rtrim(rtrim($f['labour_hours'], '0'), '.') ?: '0' }} h · ₹ {{ Format::number($f['parts_value'], 2) }}</p></div>
    </section>

    <section class="card" aria-labelledby="s-plans">
        <div class="card-header"><h2 id="s-plans" class="text-sm font-semibold text-slate-900">Preventive schedules</h2></div>
        <ul class="divide-y divide-slate-100 text-sm">
            @forelse ($asset->plans as $p)
                <li class="flex flex-wrap justify-between gap-2 px-5 py-2.5" wire:key="ap-{{ $p->id }}"><span><span class="font-mono text-xs text-slate-500">{{ $p->code }}</span> {{ $p->name }} <span class="text-slate-500">· {{ $p->everyLabel() }}</span>@if ($p->status !== 'active') <span class="badge bg-slate-100 text-slate-700">Inactive</span>@endif</span><span class="tabular-nums text-slate-600">next {{ $p->next_due_on->format('d-m-Y') }}</span></li>
            @empty
                <li class="px-5 py-3 text-slate-500">No schedules yet.</li>
            @endforelse
        </ul>
    </section>

    <section class="card" aria-labelledby="s-orders">
        <div class="card-header"><h2 id="s-orders" class="text-sm font-semibold text-slate-900">Work orders</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Order</th><th>What</th><th>Type</th><th>Due</th><th>State</th></tr></thead>
                <tbody>
                    @forelse ($orders as $o)
                        <tr wire:key="ao-{{ $o->id }}">
                            <td><a href="{{ route('maintenance.orders.show', $o) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $o->order_number }}</a></td>
                            <td class="font-medium text-slate-900">{{ $o->title }}</td>
                            <td>{{ $types[$o->type] }}</td>
                            <td class="whitespace-nowrap tabular-nums text-slate-600">{{ $o->due_on?->format('d-m-Y') ?? '—' }}@if ($o->isOverdue()) <span class="text-xs font-medium text-rose-700">overdue</span>@endif</td>
                            <td>{{ $states[$o->state] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-slate-500">No work orders yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Maintenance work orders</h1>
            <p class="mt-1 text-sm text-slate-500">Breakdowns to fix, scheduled jobs and corrective jobs. Preventive ones are made by the schedules a few days before they fall due.</p>
        </div>
        @if ($canCreate)<a href="{{ route('maintenance.orders.create') }}" wire:navigate class="btn-primary">Report or plan work</a>@endif
    </div>

    <x-status />

    <div class="card">
        <div class="card-header flex flex-wrap items-center gap-3">
            <label for="wo-q" class="sr-only">Search</label><input id="wo-q" type="search" wire:model.live.debounce.400ms="search" placeholder="Number, job or asset" class="input w-64" maxlength="80">
            <label for="wo-st" class="sr-only">State</label>
            <select id="wo-st" wire:model.live="state" class="input w-44"><option value="">Every state</option><option value="open_work">Not finished</option>@foreach ($states as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>
            <label for="wo-ty" class="sr-only">Type</label>
            <select id="wo-ty" wire:model.live="type" class="input w-44"><option value="">Every type</option>@foreach ($types as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>
            <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model.live="mine" class="checkbox"> Assigned to me</label>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Order</th><th>Asset</th><th>What</th><th>Type</th><th>Priority</th><th>Assigned</th><th>Due</th><th>State</th></tr></thead>
                <tbody>
                    @forelse ($orders as $o)
                        <tr wire:key="wo-{{ $o->id }}">
                            <td><a href="{{ route('maintenance.orders.show', $o) }}" wire:navigate class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ $o->order_number }}</a></td>
                            <td>{{ $o->asset?->name }}</td>
                            <td class="font-medium text-slate-900">{{ $o->title }}</td>
                            <td>{{ $types[$o->type] }}</td>
                            <td><span @class(['badge', 'bg-slate-100 text-slate-700' => in_array($o->priority, ['low', 'normal'], true), 'bg-amber-100 text-amber-800' => $o->priority === 'high', 'bg-rose-100 text-rose-800' => $o->priority === 'urgent'])>{{ $priorities[$o->priority] }}</span></td>
                            <td>{{ $o->assignee?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap tabular-nums {{ $o->isOverdue() ? 'font-medium text-rose-700' : 'text-slate-600' }}">{{ $o->due_on?->format('d-m-Y') ?? '—' }}{{ $o->isOverdue() ? ' overdue' : '' }}</td>
                            <td><span @class(['badge', 'bg-amber-100 text-amber-800' => in_array($o->state, ['open', 'in_progress'], true), 'bg-emerald-100 text-emerald-800' => $o->state === 'completed', 'bg-slate-100 text-slate-700' => $o->state === 'cancelled'])>{{ $states[$o->state] }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-slate-500">No work orders yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3">{{ $orders->links() }}</div>
    </div>
</div>

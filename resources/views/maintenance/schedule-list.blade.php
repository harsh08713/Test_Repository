<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Preventive schedules</h1>
            <p class="mt-1 text-sm text-slate-500">Jobs done to an asset every so many days, weeks or months. A work order is made a few days before each falls due, and the schedule moves on from the day the job is done.</p>
        </div>
        @if ($canCreate)<a href="{{ route('maintenance.schedules.create') }}" wire:navigate class="btn-primary">New schedule</a>@endif
    </div>

    <x-status />
    @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <div class="card">
        <div class="card-header"><label for="sc-q" class="sr-only">Search</label><input id="sc-q" type="search" wire:model.live.debounce.400ms="search" placeholder="Code, job or asset" class="input w-64" maxlength="80"></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Job</th><th>Asset</th><th>How often</th><th>Next due</th><th>Last done</th><th>State</th><th></th></tr></thead>
                <tbody>
                    @forelse ($plans as $p)
                        <tr wire:key="sc-{{ $p->id }}">
                            <td class="font-mono text-xs text-slate-600">{{ $p->code }}</td>
                            <td class="font-medium text-slate-900">{{ $p->name }}</td>
                            <td>{{ $p->asset?->name }}</td>
                            <td>{{ ucfirst($p->everyLabel()) }}</td>
                            <td class="whitespace-nowrap tabular-nums {{ $p->status === 'active' && $p->next_due_on->lt(now()->startOfDay()) ? 'font-medium text-rose-700' : 'text-slate-600' }}">{{ $p->next_due_on->format('d-m-Y') }}</td>
                            <td class="whitespace-nowrap tabular-nums text-slate-600">{{ $p->last_done_on?->format('d-m-Y') ?? '—' }}</td>
                            <td>{{ $p->status === 'active' ? 'On' : 'Off' }}</td>
                            <td class="whitespace-nowrap text-right">@if ($canEdit)<a href="{{ route('maintenance.schedules.edit', $p) }}" wire:navigate class="text-xs text-brand-700 hover:underline">Edit</a> <button type="button" wire:click="toggle({{ $p->id }})" class="ml-2 text-xs text-slate-600 hover:underline">{{ $p->status === 'active' ? 'Switch off' : 'Switch on' }}</button>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-slate-500">No preventive schedules yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3">{{ $plans->links() }}</div>
    </div>
</div>

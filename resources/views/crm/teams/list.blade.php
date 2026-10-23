<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Sales teams</h1>
            <p class="mt-1 text-sm text-slate-500">A team is a manager and the salespeople under them. A manager plans the days and sets the targets of the people on their teams; a person is on one team at a time.</p>
        </div>
        @if ($canCreate)
            <a href="{{ route('field.teams.create') }}" wire:navigate class="btn-primary">Add team</a>
        @endif
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="t-search" class="sr-only">Search</label>
                <input id="t-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search code or name…" class="input max-w-xs">

                <label for="t-status" class="sr-only">Status</label>
                <select id="t-status" wire:model.live="status" class="input w-40">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Team</th><th>Manager</th><th class="text-right">On the team now</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($teams as $team)
                        <tr wire:key="team-{{ $team->id }}">
                            <td class="font-mono text-xs">{{ $team->code }}</td>
                            <td><a href="{{ route('field.teams.show', $team) }}" wire:navigate class="font-medium text-slate-900 hover:text-brand-700">{{ $team->name }}</a></td>
                            <td class="text-slate-600">{{ $team->manager?->name }}</td>
                            <td class="text-right tabular-nums">{{ $team->current_members_count }}</td>
                            <td><span class="badge {{ $team->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($team->status) }}</span></td>
                            <td class="whitespace-nowrap text-right">
                                <a href="{{ route('field.teams.show', $team) }}" wire:navigate class="btn-ghost btn-sm">View</a>
                                @can('update', $team)
                                    <a href="{{ route('field.teams.edit', $team) }}" wire:navigate class="btn-ghost btn-sm">Edit</a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-slate-500">No teams yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($teams->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $teams->links() }}</div>
        @endif
    </div>
</div>
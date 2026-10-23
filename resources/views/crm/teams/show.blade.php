<div class="mx-auto max-w-4xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('field.teams.index') }}" wire:navigate class="hover:text-brand-700">Sales teams</a> › {{ $team->code }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $team->name }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="badge {{ $team->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($team->status) }}</span>
                <span class="text-sm text-slate-600">Managed by <strong class="text-slate-900">{{ $team->manager?->name }}</strong></span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canEdit)<a href="{{ route('field.teams.edit', $team) }}" wire:navigate class="btn-primary">Edit</a>@endif
            @if ($canDelete)<button type="button" wire:click="delete" wire:confirm="Delete this team?" class="btn-ghost text-rose-600">Delete</button>@endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif
    @error('delete')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('members')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if ($team->remarks)
        <p class="text-sm text-slate-600">{{ $team->remarks }}</p>
    @endif

    <section class="card" aria-labelledby="s-members">
        <div class="card-header">
            <h2 id="s-members" class="text-sm font-semibold text-slate-900">People on the team</h2>
            <span class="text-xs text-slate-500">Leaving ends the membership; nothing is deleted, so past months stay answerable</span>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Person</th><th>Joined</th><th>Left</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($team->members as $member)
                        <tr wire:key="m-{{ $member->id }}" @class(['text-slate-500' => $member->left_on !== null])>
                            <td class="font-medium {{ $member->left_on === null ? 'text-slate-900' : '' }}">{{ $member->user?->name }}</td>
                            <td>{{ $member->joined_on->format('j M Y') }}</td>
                            <td>{{ $member->left_on?->format('j M Y') ?? '—' }}</td>
                            <td class="text-right">
                                @if ($canManage && $member->left_on === null)
                                    <button type="button" wire:click="removeMember({{ $member->id }})" wire:confirm="Take {{ $member->user?->name }} off this team as of today?" class="btn-ghost btn-sm text-rose-600">Take off the team</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-8 text-center text-slate-500">Nobody has been added yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($canManage)
            <form wire:submit="addMember" class="flex flex-wrap items-end gap-3 border-t border-slate-200 px-5 py-4" novalidate>
                <div>
                    <label for="m-person" class="label">Add a person</label>
                    <select id="m-person" wire:model="newMember" class="input w-64">
                        <option value="">Select…</option>
                        @foreach ($candidates as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="m-from" class="label">From</label>
                    <input id="m-from" type="date" wire:model="joinedOn" max="{{ $today }}" class="input w-44">
                </div>
                <button type="submit" class="btn-primary" wire:loading.attr="disabled">Add to the team</button>
                @error('newMember') <p class="error basis-full">{{ $message }}</p> @enderror
            </form>
        @endif
    </section>
</div>
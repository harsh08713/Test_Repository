<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Review cycles</h1>
            <p class="mt-1 text-sm text-slate-500">A period over which goals are set and people are reviewed. Opening a cycle makes one review for everyone who had joined by its end.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('hr.team-performance') }}" wire:navigate class="btn-secondary">Team performance</a>
            @if ($canCreate)<button type="button" wire:click="create" class="btn-primary">New cycle</button>@endif
        </div>
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('state')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if ($formOpen)
        <form wire:submit="save" class="card" novalidate>
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">{{ $editingId ? 'Change the cycle' : 'New cycle' }}</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-4">
                <div><label for="rc-code" class="label">Code</label><input id="rc-code" type="text" wire:model="f.code" class="input font-mono" maxlength="30">@error('code')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="sm:col-span-3"><label for="rc-name" class="label">Name</label><input id="rc-name" type="text" wire:model="f.name" class="input" maxlength="100">@error('name')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="rc-from" class="label">From</label><input id="rc-from" type="date" wire:model="f.period_start" class="input"></div>
                <div><label for="rc-to" class="label">To</label><input id="rc-to" type="date" wire:model="f.period_end" class="input">@error('period_end')<p class="error">{{ $message }}</p>@enderror</div>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><button type="button" wire:click="cancel" class="btn-secondary">Cancel</button><button type="submit" class="btn-primary">Save</button></div>
        </form>
    @endif

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Cycle</th><th>Period</th><th class="text-right">Reviews</th><th class="text-right">Final</th><th>State</th><th></th></tr></thead>
                <tbody>
                    @forelse ($cycles as $c)
                        <tr wire:key="cy-{{ $c->id }}">
                            <td><span class="font-medium text-slate-900">{{ $c->name }}</span> <span class="font-mono text-xs text-slate-400">{{ $c->code }}</span></td>
                            <td>{{ $c->period_start->format('d M Y') }} – {{ $c->period_end->format('d M Y') }}</td>
                            <td class="text-right tabular-nums">{{ $total[$c->id] ?? 0 }}</td>
                            <td class="text-right tabular-nums">{{ $final[$c->id] ?? 0 }}</td>
                            <td><span @class(['badge', 'bg-emerald-100 text-emerald-800' => $c->state === 'open', 'bg-amber-100 text-amber-800' => $c->state === 'draft', 'bg-slate-100 text-slate-600' => $c->state === 'closed'])>{{ $states[$c->state] }}</span></td>
                            <td class="whitespace-nowrap text-right">
                                @if ($canEdit)
                                    @if ($c->state === 'draft')<button type="button" wire:click="edit({{ $c->id }})" class="text-xs text-brand-700 hover:underline">Edit</button> <button type="button" wire:click="openIt({{ $c->id }})" wire:confirm="Open this cycle and make a review for everyone?" class="btn-primary btn-sm">Open</button>@endif
                                    @if ($c->state === 'open')<button type="button" wire:click="closeIt({{ $c->id }})" wire:confirm="Close this cycle? Unfinished reviews stay as they are." class="btn-secondary btn-sm">Close</button>@endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-slate-500">No review cycle yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

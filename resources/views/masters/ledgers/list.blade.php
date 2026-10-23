<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Ledgers</h1>
            <p class="mt-1 text-sm text-slate-500">Customers, suppliers, banks, cash, expense and general accounts. Only approved, active ledgers can be used in transactions.</p>
        </div>
        @if ($creatable !== [])
            <div x-data="{ open: false }" class="relative">
                <button type="button" x-on:click="open = ! open" x-on:keydown.escape.window="open = false" class="btn-primary" aria-haspopup="true" x-bind:aria-expanded="open">Add ledger</button>
                <div x-show="open" x-cloak x-on:click.outside="open = false" class="absolute right-0 z-20 mt-2 w-48 rounded-lg border border-slate-200 bg-white py-1 shadow-lg" role="menu">
                    @foreach ($creatable as $kind)
                        <a href="{{ route('masters.ledgers.create', $kind->value) }}" wire:navigate class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" role="menuitem">{{ $kind->label() }}</a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif

    <nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Ledger type">
        @foreach ([['', 'All'], ...array_map(fn ($t) => [$t->value, $t->plural()], $types)] as [$value, $label])
            <button type="button" wire:click="$set('type', '{{ $value }}')" wire:key="tab-{{ $value ?: 'all' }}"
                    @class([
                        'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
                        'border-brand-600 text-brand-700' => $type === $value,
                        'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $type !== $value,
                    ])
                    @if ($type === $value) aria-current="true" @endif>{{ $label }}</button>
        @endforeach
    </nav>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="l-search" class="sr-only">Search</label>
                <input id="l-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search code, name or GSTIN…" class="input max-w-xs">

                <label for="l-approval" class="sr-only">Approval</label>
                <select id="l-approval" wire:model.live="approval" class="input w-48">
                    <option value="">Any approval state</option>
                    @foreach ($approvalStatuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
                </select>

                <label for="l-status" class="sr-only">Status</label>
                <select id="l-status" wire:model.live="status" class="input w-40">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Code</th><th>Name</th><th>Type</th><th>Group</th><th>GSTIN</th><th>Approval</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($ledgers as $ledger)
                        <tr wire:key="ledger-{{ $ledger->id }}">
                            <td class="font-mono text-xs">{{ $ledger->code }}</td>
                            <td>
                                <a href="{{ route('masters.ledgers.show', $ledger) }}" wire:navigate class="font-medium text-slate-900 hover:text-brand-700">{{ $ledger->name }}</a>
                                @if ($ledger->legal_name && $ledger->legal_name !== $ledger->name)<div class="text-xs text-slate-500">{{ $ledger->legal_name }}</div>@endif
                            </td>
                            <td>{{ $ledger->ledger_type->label() }}</td>
                            <td class="text-slate-600">{{ $ledger->accountGroup?->name }}</td>
                            <td class="font-mono text-xs">{{ $ledger->gstin }}</td>
                            <td><span class="badge {{ $ledger->approval_status->badge() }}">{{ $ledger->approval_status->label() }}</span></td>
                            <td><span class="badge {{ $ledger->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($ledger->status) }}</span></td>
                            <td class="whitespace-nowrap text-right">
                                <a href="{{ route('masters.ledgers.show', $ledger) }}" wire:navigate class="btn-ghost btn-sm">View</a>
                                @can('update', $ledger)
                                    <a href="{{ route('masters.ledgers.edit', $ledger) }}" wire:navigate class="btn-ghost btn-sm">Edit</a>
                                @endcan
                                @can('changeStatus', $ledger)
                                    <button type="button" wire:click="toggleStatus({{ $ledger->id }})" class="btn-ghost btn-sm">{{ $ledger->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-10 text-center text-slate-500">No ledgers match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($ledgers->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $ledgers->links() }}</div>
        @endif
    </div>
</div>

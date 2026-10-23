<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Items</h1>
            <p class="mt-1 text-sm text-slate-500">Purchase starts an item, Inventory completes it, Accounts approves it. Only approved, active items can be used in transactions.</p>
        </div>
        @if ($canCreate)
            <a href="{{ route('masters.items.create') }}" wire:navigate class="btn-primary">Add item</a>
        @endif
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif

    <nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Stage">
        @foreach ([['', 'All'], ...array_map(fn ($s) => [$s->value, $s->label()], $stages), ['returned', 'Returned']] as [$value, $label])
            <button type="button" wire:click="$set('stage', '{{ $value }}')" wire:key="tab-{{ $value ?: 'all' }}"
                    @class([
                        'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
                        'border-brand-600 text-brand-700' => $stage === $value,
                        'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $stage !== $value,
                    ])
                    @if ($stage === $value) aria-current="true" @endif>{{ $label }}</button>
        @endforeach
    </nav>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="i-search" class="sr-only">Search</label>
                <input id="i-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search code, name, alias or HSN…" class="input max-w-xs">

                <label for="i-group" class="sr-only">Item group</label>
                <select id="i-group" wire:model.live="group" class="input w-52">
                    <option value="">All item groups</option>
                    @foreach ($groups as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                <label for="i-status" class="sr-only">Status</label>
                <select id="i-status" wire:model.live="status" class="input w-40">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Code</th><th>Name</th><th>Group</th><th>Base unit</th><th>HSN</th><th class="text-right">GST</th><th>Stage</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        <tr wire:key="item-{{ $item->id }}">
                            <td class="font-mono text-xs">{{ $item->code ?? '—' }}</td>
                            <td><a href="{{ route('masters.items.show', $item) }}" wire:navigate class="font-medium text-slate-900 hover:text-brand-700">{{ $item->name }}</a></td>
                            <td class="text-slate-600">{{ $item->itemGroup?->name }}</td>
                            <td>{{ $item->baseUnit?->code }}</td>
                            <td class="font-mono text-xs">{{ $item->hsn_code }}</td>
                            <td class="text-right tabular-nums">{{ $item->gst_rate !== null ? rtrim(rtrim($item->gst_rate, '0'), '.').'%' : '' }}</td>
                            <td>
                                <span class="badge {{ $item->isReturned() ? 'bg-rose-100 text-rose-800' : $item->workflow_stage->badge() }}">{{ $item->stageLabel() }}</span>
                            </td>
                            <td><span class="badge {{ $item->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($item->status) }}</span></td>
                            <td class="whitespace-nowrap text-right">
                                <a href="{{ route('masters.items.show', $item) }}" wire:navigate class="btn-ghost btn-sm">View</a>
                                @can('update', $item)
                                    <a href="{{ route('masters.items.edit', $item) }}" wire:navigate class="btn-ghost btn-sm">Edit</a>
                                @endcan
                                @can('changeStatus', $item)
                                    <button type="button" wire:click="toggleStatus({{ $item->id }})" class="btn-ghost btn-sm">{{ $item->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-10 text-center text-slate-500">No items match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($items->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $items->links() }}</div>
        @endif
    </div>
</div>

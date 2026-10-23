<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Rates</h1>
            <p class="mt-1 text-sm text-slate-500">What one unit of an item costs at a branch, for a period. Only approved, active rates in force on the day are ever offered to a quotation or order. Rates are exclusive of GST.</p>
        </div>
        @if ($canCreate)
            <a href="{{ route('masters.rates.create') }}" wire:navigate class="btn-primary">Add rate</a>
        @endif
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="r-search" class="sr-only">Search</label>
                <input id="r-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search item code, name or alias…" class="input max-w-xs">

                <label for="r-branch" class="sr-only">Branch</label>
                <select id="r-branch" wire:model.live="branch" class="input w-48">
                    <option value="">All branches</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                <label for="r-list" class="sr-only">Price list</label>
                <select id="r-list" wire:model.live="list" class="input w-44">
                    <option value="">All price lists</option>
                    <option value="standard">Standard (no list)</option>
                    @foreach ($priceLists as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                <label for="r-approval" class="sr-only">Approval</label>
                <select id="r-approval" wire:model.live="approval" class="input w-40">
                    <option value="">Any approval state</option>
                    @foreach ($approvals as $state)<option value="{{ $state->value }}">{{ $state->label() }}</option>@endforeach
                </select>

                <label for="r-status" class="sr-only">Status</label>
                <select id="r-status" wire:model.live="status" class="input w-36">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>

                <label for="r-on" class="text-sm text-slate-600">In force on</label>
                <input id="r-on" type="date" wire:model.live="on" class="input w-40">
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Item</th><th>Branch</th><th>Unit</th><th>Price list</th><th class="text-right">Rate (₹)</th><th>Period</th><th>Approval</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($rates as $rate)
                        @php $state = $rate->periodState($today); @endphp
                        <tr wire:key="rate-{{ $rate->id }}">
                            <td>
                                <a href="{{ route('masters.rates.show', $rate) }}" wire:navigate class="font-medium text-slate-900 hover:text-brand-700">{{ $rate->item?->name }}</a>
                                @if ($rate->item?->code)<div class="font-mono text-xs text-slate-500">{{ $rate->item->code }}</div>@endif
                            </td>
                            <td class="text-slate-600">{{ $rate->location?->name }}</td>
                            <td>{{ $rate->unit?->code }}</td>
                            <td class="text-slate-600">{{ $rate->priceList?->name ?? 'Standard' }}</td>
                            <td class="text-right tabular-nums">{{ $rate->rateLabel() }}</td>
                            <td class="whitespace-nowrap text-slate-600">
                                {{ $rate->periodLabel() }}
                                @if ($rate->isApproved() && $rate->status === 'active')
                                    <span @class(['badge ml-1', 'bg-emerald-100 text-emerald-800' => $state === 'current', 'bg-sky-100 text-sky-800' => $state === 'upcoming', 'bg-slate-100 text-slate-600' => $state === 'expired'])>{{ ucfirst($state) }}</span>
                                @endif
                            </td>
                            <td><span class="badge {{ $rate->approval_status->badge() }}">{{ $rate->approval_status->label() }}</span></td>
                            <td><span class="badge {{ $rate->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($rate->status) }}</span></td>
                            <td class="whitespace-nowrap text-right">
                                <a href="{{ route('masters.rates.show', $rate) }}" wire:navigate class="btn-ghost btn-sm">View</a>
                                @can('update', $rate)
                                    <a href="{{ route('masters.rates.edit', $rate) }}" wire:navigate class="btn-ghost btn-sm">Edit</a>
                                @endcan
                                @can('changeStatus', $rate)
                                    <button type="button" wire:click="toggleStatus({{ $rate->id }})" class="btn-ghost btn-sm">{{ $rate->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-10 text-center text-slate-500">No rates match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rates->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $rates->links() }}</div>
        @endif
    </div>
</div>

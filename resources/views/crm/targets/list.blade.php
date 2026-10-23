<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Targets</h1>
            <p class="mt-1 text-sm text-slate-500">What each salesperson and team is aiming for, by month or quarter. Achievement is the net-of-tax value of approved sales orders credited to the person (see the field-sales guide).</p>
        </div>
        @if ($canCreate)
            <a href="{{ route('field.targets.create') }}" wire:navigate class="btn-primary">Set a target</a>
        @endif
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif
    @error('list')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="g-when" class="sr-only">Period</label>
                <select id="g-when" wire:model.live="when" class="input w-40">
                    <option value="current">Running now</option>
                    <option value="upcoming">Coming up</option>
                    <option value="past">Finished</option>
                    <option value="">All periods</option>
                </select>

                <label for="g-person" class="sr-only">Person</label>
                <select id="g-person" wire:model.live="person" class="input w-52">
                    <option value="">Everyone</option>
                    @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                <label for="g-group" class="sr-only">Item group</label>
                <select id="g-group" wire:model.live="group" class="input w-48">
                    <option value="">All item groups</option>
                    @foreach ($groups as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                <label for="g-branch" class="sr-only">Branch</label>
                <select id="g-branch" wire:model.live="branch" class="input w-44">
                    <option value="">All branches</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                <label for="g-status" class="sr-only">Status</label>
                <select id="g-status" wire:model.live="status" class="input w-36">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>For</th><th>Period</th><th>Scope</th><th class="text-right">Value (₹)</th><th class="text-right">Quantity</th><th>Progress</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($targets as $target)
                        @php $p = $progress[$target->id]; @endphp
                        <tr wire:key="target-{{ $target->id }}">
                            <td>
                                <span class="font-medium text-slate-900">{{ $target->subjectName() }}</span>
                                <div class="text-xs text-slate-500">{{ $target->user_id !== null ? 'Person' : 'Team' }}</div>
                            </td>
                            <td class="whitespace-nowrap">{{ $target->period_type->describe($target->period_start, $fyStart) }}</td>
                            <td class="text-slate-600">
                                {{ $target->itemGroup?->name ?? 'All items' }} · {{ $target->location?->name ?? 'All branches' }}
                                @if ($target->allow_overlap)<span class="badge bg-sky-100 text-sky-800">Overlap allowed</span>@endif
                            </td>
                            <td class="text-right tabular-nums">
                                @if ($target->target_value !== null)
                                    {{ \App\Modules\Shared\Support\Format::number($target->target_value) }}
                                    <div class="text-xs text-slate-500">done {{ \App\Modules\Shared\Support\Format::number($p->achievedValue) }}</div>
                                @else — @endif
                            </td>
                            <td class="text-right tabular-nums">
                                @if ($target->target_quantity !== null)
                                    {{ rtrim(rtrim($target->target_quantity, '0'), '.') }}
                                    <div class="text-xs text-slate-500">done {{ rtrim(rtrim($p->achievedQuantity, '0'), '.') ?: '0' }}</div>
                                @else — @endif
                            </td>
                            <td class="min-w-36">
                                @if (! $p->live)
                                    <span class="text-xs text-slate-500">No sales orders yet</span>
                                @else
                                    @foreach ([[$p->valuePercent, 'value'], [$p->quantityPercent, 'qty']] as [$percent, $kind])
                                        @if ($percent !== null)
                                            <div class="mb-1 flex items-center gap-2" title="{{ $kind === 'value' ? 'Value' : 'Quantity' }} {{ $percent }}%">
                                                <div class="h-2 w-24 overflow-hidden rounded bg-slate-100"><div class="h-2 {{ (float) $percent >= 100 ? 'bg-emerald-500' : 'bg-brand-500' }}" style="width: {{ min(100, (float) $percent) }}%"></div></div>
                                                <span class="text-xs tabular-nums text-slate-600">{{ $percent }}%</span>
                                            </div>
                                        @endif
                                    @endforeach
                                @endif
                            </td>
                            <td><span class="badge {{ $target->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $target->isOver($today) ? 'Finished' : ucfirst($target->status) }}</span></td>
                            <td class="whitespace-nowrap text-right">
                                @can('update', $target)
                                    <a href="{{ route('field.targets.edit', $target) }}" wire:navigate class="btn-ghost btn-sm">Edit</a>
                                @endcan
                                @can('delete', $target)
                                    <button type="button" wire:click="delete({{ $target->id }})" wire:confirm="Remove this target?" class="btn-ghost btn-sm text-rose-600">Remove</button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-10 text-center text-slate-500">No targets match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($targets->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $targets->links() }}</div>
        @endif
    </div>
</div>
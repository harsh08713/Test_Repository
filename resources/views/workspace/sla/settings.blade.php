@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">SLA policies</h1>
            <p class="mt-1 text-sm text-slate-500">How long a new lead, a planned follow-up or a task may wait before it is escalated. The most specific policy that matches wins. A policy applies to things made after it was added. Nothing is reassigned automatically: the person and, if you choose, their manager are told, and every breach is logged. Working days and hours are in <a href="{{ route('admin.settings') }}" wire:navigate class="text-brand-700 hover:underline">Company settings</a>.</p>
        </div>
        @if ($canCreate)<button type="button" wire:click="newPolicy" class="btn-primary">Add a policy</button>@endif
    </div>

    <x-status />

    @if ($formOpen)
        <form wire:submit="savePolicy" class="card" novalidate aria-label="Policy">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">{{ $editingId ? 'Change the policy' : 'New policy' }}</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="p-name" class="label">Name</label>
                    <input id="p-name" type="text" wire:model="p.name" class="input" maxlength="120">
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="p-kind" class="label">It limits</label>
                    <select id="p-kind" wire:model.live="p.kind" class="input">
                        @foreach ($kinds as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    @error('kind') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="p-minutes" class="label">Minutes allowed <span class="font-normal text-slate-400">({{ \App\Modules\Workspace\Enums\SlaKind::tryFrom($p['kind'] ?? '')?->counts() }})</span></label>
                    <input id="p-minutes" type="number" min="0" max="525600" wire:model="p.target_minutes" class="input">
                    @error('target_minutes') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col justify-end gap-2">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="p.business_hours" class="rounded border-slate-300"> Count working hours only (skip nights, days off and holidays)</label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="p.escalate_to_manager" class="rounded border-slate-300"> Tell the person’s team manager when it runs out</label>
                </div>
                @if (($p['kind'] ?? '') !== 'task')
                    <div>
                        <label for="p-source" class="label">Only for lead source <span class="font-normal text-slate-400">(any if empty)</span></label>
                        <select id="p-source" wire:model="p.lead_source_id" class="input">
                            <option value="">Any source</option>
                            @foreach ($sources as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                        @error('lead_source_id') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label for="p-priority" class="label">Only for priority <span class="font-normal text-slate-400">(any if empty)</span></label>
                        <select id="p-priority" wire:model="p.priority" class="input">
                            <option value="">Any priority</option>
                            @foreach ($priorities as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                        @error('priority') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label for="p-team" class="label">Only for team <span class="font-normal text-slate-400">(any if empty)</span></label>
                    <select id="p-team" wire:model="p.sales_team_id" class="input">
                        <option value="">Any team</option>
                        @foreach ($teams as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('sales_team_id') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                <button type="button" wire:click="closeForm" class="btn-secondary">Cancel</button>
                <button type="submit" class="btn-primary">Save the policy</button>
            </div>
        </form>
    @endif

    <section class="card" aria-labelledby="sec-pol">
        <div class="card-header"><h2 id="sec-pol" class="text-sm font-semibold text-slate-900">Policies</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Name</th><th>Limits</th><th>Applies to</th><th class="text-right">Minutes</th><th>Counted</th><th>Escalates</th><th>On</th><th></th></tr></thead>
                <tbody>
                    @forelse ($policies as $policy)
                        <tr wire:key="pol-{{ $policy->id }}">
                            <td class="font-medium text-slate-900">{{ $policy->name }}</td>
                            <td class="text-slate-700">{{ $policy->kind->short() }}</td>
                            <td class="text-sm text-slate-600">{{ collect([$policy->source?->name, $policy->team?->name, $policy->priority ? ucfirst($policy->priority).' priority' : null])->filter()->implode(' · ') ?: 'Everything of its kind' }}</td>
                            <td class="text-right tabular-nums">{{ number_format($policy->target_minutes) }}</td>
                            <td class="text-sm text-slate-600">{{ $policy->business_hours ? 'Working hours' : 'Round the clock' }}</td>
                            <td class="text-sm text-slate-600">{{ $policy->escalate_to_manager ? 'To the manager' : 'Person only' }}</td>
                            <td><span @class(['badge', 'bg-emerald-100 text-emerald-800' => $policy->status === 'active', 'bg-slate-100 text-slate-600' => $policy->status !== 'active'])>{{ $policy->status === 'active' ? 'On' : 'Off' }}</span></td>
                            <td class="whitespace-nowrap text-right">
                                @if ($canEdit)
                                    <button type="button" wire:click="editPolicy({{ $policy->id }})" class="btn-secondary">Change</button>
                                    <button type="button" wire:click="togglePolicy({{ $policy->id }})" class="btn-secondary">{{ $policy->status === 'active' ? 'Switch off' : 'Switch on' }}</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-10 text-center text-slate-500">No policy yet, so nothing is timed. Add one to start with first responses to new leads.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card" aria-labelledby="sec-hol">
        <div class="card-header"><h2 id="sec-hol" class="text-sm font-semibold text-slate-900">Holidays <span class="font-normal text-slate-500">(skipped by policies counted in working hours)</span></h2></div>
        @if ($canCreate)
            <form wire:submit="addHoliday" class="flex flex-wrap items-end gap-3 border-b border-slate-100 px-5 py-3" novalidate aria-label="Add a holiday">
                <div>
                    <label for="h-date" class="block text-xs text-slate-500">Day</label>
                    <input id="h-date" type="date" wire:model="h.holiday_date" class="input w-44">
                    @error('holiday_date') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="h-name" class="block text-xs text-slate-500">What it is</label>
                    <input id="h-name" type="text" wire:model="h.name" class="input w-64" maxlength="120">
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn-secondary">Add holiday</button>
            </form>
        @endif
        <ul class="divide-y divide-slate-100">
            @forelse ($holidays as $holiday)
                <li class="flex items-center justify-between px-5 py-2 text-sm" wire:key="hol-{{ $holiday->id }}">
                    <span class="text-slate-800">{{ Format::date($holiday->holiday_date) }} — {{ $holiday->name }}</span>
                    @can('delete', $holiday)<button type="button" wire:click="removeHoliday({{ $holiday->id }})" class="text-xs text-rose-700 hover:underline">Remove</button>@endcan
                </li>
            @empty
                <li class="px-5 py-4 text-sm text-slate-500">No holidays listed from last month onwards.</li>
            @endforelse
        </ul>
    </section>
</div>

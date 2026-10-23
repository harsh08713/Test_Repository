<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Employees</h1>
            <p class="mt-1 text-sm text-slate-500">The employee files you may see: everyone with HR rights, otherwise yourself and the people who report to you.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('hr.org-chart') }}" wire:navigate class="btn-secondary">Org chart</a>
            @if ($canCreate)<a href="{{ route('hr.employees.create') }}" wire:navigate class="btn-primary">Add employee</a>@endif
        </div>
    </div>

    <x-status />

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="e-q" class="sr-only">Search</label>
                <input id="e-q" type="search" wire:model.live.debounce.400ms="search" placeholder="Name, code, e-mail or mobile" class="input w-64" maxlength="80">
                <label for="e-dept" class="sr-only">Department</label>
                <select id="e-dept" wire:model.live="department" class="input w-48"><option value="">All departments</option>@foreach ($departments as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                <label for="e-branch" class="sr-only">Branch</label>
                <select id="e-branch" wire:model.live="branch" class="input w-44"><option value="">All branches</option>@foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                <label for="e-type" class="sr-only">Type</label>
                <select id="e-type" wire:model.live="type" class="input w-40"><option value="">Any type</option>@foreach ($types as $key => $name)<option value="{{ $key }}">{{ $name }}</option>@endforeach</select>
                <label for="e-state" class="sr-only">State</label>
                <select id="e-state" wire:model.live="state" class="input w-44">
                    <option value="current">Working here</option><option value="active">Active</option><option value="notice">Serving notice</option><option value="exited">Left</option><option value="all">Everyone</option>
                </select>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Name</th><th>Designation</th><th>Department</th><th>Branch</th><th>Reports to</th><th>Joined</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($employees as $e)
                        <tr wire:key="emp-{{ $e->id }}">
                            <td class="font-mono text-xs">{{ $e->code }}</td>
                            <td><a href="{{ route('hr.employees.show', $e) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $e->name }}</a></td>
                            <td>{{ $e->designation?->name ?? '—' }}</td>
                            <td>{{ $e->department?->name ?? '—' }}</td>
                            <td>{{ $e->location?->name ?? '—' }}</td>
                            <td>{{ $e->manager?->name ?? '—' }}</td>
                            <td>{{ $e->joining_date->format('d M Y') }}</td>
                            <td>
                                <span @class(['badge', 'bg-emerald-100 text-emerald-800' => $e->employment_status === 'active', 'bg-amber-100 text-amber-800' => $e->employment_status === 'notice', 'bg-slate-100 text-slate-600' => $e->employment_status === 'exited'])>
                                    {{ ['active' => 'Active', 'notice' => 'On notice', 'exited' => 'Left'][$e->employment_status] ?? $e->employment_status }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-10 text-center text-slate-500">No employees match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($employees->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $employees->links() }}</div>@endif
    </div>
</div>

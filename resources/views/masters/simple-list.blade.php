<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $title }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $description }}</p>
        </div>
        @if ($canCreate)
            <button type="button" wire:click="create" class="btn-primary">Add</button>
        @endif
    </div>

    @includeWhen($tabs === 'geography', 'masters.partials.geography-tabs')
    @includeWhen($tabs === 'crm-masters', 'crm.partials.master-tabs')

    <x-status />

    @error('list')
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>
    @enderror

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="m-search" class="sr-only">Search</label>
                <input id="m-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search…" class="input max-w-xs">

                @if ($filterLabel)
                    <label for="m-filter" class="sr-only">{{ $filterLabel }}</label>
                    <select id="m-filter" wire:model.live="filter" class="input w-52">
                        <option value="">{{ $filterLabel }}: all</option>
                        @foreach ($filterOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                @endif

                <label for="m-status" class="sr-only">Status</label>
                <select id="m-status" wire:model.live="status" class="input w-40">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        @foreach ($columns as $heading)<th>{{ $heading }}</th>@endforeach
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr wire:key="row-{{ $row->getKey() }}">
                            @foreach ($columns as $column => $heading)
                                <td @class(['font-mono text-xs' => $column === 'code', 'font-medium text-slate-900' => $column === 'name'])>{{ $cells[$row->getKey()][$column] ?? '' }}</td>
                            @endforeach
                            <td>
                                <span class="badge {{ $row->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($row->status) }}</span>
                            </td>
                            <td class="whitespace-nowrap text-right">
                                @can('update', $row)
                                    <button type="button" wire:click="edit({{ $row->getKey() }})" class="btn-ghost btn-sm">Edit</button>
                                    <button type="button" wire:click="toggleStatus({{ $row->getKey() }})" class="btn-ghost btn-sm">{{ $row->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                @endcan
                                @can('delete', $row)
                                    <button type="button" wire:click="delete({{ $row->getKey() }})" wire:confirm="Delete this record?" class="btn-ghost btn-sm text-rose-600">Delete</button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($columns) + 2 }}" class="py-10 text-center text-slate-500">Nothing here yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $rows->links() }}</div>
        @endif
    </div>

    @if ($formOpen)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="m-form-title">
            <form wire:submit="save" class="card w-full max-w-lg" novalidate>
                <div class="card-header">
                    <h2 id="m-form-title" class="text-sm font-semibold text-slate-900">{{ $editingId ? 'Edit' : 'Add' }} {{ \Illuminate\Support\Str::singular($title) }}</h2>
                    <button type="button" wire:click="cancel" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="card-body space-y-4">
                    @foreach ($fields as $field)
                        @php $name = $field['name']; @endphp
                        <div wire:key="field-{{ $name }}">
                            @switch($field['type'])
                                @case('checkbox')
                                    <label class="flex items-start gap-3 text-sm">
                                        <input type="checkbox" wire:model="values.{{ $name }}" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        <span><span class="font-medium text-slate-800">{{ $field['label'] }}</span>@isset($field['help'])<span class="help block">{{ $field['help'] }}</span>@endisset</span>
                                    </label>
                                    @break
                                @case('select')
                                    <label for="f-{{ $name }}" class="label">{{ $field['label'] }}</label>
                                    <select id="f-{{ $name }}" wire:model{{ ! empty($field['live']) ? '.live' : '' }}="values.{{ $name }}" class="input">
                                        <option value="">{{ $field['placeholder'] ?? 'Select…' }}</option>
                                        @foreach ($fieldOptions[$name] ?? [] as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @isset($field['help'])<p class="help">{{ $field['help'] }}</p>@endisset
                                    @break
                                @case('textarea')
                                    <label for="f-{{ $name }}" class="label">{{ $field['label'] }}</label>
                                    <textarea id="f-{{ $name }}" rows="2" wire:model="values.{{ $name }}" class="input"></textarea>
                                    @break
                                @default
                                    <label for="f-{{ $name }}" class="label">{{ $field['label'] }}</label>
                                    <input id="f-{{ $name }}" type="{{ $field['type'] === 'number' ? 'number' : 'text' }}" wire:model="values.{{ $name }}"
                                           @class(['input', 'uppercase' => ! empty($field['uppercase'])]) placeholder="{{ $field['placeholder'] ?? '' }}" autocomplete="off">
                                    @isset($field['help'])<p class="help">{{ $field['help'] }}</p>@endisset
                            @endswitch
                            @error('values.'.$name) <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancel" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save</button>
                </div>
            </form>
        </div>
    @endif
</div>

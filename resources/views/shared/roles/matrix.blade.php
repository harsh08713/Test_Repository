<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Roles &amp; permissions</h1>
        <p class="mt-1 text-sm text-slate-500">Tick what each role may do on every form. Super Admin always has full access and is not listed.</p>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif
    @error('selected')
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">{{ $message }}</div>
    @enderror

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex items-center gap-3">
                <label for="role" class="text-sm font-medium text-slate-700">Role</label>
                <select id="role" wire:model.live="roleId" class="input w-64">
                    @foreach ($roles as $role)
                        <option value="{{ $role->id }}">{{ $role->name }}</option>
                    @endforeach
                </select>
            </div>

            @if ($canEdit)
                <form wire:submit="createRole" class="flex items-start gap-2">
                    <div>
                        <label for="new-role" class="sr-only">New role name</label>
                        <input id="new-role" type="text" wire:model="newRole" placeholder="New role name" class="input w-52">
                        @error('newRole') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn-secondary">Add role</button>
                </form>
            @endif
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Form</th>
                        @foreach ($actions as $action)
                            <th class="text-center">{{ $action->label() }}</th>
                        @endforeach
                    </tr>
                </thead>
                @foreach ($groups as $group => $formKeys)
                    <tbody wire:key="group-{{ $group }}">
                        <tr><td colspan="{{ count($actions) + 1 }}" class="bg-slate-100/70 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $group }}</td></tr>
                        @foreach ($formKeys as $key)
                            @php $applicable = collect($forms[$key]['actions'])->map->value; @endphp
                            <tr wire:key="form-{{ $key }}">
                                <td class="font-medium text-slate-800">{{ $forms[$key]['label'] }}</td>
                                @foreach ($actions as $action)
                                    <td class="text-center">
                                        @if ($applicable->contains($action->value))
                                            <input type="checkbox" wire:model="selected" value="{{ $action->permission($key) }}" @disabled(! $canEdit)
                                                   class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 disabled:opacity-60"
                                                   aria-label="{{ $forms[$key]['label'] }} — {{ $action->label() }}">
                                        @else
                                            <span class="text-slate-300" aria-hidden="true">·</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Special permissions</h2></div>
        <div class="card-body grid gap-3 sm:grid-cols-2">
            @foreach ($special as $permission => $description)
                <label class="flex items-start gap-2 text-sm text-slate-700" wire:key="special-{{ $permission }}">
                    <input type="checkbox" wire:model="selected" value="{{ $permission }}" @disabled(! $canEdit) class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span>{{ $description }} <span class="font-mono text-xs text-slate-400">{{ $permission }}</span></span>
                </label>
            @endforeach
        </div>
    </div>

    @if ($canEdit)
        <div class="flex justify-end">
            <button type="button" wire:click="save" class="btn-primary" wire:loading.attr="disabled">Save permissions</button>
        </div>
    @endif
</div>

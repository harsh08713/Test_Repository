<div class="mx-auto max-w-4xl space-y-5">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Approval levels</h1>
        <p class="mt-1 text-sm text-slate-500">Choose who must approve each type of document, in order. With no levels configured, a single approval by anyone holding that form's <em>Approve</em> permission is enough.</p>
    </div>

    <x-status />

    <div class="card">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <label for="doc-type" class="text-sm font-medium text-slate-700">Document type</label>
                <select id="doc-type" wire:model.live="documentType" class="input w-56">
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>
            @if ($canEdit)
                <button type="button" wire:click="addLevel" class="btn-secondary btn-sm"><x-icon name="plus" class="size-4" /> Add level</button>
            @endif
        </div>

        <div class="card-body">
            <div class="mb-5 max-w-md">
                <label for="mode" class="label">When there are several levels, they are decided</label>
                <select id="mode" wire:model="mode" @disabled(! $canEdit) class="input">
                    @foreach ($modes as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
                @error('mode') <p class="error">{{ $message }}</p> @enderror
                <p class="help">Applies to documents submitted from now on.</p>
            </div>

            @if (count($levels) === 0)
                <p class="rounded-lg bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">No levels configured — single approval by any user with the Approve permission.</p>
            @endif

            <ol class="space-y-3">
                @foreach ($levels as $i => $level)
                    <li class="rounded-lg border border-slate-200 p-4" wire:key="level-{{ $i }}">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold text-slate-800">Level {{ $i + 1 }}</p>
                            @if ($canEdit)
                                <button type="button" wire:click="removeLevel({{ $i }})" class="text-xs font-medium text-rose-600 hover:underline">Remove</button>
                            @endif
                        </div>
                        <div class="mt-3 grid gap-3 sm:grid-cols-4">
                            <div>
                                <label class="label" for="lvl-name-{{ $i }}">Label <span class="font-normal text-slate-500">(optional)</span></label>
                                <input id="lvl-name-{{ $i }}" type="text" wire:model="levels.{{ $i }}.name" @disabled(! $canEdit) class="input" placeholder="e.g. Finance review">
                            </div>
                            <div>
                                <label class="label" for="lvl-role-{{ $i }}">Approver role</label>
                                <select id="lvl-role-{{ $i }}" wire:model="levels.{{ $i }}.approver_role" @disabled(! $canEdit) class="input">
                                    <option value="">—</option>
                                    @foreach ($roles as $role)<option value="{{ $role }}">{{ $role }}</option>@endforeach
                                </select>
                                @error("levels.$i.approver_role") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="label" for="lvl-user-{{ $i }}">…or a specific user</label>
                                <select id="lvl-user-{{ $i }}" wire:model="levels.{{ $i }}.approver_user_id" @disabled(! $canEdit) class="input">
                                    <option value="">—</option>
                                    @foreach ($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach
                                </select>
                                @error("levels.$i.approver_user_id") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="label" for="lvl-due-{{ $i }}">Decide within <span class="font-normal text-slate-500">(hours, optional)</span></label>
                                <input id="lvl-due-{{ $i }}" type="number" min="1" max="720" wire:model="levels.{{ $i }}.due_hours" @disabled(! $canEdit) class="input">
                                @error("levels.$i.due_hours") <p class="error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>

            @if ($canEdit)
                <div class="mt-5 flex items-center justify-between">
                    <p class="text-xs text-slate-500">Changes apply to documents submitted from now on; ones already waiting keep their chain.</p>
                    <button type="button" wire:click="save" class="btn-primary" wire:loading.attr="disabled">Save levels</button>
                </div>
            @endif
        </div>
    </div>
</div>

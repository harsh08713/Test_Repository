<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">{{ $title }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $description }}</p>
        </div>
        <div class="flex gap-2">
            <button type="button" x-data x-on:click="$dispatch('tree-expand')" class="btn-secondary btn-sm">Expand all</button>
            <button type="button" x-data x-on:click="$dispatch('tree-collapse')" class="btn-secondary btn-sm">Collapse all</button>
            @if ($canCreate)
                <button type="button" wire:click="create" class="btn-primary">Add top-level group</button>
            @endif
        </div>
    </div>

    <x-status />

    @error('tree')
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>
    @enderror

    <div class="card">
        @if ($tree === [])
            <p class="px-5 py-12 text-center text-sm text-slate-500">Nothing here yet.</p>
        @else
            <ul class="py-2" role="tree">
                @include('masters.partials.tree-nodes', ['nodes' => $tree])
            </ul>
        @endif
    </div>

    {{-- Add / edit --}}
    @if ($formOpen)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="t-form-title">
            <form wire:submit="save" class="card w-full max-w-lg" novalidate>
                <div class="card-header">
                    <h2 id="t-form-title" class="text-sm font-semibold text-slate-900">
                        {{ $editingId ? 'Edit group' : ($parentLabel ? "New group under “{$parentLabel}”" : 'New top-level group') }}
                    </h2>
                    <button type="button" wire:click="cancel" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="card-body space-y-4">
                    @if ($editingLocked)
                        <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">This is a standard group. Its code, name and place in the tree are fixed; you can only add a remark.</p>
                    @endif

                    <div>
                        <label for="t-code" class="label">Code</label>
                        <input id="t-code" type="text" wire:model="values.code" @disabled($editingLocked) class="input uppercase" autocomplete="off">
                        @error('values.code') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="t-name" class="label">Name</label>
                        <input id="t-name" type="text" wire:model="values.name" @disabled($editingLocked) class="input" autocomplete="off">
                        @error('values.name') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    @if ($hasNature && ! $editingId && ! $parentLabel)
                        <div>
                            <label for="t-nature" class="label">Nature</label>
                            <select id="t-nature" wire:model="values.nature" class="input">
                                <option value="">Select…</option>
                                @foreach ($natureOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                            <p class="help">Every group beneath it inherits this.</p>
                            @error('values.nature') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @unless ($editingLocked)
                        <div>
                            <label for="t-status" class="label">Status</label>
                            <select id="t-status" wire:model="values.status" class="input">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            @error('values.status') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endunless

                    <div>
                        <label for="t-remarks" class="label">Remarks</label>
                        <textarea id="t-remarks" rows="2" wire:model="values.remarks" class="input"></textarea>
                        @error('values.remarks') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    @error('values.parent_id') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancel" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Move --}}
    @if ($moveOpen)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="t-move-title">
            <form wire:submit="move" class="card w-full max-w-lg" novalidate>
                <div class="card-header">
                    <h2 id="t-move-title" class="text-sm font-semibold text-slate-900">Move group</h2>
                    <button type="button" wire:click="cancel" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="card-body space-y-3">
                    <label for="t-parent" class="label">New parent</label>
                    <select id="t-parent" wire:model="newParentId" class="input">
                        <option value="">— top level —</option>
                        @foreach ($moveTargets as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                    </select>
                    <p class="help">Only groups it can legitimately sit under are listed: not itself, not its own sub-groups{{ $hasNature ? ', and only groups of the same nature' : '' }}.</p>
                    @error('newParentId') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancel" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">Move</button>
                </div>
            </form>
        </div>
    @endif
</div>

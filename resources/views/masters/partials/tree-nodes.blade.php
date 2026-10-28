@foreach ($nodes as $node)
    @php
        $model = $node['model'];
        $isLocked = $locked[$model->getKey()] ?? false;
        $hasChildren = $node['children'] !== [];
    @endphp
    <li wire:key="node-{{ $model->getKey() }}" role="treeitem" aria-expanded="true"
        x-data="{ open: true }" x-on:tree-expand.window="open = true" x-on:tree-collapse.window="open = false">
        <div class="group flex flex-wrap items-center gap-x-2 gap-y-1 py-1.5 pr-4 hover:bg-slate-50" style="padding-left: {{ 1 + $node['depth'] * 1.5 }}rem">
            <button type="button" x-on:click="open = ! open" @disabled(! $hasChildren)
                    class="flex size-7 shrink-0 items-center justify-center rounded text-slate-400 hover:bg-slate-200 disabled:opacity-0 pointer-coarse:size-9"
                    x-bind:aria-label="open ? 'Collapse' : 'Expand'">
                <svg class="size-3.5 transition" x-bind:class="open ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M7 4l6 6-6 6V4z" /></svg>
            </button>

            <span class="font-mono text-xs text-slate-500">{{ $model->code }}</span>
            <span @class(['text-sm', 'font-semibold text-slate-900' => $node['depth'] === 0, 'text-slate-800' => $node['depth'] > 0])>{{ $model->name }}</span>

            @if ($hasNature && $node['depth'] === 0)
                <span class="badge bg-brand-50 text-brand-700">{{ $model->nature->label() }}</span>
            @endif
            @if ($isLocked)
                <span class="badge bg-slate-100 text-slate-600" title="Standard group — fixed in place">Standard</span>
            @endif
            @if ($model->status !== 'active')
                <span class="badge bg-amber-100 text-amber-800">Inactive</span>
            @endif

            {{-- Shown on hover only where there is a mouse (pointer-fine); a finger cannot hover, so on a phone or a touch tablet the actions are always there, on a line of their own when the name is long. --}}
            <span class="ml-auto flex shrink-0 flex-wrap justify-end gap-1 transition pointer-fine:opacity-0 pointer-fine:group-focus-within:opacity-100 pointer-fine:group-hover:opacity-100">
                @can('create', $model)
                    <button type="button" wire:click="create({{ $model->getKey() }})" class="btn-ghost btn-sm">Add child</button>
                @endcan
                @can('update', $model)
                    <button type="button" wire:click="edit({{ $model->getKey() }})" class="btn-ghost btn-sm">Edit</button>
                    @unless ($isLocked)
                        <button type="button" wire:click="startMove({{ $model->getKey() }})" class="btn-ghost btn-sm">Move</button>
                        <button type="button" wire:click="toggleStatus({{ $model->getKey() }})" class="btn-ghost btn-sm">{{ $model->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                    @endunless
                @endcan
                @can('delete', $model)
                    @unless ($isLocked)
                        <button type="button" wire:click="delete({{ $model->getKey() }})" wire:confirm="Delete this group?" class="btn-ghost btn-sm text-rose-600">Delete</button>
                    @endunless
                @endcan
            </span>
        </div>

        @if ($hasChildren)
            <ul x-show="open" role="group">
                @include('masters.partials.tree-nodes', ['nodes' => $node['children']])
            </ul>
        @endif
    </li>
@endforeach

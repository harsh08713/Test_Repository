@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <p class="text-sm"><a href="{{ route('workspace.tasks.index') }}" wire:navigate class="text-brand-700 hover:underline">Tasks</a></p>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $task->title }}</h1>
            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                <span class="badge {{ $task->status->badge() }}">{{ $task->status->label() }}</span>
                <span class="badge {{ $task->priority->badge() }}">{{ $task->priority->label() }} priority</span>
                @if ($task->isOverdue())<span class="badge bg-rose-100 text-rose-800">Overdue</span>@endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('update', $task)
                @if ($task->status->value === 'open')<button type="button" wire:click="startTask" class="btn-secondary">Start</button>@endif
                <button type="button" wire:click="openDialog('finish')" class="btn-primary">Done</button>
                <a href="{{ route('workspace.tasks.edit', $task) }}" wire:navigate class="btn-secondary">Change</a>
                <button type="button" wire:click="openDialog('cancel')" class="btn-secondary">Call off</button>
            @endcan
            @can('assign', $task)
                <button type="button" wire:click="openDialog('give')" class="btn-secondary">Give to…</button>
            @endcan
        </div>
    </div>

    <x-status />
    @error('workflow')<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror

    <div class="grid gap-5 lg:grid-cols-3">
        <section class="card lg:col-span-2" aria-labelledby="sec-task">
            <div class="card-header"><h2 id="sec-task" class="text-sm font-semibold text-slate-900">Details</h2></div>
            <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-2">
                <div><dt class="text-xs text-slate-500">With</dt><dd class="text-sm text-slate-900">{{ $task->assignee?->name }}</dd></div>
                <div><dt class="text-xs text-slate-500">Made by</dt><dd class="text-sm text-slate-900">{{ $task->creator?->name ?? '—' }} · {{ Format::dateTime($task->created_at) }}</dd></div>
                <div><dt class="text-xs text-slate-500">Deadline</dt><dd class="text-sm {{ $task->isOverdue() ? 'font-medium text-rose-700' : 'text-slate-900' }}">{{ $task->due_at ? Format::dateTime($task->due_at) : 'None' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Reminder</dt><dd class="text-sm text-slate-900">{{ $task->remind_at ? Format::dateTime($task->remind_at) : 'None' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Repeats</dt><dd class="text-sm text-slate-900">{{ $task->recurrence->label() }}{{ $task->recurrence_until ? ', until '.Format::date($task->recurrence_until) : '' }}</dd></div>
                <div><dt class="text-xs text-slate-500">About</dt><dd class="text-sm text-slate-900">@if ($about && $about['url'])<a href="{{ $about['url'] }}" wire:navigate class="text-brand-700 hover:underline"><span class="text-xs text-slate-500">{{ $about['type'] }}</span> {{ $about['label'] }}</a>@elseif ($about)<span class="text-slate-500">{{ $about['type'] }}: {{ $about['label'] }}</span>@else — @endif</dd></div>
                @if ($task->description)<div class="sm:col-span-2"><dt class="text-xs text-slate-500">Details</dt><dd class="whitespace-pre-line text-sm text-slate-900">{{ $task->description }}</dd></div>@endif
                @if ($task->status->value === 'done')
                    <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Done by {{ $task->completer?->name }} on {{ Format::dateTime($task->completed_at) }}</dt><dd class="whitespace-pre-line text-sm text-slate-900">{{ $task->completion_note ?: 'No note.' }}</dd></div>
                @endif
                @if ($task->status->value === 'cancelled')
                    <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Called off</dt><dd class="text-sm text-slate-900">{{ $task->cancel_reason }}</dd></div>
                @endif
                @if ($clock)
                    <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Service level</dt>
                        <dd class="text-sm text-slate-900">
                            Escalates if not done by {{ Format::dateTime($clock->due_at) }} —
                            @if ($clock->state === 'running' && $clock->breached_at) <span class="font-medium text-rose-700">missed</span>
                            @elseif ($clock->state === 'running') running
                            @elseif ($clock->state === 'met') {{ $clock->breached_at ? 'done late' : 'met' }}
                            @else stopped ({{ $clock->stop_reason }}) @endif
                        </dd>
                    </div>
                @endif
            </dl>
        </section>

        <section class="card" aria-labelledby="sec-hist">
            <div class="card-header"><h2 id="sec-hist" class="text-sm font-semibold text-slate-900">Who has had it</h2></div>
            <ul class="divide-y divide-slate-100">
                @foreach ($task->assignments as $row)
                    <li class="px-5 py-2 text-sm" wire:key="as-{{ $row->id }}">
                        <div class="text-slate-800">{{ $row->from ? $row->from->name.' → ' : 'Given to ' }}<strong>{{ $row->to?->name }}</strong></div>
                        <div class="text-xs text-slate-500">{{ Format::dateTime($row->created_at) }}{{ $row->actor ? ' · by '.$row->actor->name : '' }}{{ $row->reason ? ' · '.$row->reason : '' }}</div>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <section class="card" aria-labelledby="sec-files">
        <div class="card-header"><h2 id="sec-files" class="text-sm font-semibold text-slate-900">Files</h2></div>
        <ul class="divide-y divide-slate-100">
            @forelse ($files as $file)
                <li class="flex items-center justify-between gap-3 px-5 py-2 text-sm" wire:key="file-{{ $file->id }}">
                    <span><a href="{{ route('workspace.tasks.attachments.download', [$task, $file]) }}" class="text-brand-700 hover:underline">{{ $file->name }}</a> <span class="text-xs text-slate-500">{{ number_format($file->size / 1024, 0) }} KB · {{ $file->uploader?->name }} · {{ Format::dateTime($file->created_at) }}</span></span>
                    @can('update', $task)<button type="button" wire:click="removeFile({{ $file->id }})" wire:confirm="Remove this file?" class="text-xs text-rose-700 hover:underline">Remove</button>@endcan
                </li>
            @empty
                <li class="px-5 py-4 text-sm text-slate-500">No file on this task.</li>
            @endforelse
        </ul>
        @can('update', $task)
            <form wire:submit="addFile" class="flex flex-wrap items-end gap-3 border-t border-slate-100 px-5 py-3" novalidate>
                <div>
                    <label for="t-file" class="block text-xs text-slate-500">Add a file (PDF, picture, text, CSV, Word, Excel, PowerPoint — up to 10 MB)</label>
                    <input id="t-file" type="file" wire:model="attachment" class="input">
                    @error('attachment') <p class="error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn-secondary">Add</button>
            </form>
        @endcan
    </section>

    <livewire:shared.comments type="task" :id="$task->id" :key="'cm-'.$task->id" />

    @if ($dialog !== '')
        @php
            [$title, $action, $button] = match ($dialog) {
                'finish' => ['Mark the task as done', 'finishTask', 'Done'],
                'cancel' => ['Call the task off', 'cancelTask', 'Call off'],
                default => ['Give the task to somebody else', 'giveTask', 'Give'],
            };
        @endphp
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="dialog-title">
            <form wire:submit="{{ $action }}" class="card w-full max-w-lg" novalidate wire:key="dialog-{{ $dialog }}">
                <div class="card-header">
                    <h2 id="dialog-title" class="text-sm font-semibold text-slate-900">{{ $title }}</h2>
                    <button type="button" wire:click="closeDialog" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="card-body space-y-3">
                    @if ($dialog === 'finish')
                        <div>
                            <label for="d-note" class="label">What was done? <span class="font-normal text-slate-400">(optional)</span></label>
                            <textarea id="d-note" rows="3" wire:model="note" class="input" maxlength="2000"></textarea>
                            @error('note') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        @if ($task->recurrence->value !== 'none')<p class="text-xs text-slate-500">This task repeats: finishing it makes the next one.</p>@endif
                    @elseif ($dialog === 'cancel')
                        <div>
                            <label for="d-reason" class="label">Why is it being called off?</label>
                            <textarea id="d-reason" rows="2" wire:model="reason" class="input" maxlength="500"></textarea>
                            @error('reason') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @else
                        <div>
                            <label for="d-to" class="label">Give it to</label>
                            <select id="d-to" wire:model="giveTo" class="input">
                                <option value="">Choose a person…</option>
                                @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                            @error('assigned_to') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="d-why" class="label">Note <span class="font-normal text-slate-400">(optional)</span></label>
                            <textarea id="d-why" rows="2" wire:model="reason" class="input" maxlength="500"></textarea>
                            @error('reason') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeDialog" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary">{{ $button }}</button>
                </div>
            </form>
        </div>
    @endif
</div>

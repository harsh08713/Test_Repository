@php
    use App\Modules\Shared\Support\Format;
    use App\Modules\Workspace\Enums\TaskStatus;

    $modes = ['list' => 'List', 'board' => 'Board', 'calendar' => 'Calendar'];
    $shows = ['mine' => 'With me', 'given' => 'Given by me', 'team' => 'Team', 'all' => 'Everything I may see'];
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Tasks</h1>
            <p class="mt-1 text-sm text-slate-500">Things to do — yours, the ones you gave to others, and your team’s. A task can be about a lead, a deal, a quotation, an order or a customer. Follow-ups with a lead stay on the lead.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canExport)<a href="{{ route('reports.run', ['key' => 'overdue-tasks', 'choice' => 'live']) }}" wire:navigate class="btn-secondary">Export to Excel or PDF</a>@endif
            @if ($canCreate)
                <a href="{{ route('workspace.tasks.create') }}" wire:navigate class="btn-primary">New task</a>
            @endif
        </div>
    </div>

    <x-status />
    @error('list')<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror

    <nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Task view">
        @foreach ($modes as $value => $label)
            <button type="button" wire:click="$set('mode', '{{ $value }}')" wire:key="mode-{{ $value }}"
                    @class([
                        'whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
                        'border-brand-600 text-brand-700' => $mode === $value,
                        'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $mode !== $value,
                    ])
                    @if ($mode === $value) aria-current="page" @endif>{{ $label }}</button>
        @endforeach
    </nav>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-end gap-3">
                <div>
                    <label for="t-show" class="block text-xs text-slate-500">Show</label>
                    <select id="t-show" wire:model.live="show" class="input w-52">
                        @foreach ($shows as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                @if ($mode === 'list')
                    <div>
                        <label for="t-state" class="block text-xs text-slate-500">State</label>
                        <select id="t-state" wire:model.live="state" class="input w-40">
                            <option value="live">To do or in progress</option>
                            <option value="overdue">Overdue</option>
                            <option value="done">Done</option>
                            <option value="all">Everything</option>
                        </select>
                    </div>
                @endif
                @if (count($people) > 1)
                    <div>
                        <label for="t-person" class="block text-xs text-slate-500">Person</label>
                        <select id="t-person" wire:model.live="person" class="input w-44">
                            <option value="">Anyone</option>
                            @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <label for="t-priority" class="block text-xs text-slate-500">Priority</label>
                    <select id="t-priority" wire:model.live="priority" class="input w-36">
                        <option value="">Any</option>
                        @foreach ($priorities as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="t-q" class="block text-xs text-slate-500">Search</label>
                    <input id="t-q" type="search" wire:model.live.debounce.300ms="search" placeholder="Title…" class="input w-48">
                </div>
            </div>
        </div>

        @if ($mode === 'list')
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Task</th><th>About</th><th>With</th><th>Priority</th><th>State</th><th>Due</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($tasks as $task)
                            @php $link = $about($task); @endphp
                            <tr wire:key="task-{{ $task->id }}">
                                <td>
                                    <a href="{{ route('workspace.tasks.show', $task) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $task->title }}</a>
                                    @if ($task->recurrence->value !== 'none')<div class="text-xs text-slate-500">{{ $task->recurrence->label() }}</div>@endif
                                </td>
                                <td class="text-sm text-slate-600">
                                    @if ($link && $link['url'])<a href="{{ $link['url'] }}" wire:navigate class="hover:underline"><span class="text-xs text-slate-400">{{ $link['type'] }}</span> {{ $link['label'] }}</a>@elseif ($link)<span class="text-slate-400">{{ $link['type'] }}: {{ $link['label'] }}</span>@else — @endif
                                </td>
                                <td class="text-slate-700">{{ $task->assignee?->name }}</td>
                                <td><span class="badge {{ $task->priority->badge() }}">{{ $task->priority->label() }}</span></td>
                                <td><span class="badge {{ $task->status->badge() }}">{{ $task->status->label() }}</span></td>
                                <td class="whitespace-nowrap tabular-nums">
                                    @if ($task->due_at)
                                        <span @class(['font-medium text-rose-700' => $task->isOverdue(), 'text-slate-600' => ! $task->isOverdue()])>{{ Format::dateTime($task->due_at) }}</span>
                                    @else <span class="text-slate-400">No deadline</span> @endif
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    @can('update', $task)
                                        @if ($task->status === TaskStatus::Open)<button type="button" wire:click="startTask({{ $task->id }})" class="btn-secondary">Start</button>@endif
                                        <button type="button" wire:click="openFinish({{ $task->id }})" class="btn-secondary">Done</button>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-10 text-center text-slate-500">No tasks match.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($tasks->hasPages())
                <div class="border-t border-slate-200 px-5 py-3">{{ $tasks->links() }}</div>
            @endif
        @elseif ($mode === 'board')
            <div class="grid gap-4 p-4 lg:grid-cols-3">
                @foreach ($columns as $key => $column)
                    <section wire:key="col-{{ $key }}" aria-labelledby="col-h-{{ $key }}" class="rounded-lg bg-slate-50 p-3">
                        <h2 id="col-h-{{ $key }}" class="mb-2 flex items-center justify-between text-sm font-semibold text-slate-800">{{ $column['label'] }} <span class="badge bg-white text-slate-600">{{ $column['count'] }}</span></h2>
                        <ul class="space-y-2">
                            @forelse ($column['tasks'] as $task)
                                @php $link = $about($task); @endphp
                                <li wire:key="card-{{ $task->id }}" class="rounded-lg border border-slate-200 bg-white p-3 text-sm shadow-sm">
                                    <a href="{{ route('workspace.tasks.show', $task) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $task->title }}</a>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                        <span class="badge {{ $task->priority->badge() }}">{{ $task->priority->label() }}</span>
                                        <span>{{ $task->assignee?->name }}</span>
                                        @if ($task->due_at)<span @class(['font-medium text-rose-700' => $task->isOverdue()])>{{ Format::dateTime($task->due_at) }}</span>@endif
                                    </div>
                                    @if ($link)<div class="mt-1 truncate text-xs text-slate-500">{{ $link['type'] }}: {{ $link['label'] }}</div>@endif
                                    @can('update', $task)
                                        <div class="mt-2 flex gap-2">
                                            @if ($task->status === TaskStatus::Open)<button type="button" wire:click="startTask({{ $task->id }})" class="btn-secondary">Start</button>@endif
                                            <button type="button" wire:click="openFinish({{ $task->id }})" class="btn-secondary">Done</button>
                                        </div>
                                    @endcan
                                </li>
                            @empty
                                <li class="text-sm text-slate-400">Nothing here.</li>
                            @endforelse
                        </ul>
                        @if ($column['count'] > count($column['tasks']))<p class="mt-2 text-xs text-slate-500">Showing the first {{ count($column['tasks']) }} of {{ $column['count'] }}. Narrow the filters or use the list.</p>@endif
                    </section>
                @endforeach
            </div>
            <p class="px-5 pb-3 text-xs text-slate-500">Done shows the last 14 days.</p>
        @else
            @php
                $lead = $first->copy()->startOfWeek();
                $end = $first->copy()->endOfMonth()->endOfWeek();
            @endphp
            <div class="flex items-center justify-between px-5 py-3">
                <button type="button" wire:click="shiftMonth(-1)" class="btn-secondary" aria-label="Previous month">‹</button>
                <h2 class="text-sm font-semibold text-slate-900">{{ $first->format('F Y') }}</h2>
                <button type="button" wire:click="shiftMonth(1)" class="btn-secondary" aria-label="Next month">›</button>
            </div>
            <div class="grid grid-cols-7 border-t border-slate-200 text-center text-xs text-slate-500">
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)<div class="border-b border-slate-200 py-1">{{ $d }}</div>@endforeach
            </div>
            <div class="grid grid-cols-7">
                @for ($day = $lead->copy(); $day->lte($end); $day->addDay())
                    @php $items = $byDay->get($day->toDateString(), collect()); @endphp
                    <div wire:key="day-{{ $day->toDateString() }}" @class(['min-h-24 border-b border-r border-slate-100 p-1.5 text-xs', 'bg-slate-50 text-slate-400' => $day->month !== $first->month])>
                        <div @class(['mb-1 font-medium', 'text-brand-700' => $day->isToday()])>{{ $day->day }}</div>
                        @foreach ($items->take(3) as $task)
                            <a href="{{ route('workspace.tasks.show', $task->id) }}" wire:navigate wire:key="cal-{{ $task->id }}"
                               @class(['mb-0.5 block truncate rounded px-1 py-0.5', 'bg-emerald-100 text-emerald-800 line-through' => $task->status === TaskStatus::Done, 'bg-rose-100 text-rose-800' => $task->status !== TaskStatus::Done && $task->due_at->isPast(), 'bg-sky-100 text-sky-800' => $task->status !== TaskStatus::Done && ! $task->due_at->isPast()])
                               title="{{ $task->title }}">{{ $task->due_at->format('H:i') }} {{ $task->title }}</a>
                        @endforeach
                        @if ($items->count() > 3)<div class="text-slate-500">+{{ $items->count() - 3 }} more</div>@endif
                    </div>
                @endfor
            </div>
        @endif
    </div>

    @if ($finishing)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="finish-title">
            <form wire:submit="finishTask" class="card w-full max-w-lg" novalidate>
                <div class="card-header">
                    <h2 id="finish-title" class="text-sm font-semibold text-slate-900">Mark the task as done</h2>
                    <button type="button" wire:click="closeFinish" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="card-body space-y-3">
                    <div>
                        <label for="f-note" class="label">What was done? <span class="font-normal text-slate-400">(optional)</span></label>
                        <textarea id="f-note" rows="3" wire:model="note" class="input" maxlength="2000"></textarea>
                        @error('note') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <p class="text-xs text-slate-500">A task that repeats makes its next one when you finish this one.</p>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeFinish" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary">Done</button>
                </div>
            </form>
        </div>
    @endif
</div>

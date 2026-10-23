<div class="mx-auto max-w-3xl space-y-5">
    <div>
        <p class="text-sm"><a href="{{ route('workspace.tasks.index') }}" wire:navigate class="text-brand-700 hover:underline">Tasks</a></p>
        <h1 class="text-2xl font-semibold text-slate-900">{{ $editing ? 'Change the task' : 'New task' }}</h1>
    </div>

    <form wire:submit="save" class="card" novalidate>
        <div class="card-body space-y-4">
            @error('form')<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror
            @error('subject_id')<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror

            @if ($about)
                <p class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700"><span>About: <span class="text-xs text-slate-500">{{ $about['type'] }}</span> <strong>{{ $about['label'] }}</strong></span>
                    @if (! $editing)<button type="button" wire:click="clearSubject" class="text-xs text-rose-700 hover:underline">Remove</button>@endif</p>
            @elseif (! $editing)
                <div class="relative">
                    <label for="t-about" class="label">About a record <span class="font-normal text-slate-400">(optional — a lead, deal, quotation, order or customer)</span></label>
                    <input id="t-about" type="search" wire:model.live.debounce.300ms="subjectSearch" class="input" placeholder="Type a number, a name or a mobile…" autocomplete="off">
                    @if ($found !== [])
                        <ul class="absolute z-10 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg" role="listbox">
                            @foreach ($found as $hit)
                                <li wire:key="hit-{{ $hit['type'] }}-{{ $hit['id'] }}"><button type="button" wire:click="pickSubject('{{ $hit['type'] }}', {{ $hit['id'] }})" class="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50"><span class="text-xs text-slate-400">{{ $hit['kind'] }}</span> {{ $hit['label'] }}</button></li>
                            @endforeach
                        </ul>
                    @elseif (mb_strlen(trim($subjectSearch)) >= 2)
                        <p class="mt-1 text-xs text-slate-500">Nothing you may open matches that.</p>
                    @endif
                </div>
            @endif

            <div>
                <label for="t-title" class="label">What has to be done?</label>
                <input id="t-title" type="text" wire:model="t.title" class="input" maxlength="200" required>
                @error('title') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="t-desc" class="label">Details <span class="font-normal text-slate-400">(optional)</span></label>
                <textarea id="t-desc" rows="3" wire:model="t.description" class="input" maxlength="5000"></textarea>
                @error('description') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="t-priority" class="label">Priority</label>
                    <select id="t-priority" wire:model="t.priority" class="input">
                        @foreach ($priorities as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    @error('priority') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="t-assignee" class="label">With</label>
                    <select id="t-assignee" wire:model="t.assigned_to" class="input" @disabled(count($people) < 2 || $editing)>
                        @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @if ($editing)<p class="mt-1 text-xs text-slate-500">To give it to somebody else, use “Give to…” on the task.</p>@endif
                    @error('assigned_to') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="t-due" class="label">Deadline <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="t-due" type="datetime-local" wire:model="t.due_at" class="input">
                    @error('due_at') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="t-remind" class="label">Remind me at <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="t-remind" type="datetime-local" wire:model="t.remind_at" class="input">
                    @error('remind_at') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="t-repeat" class="label">Repeats</label>
                    <select id="t-repeat" wire:model="t.recurrence" class="input">
                        @foreach ($recurrences as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    @error('recurrence') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="t-until" class="label">Last repeat on or before <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="t-until" type="date" wire:model="t.recurrence_until" class="input">
                    @error('recurrence_until') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
            <a href="{{ route('workspace.tasks.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary">{{ $editing ? 'Save' : 'Make the task' }}</button>
        </div>
    </form>
</div>

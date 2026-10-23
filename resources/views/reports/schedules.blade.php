<div class="space-y-5">
    <div class="max-w-3xl">
        <p class="text-sm"><a href="{{ route('reports.index') }}" wire:navigate class="text-brand-700 hover:underline">Reports</a></p>
        <h1 class="text-2xl font-semibold text-slate-900">Scheduled reports</h1>
        <p class="mt-1 text-sm text-slate-500">A report e-mailed to you as a file, on a schedule. It is made as you, with the access you have on screen, and the date range is worked out each time it runs (“last month” always means the month just gone). You can keep up to {{ $cap }}.</p>
    </div>

    <x-status />

    <div class="card">
        <div class="card-header flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-900">Your schedules</h2>
            <button type="button" wire:click="openForm" class="btn-primary">{{ $adding ? 'Close' : 'Schedule a report' }}</button>
        </div>

        @if ($adding)
            <form wire:submit="save" class="card-body grid gap-4 border-b border-slate-100 md:grid-cols-3">
                <div class="md:col-span-3">
                    <label class="label" for="sr-report">Report</label>
                    <select id="sr-report" wire:model.live="form.report" class="input">
                        <option value="">Choose a report…</option>
                        @foreach ($reports as $key => $title)
                            <option value="{{ $key }}">{{ $title }}</option>
                        @endforeach
                    </select>
                    @error('report') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="sr-format">File</label>
                    <select id="sr-format" wire:model="form.format" class="input"><option value="xlsx">Excel</option><option value="pdf">PDF</option></select>
                    @error('format') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="sr-frequency">How often</label>
                    <select id="sr-frequency" wire:model.live="form.frequency" class="input">
                        @foreach ($frequencies as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('frequency') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                @if ($form['frequency'] === 'weekly')
                    <div>
                        <label class="label" for="sr-weekday">On</label>
                        <select id="sr-weekday" wire:model="form.weekday" class="input">
                            @foreach ($weekdays as $n => $name)
                                <option value="{{ $n }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('weekday') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @elseif ($form['frequency'] === 'monthly')
                    <div>
                        <label class="label" for="sr-monthday">On day of the month</label>
                        <input id="sr-monthday" type="number" min="1" max="28" wire:model="form.month_day" class="input">
                        @error('month_day') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label class="label" for="sr-hour">At (hour, 0–23)</label>
                    <input id="sr-hour" type="number" min="0" max="23" wire:model="form.send_hour" class="input">
                    @error('send_hour') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                @if ($chosen !== null && in_array('date', $chosen->filters(), true))
                    <div>
                        <label class="label" for="sr-range">Dates</label>
                        <select id="sr-range" wire:model="form.date_range" class="input">
                            @foreach ($ranges as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('date_range') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endif
                @if ($branches !== [])
                    <div>
                        <label class="label" for="sr-branch">Branch</label>
                        <select id="sr-branch" wire:model="form.branch" class="input">
                            <option value="">All my branches</option>
                            @foreach ($branches as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('branch') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endif
                @if ($choices !== [])
                    <div>
                        <label class="label" for="sr-choice">{{ $choiceLabel }}</label>
                        <select id="sr-choice" wire:model="form.choice" class="input">
                            <option value="">Report’s default</option>
                            @foreach ($choices as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('choice') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div class="md:col-span-3"><button type="submit" class="btn-primary">Save schedule</button></div>
            </form>
        @endif

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Report</th><th>File</th><th>When</th><th>Dates</th><th>Next</th><th>State</th><th></th></tr></thead>
                <tbody>
                    @forelse ($schedules as $schedule)
                        <tr wire:key="sch-{{ $schedule->id }}">
                            <td class="font-medium text-slate-900">{{ $catalog->find($schedule->report_key)?->title() ?? $schedule->report_key }}</td>
                            <td class="uppercase text-slate-600">{{ $schedule->format }}</td>
                            <td class="text-slate-600">{{ $frequencies[$schedule->frequency] }}, {{ sprintf('%02d:00', $schedule->send_hour) }}</td>
                            <td class="text-slate-600">{{ $ranges[$schedule->date_range] ?? '' }}</td>
                            <td class="whitespace-nowrap tabular-nums text-slate-600">{{ $schedule->active ? $schedule->next_run_at->format('j M Y H:i') : '—' }}</td>
                            <td>{{ $schedule->active ? 'Active' : 'Paused' }}@if ($schedule->paused_reason) <span class="block text-xs text-slate-500">{{ $schedule->paused_reason }}</span>@endif</td>
                            <td class="whitespace-nowrap text-right">
                                @if ($schedule->active)
                                    <button type="button" wire:click="pause({{ $schedule->id }})" class="text-sm text-brand-700 hover:underline">Pause</button>
                                @else
                                    <button type="button" wire:click="resume({{ $schedule->id }})" class="text-sm text-brand-700 hover:underline">Resume</button>
                                @endif
                                <button type="button" wire:click="sendNow({{ $schedule->id }})" class="ml-3 text-sm text-brand-700 hover:underline">Send now</button>
                                <button type="button" wire:click="remove({{ $schedule->id }})" wire:confirm="Remove this scheduled report?" class="ml-3 text-sm text-red-600 hover:underline">Remove</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-slate-500">Nothing is scheduled yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

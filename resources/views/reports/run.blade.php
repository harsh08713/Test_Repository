<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <p class="text-sm"><a href="{{ route('reports.index') }}" wire:navigate class="text-brand-700 hover:underline">Reports</a> <span class="text-slate-400">/ {{ $report->group() }}</span></p>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $report->title() }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $report->description() }}</p>
        </div>
        @if ($canExport)
            <div class="flex gap-2">
                <button type="button" wire:click="requestExport('xlsx')" class="btn-secondary">Export to Excel</button>
                <button type="button" wire:click="requestExport('pdf')" class="btn-secondary">Export to PDF</button>
            </div>
        @endif
    </div>

    <x-status />

    @if ($report->note())
        <div class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900" role="note">{{ $report->note() }}</div>
    @endif

    @if ($problems !== [])
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800" role="alert">
            @foreach ($problems as $problem)<p>{{ $problem }}</p>@endforeach
        </div>
    @endif

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-end gap-3">
                @if (in_array('date', $report->filters(), true))
                    <div>
                        <label for="f-from" class="block text-xs text-slate-500">{{ $report->dateLabel() }} from</label>
                        <input id="f-from" type="date" wire:model.live="from" class="input w-40">
                    </div>
                    <div>
                        <label for="f-to" class="block text-xs text-slate-500">to</label>
                        <input id="f-to" type="date" wire:model.live="to" class="input w-40">
                    </div>
                @endif
                @if (in_array('choice', $report->filters(), true))
                    <div>
                        <label for="f-choice" class="block text-xs text-slate-500">{{ $report->choiceLabel() }}</label>
                        <select id="f-choice" wire:model.live="choice" class="input w-56">
                            <option value="">All</option>
                            @foreach ($report->choices() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                @endif
                @if (in_array('branch', $report->filters(), true))
                    <div>
                        <label for="f-branch" class="block text-xs text-slate-500">Branch</label>
                        <select id="f-branch" wire:model.live="branch" class="input w-44">
                            <option value="">All branches</option>
                            @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                    </div>
                @endif
                @if (in_array('person', $report->filters(), true))
                    <div>
                        <label for="f-person" class="block text-xs text-slate-500">Person</label>
                        <select id="f-person" wire:model.live="person" class="input w-48">
                            <option value="">Everyone I may see</option>
                            @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                    </div>
                @endif
                @if (in_array('team', $report->filters(), true) && $teams !== [])
                    <div>
                        <label for="f-team" class="block text-xs text-slate-500">Team</label>
                        <select id="f-team" wire:model.live="team" class="input w-44">
                            <option value="">All teams</option>
                            @foreach ($teams as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                    </div>
                @endif
                @if (in_array('source', $report->filters(), true))
                    <div>
                        <label for="f-source" class="block text-xs text-slate-500">Source</label>
                        <select id="f-source" wire:model.live="source" class="input w-44">
                            <option value="">All sources</option>
                            @foreach ($sources as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                    </div>
                @endif
            </div>
        </div>

        @if ($summary !== [])
            <dl class="grid gap-3 border-b border-slate-200 px-5 py-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Totals for these filters">
                @foreach ($summary as $label => $value)
                    <div wire:key="sum-{{ \Illuminate\Support\Str::slug($label) }}"><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="text-lg font-semibold tabular-nums text-slate-900">{{ $value }}</dd></div>
                @endforeach
            </dl>
        @endif

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        @foreach ($report->columns() as $column)
                            <th @class(['text-right' => $column->isNumeric()])>{{ $column->label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows ?? [] as $i => $row)
                        <tr wire:key="row-{{ $rows->firstItem() + $i }}">
                            @foreach ($report->columns() as $column)
                                <td @class(['text-right tabular-nums' => $column->isNumeric(), 'whitespace-nowrap' => in_array($column->type, ['date', 'datetime'], true)])>{{ $column->show($row[$column->key] ?? null) }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($report->columns()) }}" class="py-10 text-center text-slate-500">{{ $problems === [] ? 'Nothing matches these filters.' : 'Fix the filters above to see the report.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows !== null && $rows->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $rows->links() }}</div>
        @endif
    </div>
</div>

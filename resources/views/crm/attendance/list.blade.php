<div class="space-y-5">
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">Attendance</h1>
        <p class="mt-1 text-sm text-slate-500">Marked against the day’s published route plan, from the person’s own position, with the server’s clock. Records are never edited.</p>
    </div>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="a-from" class="text-sm text-slate-600">From</label>
                <input id="a-from" type="date" wire:model.live="from" class="input w-40">
                <label for="a-to" class="text-sm text-slate-600">to</label>
                <input id="a-to" type="date" wire:model.live="to" class="input w-40">

                <label for="a-person" class="sr-only">Person</label>
                <select id="a-person" wire:model.live="person" class="input w-52">
                    <option value="">Everyone</option>
                    @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                <label for="a-branch" class="sr-only">Branch</label>
                <select id="a-branch" wire:model.live="branch" class="input w-44">
                    <option value="">All branches</option>
                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                <label for="a-decision" class="sr-only">Decision</label>
                <select id="a-decision" wire:model.live="decision" class="input w-48">
                    <option value="">Any decision</option>
                    @foreach ($decisions as $d)<option value="{{ $d->value }}">{{ $d->label() }}</option>@endforeach
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Day</th><th>Person</th><th>Branch</th><th>Marked</th><th>At stop</th><th class="text-right">Distance</th><th class="text-right">Accuracy</th><th>Decision</th><th>From</th></tr></thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr wire:key="rec-{{ $record->id }}">
                            <td class="whitespace-nowrap">{{ $record->attendance_date->format('D, j M Y') }}</td>
                            <td class="font-medium text-slate-900">{{ $record->user?->name }}</td>
                            <td class="text-slate-600">{{ $record->plan?->location?->name }}</td>
                            <td class="tabular-nums">{{ $record->marked_at->format('H:i') }}</td>
                            <td class="text-slate-600">{{ $record->stop?->label ?? '—' }}</td>
                            <td class="text-right tabular-nums">{{ number_format((float) $record->distance_m, 0) }} m <span class="text-xs text-slate-400">/ {{ $record->radius_m }}</span></td>
                            <td class="text-right tabular-nums">± {{ number_format((float) $record->accuracy_m, 0) }} m</td>
                            <td>
                                <span class="badge {{ $record->decision->badge() }}">{{ $record->decision->label() }}</span>
                                @if ($record->override_by)<div class="text-xs text-slate-500" title="{{ $record->override_reason }}">by {{ $record->overrider?->name }}</div>@endif
                            </td>
                            <td class="text-slate-600">{{ ucfirst($record->source) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-10 text-center text-slate-500">No attendance in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($records->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $records->links() }}</div>
        @endif
    </div>
</div>
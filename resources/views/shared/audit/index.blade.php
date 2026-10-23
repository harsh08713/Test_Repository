<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Audit log</h1>
        <p class="mt-1 text-sm text-slate-500">Every create, update, delete and approval decision in this company, with the values before and after.</p>
    </div>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="grid flex-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div>
                    <label for="f-log" class="sr-only">Area</label>
                    <select id="f-log" wire:model.live="log" class="input"><option value="">All areas</option>@foreach ($logNames as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select>
                </div>
                <div>
                    <label for="f-event" class="sr-only">Event</label>
                    <select id="f-event" wire:model.live="event" class="input"><option value="">All events</option>@foreach ($events as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select>
                </div>
                <div>
                    <label for="f-user" class="sr-only">User</label>
                    <select id="f-user" wire:model.live="user" class="input"><option value="">All users</option>@foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select>
                </div>
                <div><label for="f-from" class="sr-only">From</label><input id="f-from" type="date" wire:model.live="from" class="input" aria-label="From date"></div>
                <div><label for="f-to" class="sr-only">To</label><input id="f-to" type="date" wire:model.live="to" class="input" aria-label="To date"></div>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>When</th><th>User</th><th>Area</th><th>Event</th><th>Record</th><th></th></tr></thead>
                @forelse ($entries as $entry)
                    <tbody wire:key="entry-{{ $entry->id }}">
                        <tr>
                            <td class="whitespace-nowrap">{{ \App\Modules\Shared\Support\Format::dateTime($entry->created_at) }}</td>
                            <td>{{ $entry->causer?->name ?? 'System' }}</td>
                            <td><span class="badge bg-slate-100 text-slate-700">{{ $entry->log_name }}</span></td>
                            <td>{{ $entry->event ?? $entry->description }}</td>
                            <td class="text-slate-500">{{ $entry->subject_type ? $entry->subject_type.' #'.$entry->subject_id : '—' }}</td>
                            <td class="text-right"><button type="button" wire:click="toggle({{ $entry->id }})" class="btn-ghost btn-sm">{{ $expanded === $entry->id ? 'Hide' : 'Details' }}</button></td>
                        </tr>
                        @if ($expanded === $entry->id)
                            @php
                                $new = $entry->properties['attributes'] ?? [];
                                $old = $entry->properties['old'] ?? [];
                                $extra = collect($entry->properties)->except(['attributes', 'old']);
                            @endphp
                            <tr class="bg-slate-50">
                                <td colspan="6" class="px-6 py-4">
                                    <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[max-content_1fr_1fr]">
                                        <dt class="text-xs font-semibold uppercase text-slate-500">Field</dt><dd class="text-xs font-semibold uppercase text-slate-500">Before</dd><dd class="text-xs font-semibold uppercase text-slate-500">After</dd>
                                        @foreach (collect(array_keys($new))->merge(array_keys($old))->unique() as $field)
                                            <dt class="font-mono text-slate-600">{{ $field }}</dt>
                                            <dd class="break-words text-rose-700">{{ is_array($old[$field] ?? null) ? json_encode($old[$field]) : ($old[$field] ?? '—') }}</dd>
                                            <dd class="break-words text-emerald-700">{{ is_array($new[$field] ?? null) ? json_encode($new[$field]) : ($new[$field] ?? '—') }}</dd>
                                        @endforeach
                                    </dl>
                                    @foreach ($extra as $key => $value)
                                        <p class="mt-2 text-sm text-slate-600"><span class="font-medium">{{ $key }}:</span> {{ is_array($value) ? json_encode($value) : $value }}</p>
                                    @endforeach
                                </td>
                            </tr>
                        @endif
                    </tbody>
                @empty
                    <tbody><tr><td colspan="6" class="py-10 text-center text-slate-500">No activity recorded yet.</td></tr></tbody>
                @endforelse
            </table>
        </div>

        @if ($entries->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $entries->links() }}</div>
        @endif
    </div>
</div>

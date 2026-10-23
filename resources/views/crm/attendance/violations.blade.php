<div class="space-y-5">
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">Geofence violation log</h1>
        <p class="mt-1 text-sm text-slate-500">Every attendance attempt, and every lead saved from the field, that the server refused — no route plan, an unusable or inaccurate position, or a position outside the geofence — with what was reported and how far away it was. The log is never edited.</p>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="v-from" class="text-sm text-slate-600">From</label>
                <input id="v-from" type="date" wire:model.live="from" class="input w-40">
                <label for="v-to" class="text-sm text-slate-600">to</label>
                <input id="v-to" type="date" wire:model.live="to" class="input w-40">

                <label for="v-person" class="sr-only">Person</label>
                <select id="v-person" wire:model.live="person" class="input w-52">
                    <option value="">Everyone</option>
                    @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>

                <label for="v-reason" class="sr-only">Reason</label>
                <select id="v-reason" wire:model.live="reason" class="input w-56">
                    <option value="">Any reason</option>
                    @foreach ($reasons as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>When</th><th>Person</th><th>Attempt</th><th>Reason</th><th>What the person was told</th><th class="text-right">Distance</th><th class="text-right">Accuracy</th><th>Outcome</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($violations as $violation)
                        <tr wire:key="viol-{{ $violation->id }}">
                            <td class="whitespace-nowrap tabular-nums">{{ $violation->attempted_at->format('j M, H:i') }}</td>
                            <td class="font-medium text-slate-900">{{ $violation->user?->name }}</td>
                            <td class="whitespace-nowrap text-slate-600">{{ $violation->attempt_type === 'lead_save' ? 'Lead saved in the field' : 'Attendance' }}</td>
                            <td><span class="badge {{ $violation->reason->badge() }}">{{ $violation->reason->label() }}</span></td>
                            <td class="max-w-sm text-slate-600">{{ $violation->message }}
                                @if ($violation->latitude !== null)<div class="font-mono text-xs text-slate-400">{{ $violation->latitude }}, {{ $violation->longitude }}</div>
                                @elseif ($violation->raw_coordinates)<div class="font-mono text-xs text-slate-400">sent: {{ $violation->raw_coordinates }}</div>@endif
                            </td>
                            <td class="text-right tabular-nums">{{ $violation->distance_m !== null ? number_format((float) $violation->distance_m, 0).' m' : '—' }}@if ($violation->radius_m) <span class="text-xs text-slate-400">/ {{ $violation->radius_m }}</span>@endif</td>
                            <td class="text-right tabular-nums">{{ $violation->accuracy_m !== null ? '± '.number_format((float) $violation->accuracy_m, 0).' m' : '—' }}</td>
                            <td class="text-slate-600">
                                @if ($violation->acceptedAs)
                                    <span class="badge bg-amber-100 text-amber-800">Accepted by {{ $violation->acceptedAs->overrider?->name }}</span>
                                @elseif ($violation->attempt_type === 'lead_save') Lead not saved
                                @else Refused @endif
                            </td>
                            <td class="whitespace-nowrap text-right">
                                @can('override', $violation)
                                    @unless ($violation->acceptedAs || $violation->attempt_type !== 'attendance')
                                        <button type="button" wire:click="openAccept({{ $violation->id }})" class="btn-ghost btn-sm">Accept attendance…</button>
                                    @endunless
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-10 text-center text-slate-500">Nothing was refused in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($violations->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $violations->links() }}</div>
        @endif
    </div>

    @if ($accepting !== 0)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="accept-title">
            <form wire:submit="accept" class="card w-full max-w-lg" novalidate>
                <div class="card-header">
                    <h2 id="accept-title" class="text-sm font-semibold text-slate-900">Accept this attendance anyway</h2>
                    <button type="button" wire:click="closeAccept" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="card-body space-y-3">
                    <p class="text-sm text-slate-600">The attendance is recorded as <strong>accepted by you</strong> with the position the person actually reported. The refused attempt stays in the log, and this is audited.</p>
                    <div>
                        <label for="accept-why" class="label">Why are you accepting it?</label>
                        <textarea id="accept-why" rows="3" wire:model="why" class="input" maxlength="1000"></textarea>
                        @error('why') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closeAccept" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">Accept attendance</button>
                </div>
            </form>
        </div>
    @endif
</div>
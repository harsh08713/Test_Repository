@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-5">
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">SLA breaches</h1>
        <p class="mt-1 text-sm text-slate-500">Every time a new lead, a planned follow-up, a task, a sent quotation or a document waiting for approval ran past the time its policy allowed — for you and the people you manage. The log is a record: it is never edited. @can('sla_policy.view')The rules are under <a href="{{ route('workspace.sla.policies') }}" wire:navigate class="text-brand-700 hover:underline">SLA policies</a>.@endcan</p>
    </div>

    <x-status />
    @error('clock')<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror

    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" aria-label="Totals">
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Breaches in these filters</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format($total) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">…of which answered late</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format($late) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Clocks running now</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format($runningCount) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">…already past their time</dt><dd class="text-xl font-semibold tabular-nums {{ $runningLate > 0 ? 'text-rose-700' : 'text-slate-900' }}">{{ number_format($runningLate) }}</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">…paused</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format($paused) }}</dd></div>
    </dl>

    <nav class="-mb-px flex gap-1 border-b border-slate-200" aria-label="Log or running clocks">
        @foreach (['log' => 'Breach log', 'running' => 'Clocks running'] as $value => $label)
            <button type="button" wire:click="$set('tab', '{{ $value }}')" wire:key="tab-{{ $value }}"
                    @class(['whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium', 'border-brand-600 text-brand-700' => $tab === $value, 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $tab !== $value])
                    @if ($tab === $value) aria-current="page" @endif>{{ $label }}</button>
        @endforeach
    </nav>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-end gap-3">
                <div><label for="b-kind" class="block text-xs text-slate-500">Kind</label>
                    <select id="b-kind" wire:model.live="kind" class="input w-64"><option value="">All kinds</option>@foreach ($kinds as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                @if ($tab === 'log')
                    <div><label for="b-from" class="block text-xs text-slate-500">From</label><input id="b-from" type="date" wire:model.live="from" class="input w-40"></div>
                    <div><label for="b-to" class="block text-xs text-slate-500">To</label><input id="b-to" type="date" wire:model.live="to" class="input w-40"></div>
                @endif
                @if (count($people) > 1)
                    <div><label for="b-person" class="block text-xs text-slate-500">Person</label>
                        <select id="b-person" wire:model.live="person" class="input w-48"><option value="">Everyone</option>@foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                @endif
            </div>
        </div>
        <div class="table-wrap">
            @if ($tab === 'log')
                <table class="data-table">
                    <thead><tr><th>Recorded</th><th>Kind</th><th>About</th><th>With</th><th>Was due</th><th>Told</th></tr></thead>
                    <tbody>
                        @forelse ($rows as $breach)
                            <tr wire:key="br-{{ $breach->id }}">
                                <td class="whitespace-nowrap tabular-nums text-slate-600">{{ Format::dateTime($breach->detected_at) }}</td>
                                <td>{{ $breach->kind->short() }} @if ($breach->answered_late)<span class="badge bg-amber-100 text-amber-800">answered late</span>@endif</td>
                                <td>@if ($labels[$breach->id]['url'] !== '')<a href="{{ $labels[$breach->id]['url'] }}" wire:navigate class="text-brand-700 hover:underline">{{ $labels[$breach->id]['label'] }}</a>@else <span class="text-slate-500">{{ $labels[$breach->id]['label'] }}</span>@endif</td>
                                <td class="text-slate-700">{{ $breach->owner?->name ?? 'Nobody (in the pool)' }}</td>
                                <td class="whitespace-nowrap tabular-nums text-slate-600">{{ Format::dateTime($breach->due_at) }}</td>
                                <td class="text-sm text-slate-600">{{ $breach->escalatedTo ? 'Manager: '.$breach->escalatedTo->name : ($breach->answered_late ? '—' : 'The person only') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-10 text-center text-slate-500">No breach in these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @else
                <table class="data-table">
                    <thead><tr><th>Kind</th><th>About</th><th>With</th><th>Started</th><th>Due</th><th>State</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($rows as $clock)
                            <tr wire:key="ck-{{ $clock->id }}">
                                <td>{{ $clock->kind->short() }}</td>
                                <td>@if ($labels[$clock->id]['url'] !== '')<a href="{{ $labels[$clock->id]['url'] }}" wire:navigate class="text-brand-700 hover:underline">{{ $labels[$clock->id]['label'] }}</a>@else <span class="text-slate-500">{{ $labels[$clock->id]['label'] }}</span>@endif</td>
                                <td class="text-slate-700">{{ $clock->owner?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap tabular-nums text-slate-600">{{ Format::dateTime($clock->started_at) }}</td>
                                <td class="whitespace-nowrap tabular-nums {{ $clock->breached_at ? 'font-medium text-rose-700' : 'text-slate-600' }}">{{ Format::dateTime($clock->due_at) }}</td>
                                <td class="text-sm">
                                    @if ($clock->paused_at)<span class="badge bg-sky-100 text-sky-800">Paused</span> <span class="text-xs text-slate-500" title="{{ $clock->pause_reason }}">{{ $clock->pause_reason }}</span>
                                    @elseif ($clock->breached_at)<span class="badge bg-rose-100 text-rose-800">Past its time</span>
                                    @else <span class="badge bg-emerald-100 text-emerald-800">Within time</span> @endif
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    @if ($canPause)
                                        @if ($clock->paused_at)<button type="button" wire:click="resumeClock({{ $clock->id }})" class="btn-secondary">Resume</button>
                                        @elseif (! $clock->breached_at)<button type="button" wire:click="openPause({{ $clock->id }})" class="btn-secondary">Pause</button>@endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-10 text-center text-slate-500">No clock is running for these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </div>
        @if ($rows->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $rows->links() }}</div>@endif
    </div>

    @if ($pausing)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="pause-title">
            <form wire:submit="pauseClock" class="card w-full max-w-lg" novalidate>
                <div class="card-header">
                    <h2 id="pause-title" class="text-sm font-semibold text-slate-900">Pause the clock</h2>
                    <button type="button" wire:click="closePause" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="card-body space-y-3">
                    <p class="text-sm text-slate-600">While it is paused the clock cannot run out. When you resume it, its deadline moves on by the time it was paused. Use it when the customer, not you, is the one being waited for.</p>
                    <div>
                        <label for="p-reason" class="label">What is it waiting for?</label>
                        <textarea id="p-reason" rows="2" wire:model="reason" class="input" maxlength="200"></textarea>
                        @error('reason') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="closePause" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary">Pause</button>
                </div>
            </form>
        </div>
    @endif
</div>

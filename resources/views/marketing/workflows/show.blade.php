@php
    use App\Modules\Shared\Support\Format;
    $stateBadge = ['active' => 'bg-emerald-100 text-emerald-800', 'paused' => 'bg-amber-100 text-amber-800', 'draft' => 'bg-slate-100 text-slate-700'][$workflow->state];
    $runBadge = ['ok' => 'bg-emerald-100 text-emerald-800', 'failed' => 'bg-rose-100 text-rose-800', 'skipped' => 'bg-slate-100 text-slate-700', 'dry_run' => 'bg-sky-100 text-sky-800', 'running' => 'bg-amber-100 text-amber-800'];
    $runLabel = ['ok' => 'Done', 'failed' => 'Failed', 'skipped' => 'Skipped', 'dry_run' => 'Recorded only', 'running' => 'Running'];
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 max-w-3xl">
            <p class="text-sm text-slate-500"><a href="{{ route('marketing.workflows.index') }}" wire:navigate class="hover:text-brand-700">Workflows</a></p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $workflow->name }}</h1>
            <p class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span class="badge {{ $stateBadge }}">{{ ['active' => 'On', 'paused' => 'Paused', 'draft' => 'Not switched on'][$workflow->state] }}</span>
                @if ($workflow->dry_run)<span class="badge bg-sky-100 text-sky-800">Records only — does nothing</span>@endif
                <span class="text-slate-600">Runs as {{ $workflow->runner?->name }}</span>
                <span class="text-slate-500">Version {{ $workflow->current?->version }}</span>
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($can['edit'])<a href="{{ route('marketing.workflows.edit', $workflow) }}" wire:navigate class="btn-secondary">Change</a>@endif
            @if ($can['operate'])
                @if ($workflow->state === 'draft')<button type="button" wire:click="activate" class="btn-primary">Switch on</button>@endif
                @if ($workflow->state === 'active')<button type="button" wire:click="pause" class="btn-secondary">Pause</button>@endif
                @if ($workflow->state === 'paused')<button type="button" wire:click="resume" class="btn-primary">Resume</button>@endif
                <button type="button" wire:click="toggleDryRun" class="btn-secondary">{{ $workflow->dry_run ? 'Make it act' : 'Record only (dry run)' }}</button>
            @endif
        </div>
    </div>

    <x-status />
    @error('workflow')<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror
    @if ($workflow->description)<p class="max-w-3xl text-sm text-slate-600">{{ $workflow->description }}</p>@endif

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card" aria-labelledby="sec-def">
            <div class="card-header"><h2 id="sec-def" class="text-sm font-semibold text-slate-900">What it does</h2></div>
            <div class="card-body space-y-3 text-sm">
                <p><span class="text-xs uppercase text-slate-500">When</span><br>{{ $trigger['label'] ?? $workflow->trigger }}@foreach ($definition['params'] ?? [] as $key => $value) — {{ str_replace('_', ' ', $key) }}: {{ $value }}@endforeach</p>
                <p><span class="text-xs uppercase text-slate-500">Only if</span><br>{{ $conditionText === [] ? 'always' : implode('; and ', $conditionText) }}</p>
                <div><span class="text-xs uppercase text-slate-500">Then</span>
                    <ol class="mt-1 list-decimal space-y-1 pl-5">@foreach ($actionText as $i => $text)<li wire:key="at-{{ $i }}">{{ $text }}@if (($definition['actions'][$i]['title'] ?? null)) — “{{ $definition['actions'][$i]['title'] }}”@endif</li>@endforeach</ol></div>
                <p class="text-xs text-slate-500">It acts on what happens from the moment it is switched on{{ $workflow->activated_at ? ' ('.Format::dateTime($workflow->activated_at).')' : '' }}. Each event runs it once.</p>
            </div>
        </section>

        <section class="card" aria-labelledby="sec-test">
            <div class="card-header"><h2 id="sec-test" class="text-sm font-semibold text-slate-900">Test on a real record <span class="font-normal text-slate-500">(nothing is done)</span></h2></div>
            <div class="card-body space-y-3">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-64 flex-1"><label for="t-sample" class="label">A record this trigger would fire for</label>
                        <select id="t-sample" wire:model="sample" class="input"><option value="">Choose…</option>@foreach ($samples as $s)<option value="{{ $s['type'] }}:{{ $s['id'] }}">{{ $s['label'] }}</option>@endforeach</select>
                        @error('sample') <p class="error">{{ $message }}</p> @enderror</div>
                    <button type="button" wire:click="test" class="btn-secondary">Test</button>
                </div>
                @if ($samples === [])<p class="text-xs text-slate-500">No recent record you may see would have set this off.</p>@endif
                @if ($tested)
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm" role="status">
                        @if (! $tested['found'])<p>That record is not available to you.</p>
                        @elseif (! $tested['matches'])<p class="font-medium text-slate-900">The conditions are not met, so it would do nothing:</p><ul class="mt-1 list-disc pl-5 text-slate-700">@foreach ($tested['failed'] as $f)<li>{{ $f }}</li>@endforeach</ul>
                        @else<p class="font-medium text-slate-900">It would:</p><ol class="mt-1 list-decimal space-y-1 pl-5 text-slate-700">@foreach ($tested['would'] as $w)<li>{{ $w }}</li>@endforeach</ol>@endif
                    </div>
                @endif
            </div>
        </section>
    </div>

    <section class="card" aria-labelledby="sec-runs">
        <div class="card-header flex-wrap">
            <h2 id="sec-runs" class="text-sm font-semibold text-slate-900">Runs</h2>
            <div><label for="r-f" class="sr-only">Show</label><select id="r-f" wire:model.live="runs" class="input w-44"><option value="">All</option><option value="ok">Done</option><option value="failed">Failed</option><option value="skipped">Skipped</option><option value="dry_run">Recorded only</option></select></div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>When</th><th>About</th><th>Result</th><th>What happened</th><th></th></tr></thead>
                <tbody>
                    @forelse ($runRows as $run)
                        <tr wire:key="run-{{ $run->id }}">
                            <td class="whitespace-nowrap text-sm tabular-nums text-slate-600">{{ Format::dateTime($run->executed_at) }}<div class="text-xs text-slate-400">v{{ $run->version?->version }} · try {{ $run->attempts }}</div></td>
                            <td class="text-sm text-slate-700">{{ ucfirst($run->subject_type) }} #{{ $run->subject_id }}</td>
                            <td><span class="badge {{ $runBadge[$run->status] ?? '' }}">{{ $runLabel[$run->status] ?? $run->status }}</span></td>
                            <td class="text-sm text-slate-600">
                                @if ($run->reason)<div>{{ $run->reason }}</div>@endif
                                @foreach ($run->results ?? [] as $r)<div class="{{ $r['ok'] ? 'text-slate-600' : 'text-rose-700' }}" wire:key="rr-{{ $run->id }}-{{ $r['index'] }}">{{ $r['ok'] ? '✓' : '✗' }} {{ $r['note'] }}</div>@endforeach
                            </td>
                            <td class="text-right">@if ($run->status === 'failed' && $can['operate'] && $run->attempts < \App\Modules\Marketing\Services\WorkflowEngine::MAX_ATTEMPTS)<button type="button" wire:click="retryRun({{ $run->id }})" class="btn-secondary">Try again</button>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-slate-500">It has not run yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($runRows->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $runRows->links() }}</div>@endif
    </section>

    <section class="card" aria-labelledby="sec-ver">
        <div class="card-header"><h2 id="sec-ver" class="text-sm font-semibold text-slate-900">Versions</h2></div>
        <ul class="divide-y divide-slate-100">
            @foreach ($workflow->versions as $v)
                <li class="px-5 py-2 text-sm" wire:key="ver-{{ $v->id }}">Version {{ $v->version }} — saved {{ Format::dateTime($v->created_at) }} by {{ $v->saver?->name }}{{ $v->id === $workflow->current_version_id ? ' (current)' : '' }}</li>
            @endforeach
        </ul>
    </section>
</div>

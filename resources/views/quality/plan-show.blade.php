@php $trim = fn ($v) => $v === null ? '' : (rtrim(rtrim((string) $v, '0'), '.') ?: '0'); @endphp
<div class="mx-auto max-w-4xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('quality.plans.index') }}" wire:navigate class="hover:text-brand-700">Inspection plans</a></p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $plan->name }} <span class="font-mono text-base font-normal text-slate-500">{{ $plan->code }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span class="badge {{ $plan->approval_status->badge() }}">{{ $plan->approval_status->label() }}</span>
                @if ($plan->status !== 'active')<span class="badge bg-slate-100 text-slate-700">Inactive</span>@endif
                <span class="text-slate-600">{{ $plan->item?->name }} · {{ $stages[$plan->stage] }}</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canInspect)<a href="{{ route('quality.inspections.create', ['plan' => $plan->id]) }}" wire:navigate class="btn-primary">Start an inspection</a>@endif
            @if ($canEdit)<a href="{{ route('quality.plans.edit', $plan) }}" wire:navigate class="btn-secondary">Edit</a>@endif
            @if ($canToggle)<button type="button" wire:click="toggle" class="btn-ghost">{{ $plan->status === 'active' ? 'Make inactive' : 'Make active' }}</button>@endif
        </div>
    </div>

    <x-status />
    @error('workflow')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    <section class="card" aria-labelledby="s-checks">
        <div class="card-header"><h2 id="s-checks" class="text-sm font-semibold text-slate-900">What is checked</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>#</th><th>Check</th><th>Kind</th><th class="text-right">Allowed</th><th>Critical</th><th>How</th></tr></thead>
                <tbody>
                    @foreach ($plan->parameters as $p)
                        <tr wire:key="pp-{{ $p->id }}">
                            <td class="tabular-nums text-slate-500">{{ $p->line_no }}</td>
                            <td class="font-medium text-slate-900">{{ $p->name }}</td>
                            <td>{{ $p->kind === 'measure' ? 'Measurement' : 'Pass / fail' }}</td>
                            <td class="text-right tabular-nums">@if ($p->kind === 'measure'){{ $p->min_value !== null ? $trim($p->min_value) : '…' }} to {{ $p->max_value !== null ? $trim($p->max_value) : '…' }} {{ $p->unit_text }}@else — @endif</td>
                            <td>{{ $p->is_critical ? 'Yes' : '' }}</td>
                            <td class="text-slate-600">{{ $p->method }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($plan->remarks)<p class="border-t border-slate-100 px-5 py-3 text-sm text-slate-700">{{ $plan->remarks }}</p>@endif
    </section>

    <livewire:shared.approval-panel type="inspection_plan" :id="$plan->id" :key="'ap-'.$plan->id" />

    @if ($history->isNotEmpty())
        <section class="card" aria-labelledby="s-hist">
            <div class="card-header"><h2 id="s-hist" class="text-sm font-semibold text-slate-900">Audit trail</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($history as $entry)
                    <li class="flex justify-between gap-3 px-5 py-2" wire:key="h-{{ $entry->id }}"><span>{{ str_replace('_', ' ', ucfirst((string) ($entry->event ?? $entry->description))) }} · {{ $entry->causer?->name ?? 'System' }}</span><span class="tabular-nums text-xs text-slate-500">{{ $entry->created_at?->format('d-m-Y H:i') }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif
</div>

@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Workflows</h1>
            <p class="mt-1 text-sm text-slate-500">When something happens and the facts match, do something — make a task, tell a manager, hand a lead over, queue an approved message. A workflow runs as the person who last saved it and can do only what that person could do by hand. It cannot approve a document or skip a person’s consent.</p>
        </div>
        @if ($canCreate)<a href="{{ route('marketing.workflows.create') }}" wire:navigate class="btn-primary">New workflow</a>@endif
    </div>

    <x-status />

    <div class="card">
        <div class="card-header"><div><label for="w-st" class="block text-xs text-slate-500">State</label><select id="w-st" wire:model.live="state" class="input w-40"><option value="">All</option><option value="active">On</option><option value="paused">Paused</option><option value="draft">Not switched on</option></select></div></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Workflow</th><th>Starts when</th><th>State</th><th>Runs as</th><th class="text-right">Done</th><th class="text-right">Failed</th><th>Changed</th></tr></thead>
                <tbody>
                    @forelse ($workflows as $workflow)
                        <tr wire:key="wf-{{ $workflow->id }}">
                            <td><a href="{{ route('marketing.workflows.show', $workflow) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $workflow->name }}</a></td>
                            <td class="text-sm text-slate-600">{{ $triggers[$workflow->trigger]['label'] ?? $workflow->trigger }}</td>
                            <td><span @class(['badge', 'bg-emerald-100 text-emerald-800' => $workflow->state === 'active', 'bg-amber-100 text-amber-800' => $workflow->state === 'paused', 'bg-slate-100 text-slate-700' => $workflow->state === 'draft'])>{{ ['active' => 'On', 'paused' => 'Paused', 'draft' => 'Not switched on'][$workflow->state] }}</span>@if ($workflow->dry_run)<span class="badge bg-sky-100 text-sky-800">Records only</span>@endif</td>
                            <td class="text-sm text-slate-700">{{ $workflow->runner?->name }}</td>
                            <td class="text-right tabular-nums">{{ number_format($workflow->runs_ok) }}</td>
                            <td class="text-right tabular-nums {{ $workflow->runs_failed > 0 ? 'font-medium text-rose-700' : '' }}">{{ number_format($workflow->runs_failed) }}</td>
                            <td class="whitespace-nowrap text-sm tabular-nums text-slate-600">{{ Format::date($workflow->updated_at) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-slate-500">No workflow yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($workflows->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $workflows->links() }}</div>@endif
    </div>
</div>

@php
    use App\Modules\Shared\Support\Format;
    $stateBadge = ['draft' => 'bg-slate-100 text-slate-700', 'running' => 'bg-emerald-100 text-emerald-800', 'paused' => 'bg-amber-100 text-amber-800', 'completed' => 'bg-sky-100 text-sky-800', 'cancelled' => 'bg-rose-100 text-rose-800'];
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Campaigns</h1>
            <p class="mt-1 text-sm text-slate-500">Messages to leads or customers, in a sequence, from an approved template — sent only to people who agreed, a few a week at most, each with an unsubscribe link. A campaign is approved by someone else before it is launched.</p>
        </div>
        @if ($canCreate)<a href="{{ route('marketing.campaigns.create') }}" wire:navigate class="btn-primary">New campaign</a>@endif
    </div>

    <x-status />

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-end gap-3">
                <div><label for="c-q" class="block text-xs text-slate-500">Search</label><input id="c-q" type="search" wire:model.live.debounce.300ms="search" class="input w-52" placeholder="Number or name…"></div>
                <div><label for="c-state" class="block text-xs text-slate-500">State</label>
                    <select id="c-state" wire:model.live="state" class="input w-40"><option value="">All</option><option value="draft">Draft</option><option value="running">Running</option><option value="paused">Paused</option><option value="completed">Finished</option><option value="cancelled">Called off</option></select></div>
                <div><label for="c-ap" class="block text-xs text-slate-500">Approval</label>
                    <select id="c-ap" wire:model.live="approval" class="input w-44"><option value="">Any</option><option value="draft">Draft</option><option value="pending">Waiting</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select></div>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Campaign</th><th>Owner</th><th>State</th><th>Approval</th><th>Dates</th><th class="text-right">People</th><th class="text-right">Messages</th><th class="text-right">Responded</th><th class="text-right">Leads</th></tr></thead>
                <tbody>
                    @forelse ($campaigns as $campaign)
                        <tr wire:key="cmp-{{ $campaign->id }}">
                            <td><a href="{{ route('marketing.campaigns.show', $campaign) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $campaign->name }}</a><div class="font-mono text-xs text-slate-500">{{ $campaign->code }}</div></td>
                            <td class="text-slate-700">{{ $campaign->owner?->name }}</td>
                            <td><span class="badge {{ $stateBadge[$campaign->state] }}">{{ ['draft' => 'Draft', 'running' => 'Running', 'paused' => 'Paused', 'completed' => 'Finished', 'cancelled' => 'Called off'][$campaign->state] }}</span></td>
                            <td><span class="badge {{ $campaign->approval_status->badge() }}">{{ $campaign->approval_status->label() }}</span></td>
                            <td class="whitespace-nowrap text-sm tabular-nums text-slate-600">{{ Format::date($campaign->start_date) }}{{ $campaign->end_date ? ' – '.Format::date($campaign->end_date) : '' }}</td>
                            <td class="text-right tabular-nums">{{ number_format($campaign->members_count) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($campaign->messages_count) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($campaign->responded_count) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($campaign->leads_count) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-10 text-center text-slate-500">No campaign yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($campaigns->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $campaigns->links() }}</div>@endif
    </div>
</div>

@php
    use App\Modules\Shared\Support\Format;
    $stateLabel = ['draft' => 'Draft', 'running' => 'Running', 'paused' => 'Paused', 'completed' => 'Finished', 'cancelled' => 'Called off'][$campaign->state];
    $stateBadge = ['draft' => 'bg-slate-100 text-slate-700', 'running' => 'bg-emerald-100 text-emerald-800', 'paused' => 'bg-amber-100 text-amber-800', 'completed' => 'bg-sky-100 text-sky-800', 'cancelled' => 'bg-rose-100 text-rose-800'][$campaign->state];
    $exitText = ['responded' => 'Responded', 'stage_reached' => 'Their lead moved on', 'opted_out' => 'Opted out', 'no_consent' => 'Never agreed to marketing', 'no_address' => 'No address for the first message', 'record_gone' => 'The record is gone', 'campaign_cancelled' => 'Campaign called off', 'campaign_ended' => 'Campaign ended'];
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 max-w-3xl">
            <p class="text-sm text-slate-500"><a href="{{ route('marketing.campaigns.index') }}" wire:navigate class="hover:text-brand-700">Campaigns</a> › {{ $campaign->location?->name }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $campaign->name }} <span class="font-mono text-base font-normal text-slate-500">{{ $campaign->code }}</span></h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span class="badge {{ $stateBadge }}">{{ $stateLabel }}</span>
                <span class="badge {{ $campaign->approval_status->badge() }}">{{ $campaign->approval_status->label() }}</span>
                <span class="text-slate-600">Owner {{ $campaign->owner?->name }}</span>
                <span class="text-slate-600">{{ Format::date($campaign->start_date) }}{{ $campaign->end_date ? ' to '.Format::date($campaign->end_date) : '' }}</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($can['edit'])<a href="{{ route('marketing.campaigns.edit', $campaign) }}" wire:navigate class="btn-secondary">Change</a>@endif
            @if ($can['launch'])<button type="button" wire:click="launch" wire:confirm="Launch the campaign? Its audience is fixed now and the first messages go out." class="btn-primary">Launch</button>@endif
            @if ($campaign->state === 'running' && $can['control'])<button type="button" wire:click="pause" class="btn-secondary">Pause</button>@endif
            @if ($campaign->state === 'paused' && $can['control'])<button type="button" wire:click="resume" class="btn-secondary">Resume</button>@endif
            @if ($can['reopen'])<button type="button" wire:click="openDialog('reopen')" class="btn-secondary">Re-open for editing</button>@endif
            @if ($can['control'] || $can['cancelDraft'])<button type="button" wire:click="openDialog('cancel')" class="btn-secondary">Call off</button>@endif
        </div>
    </div>

    <x-status />
    @error('workflow')<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror
    @if ($campaign->state === 'cancelled')<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800" role="status">Called off: {{ $campaign->cancel_reason }}</p>@endif

    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Results so far">
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">People in it</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format($stats['people']) }}</dd><dd class="text-xs text-slate-500">{{ number_format($stats['active']) }} still in the sequence</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Messages handed to the mail server</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format($stats['sent']) }}</dd><dd class="text-xs text-slate-500">{{ number_format($stats['failed']) }} failed · {{ number_format($stats['held']) }} not sent (consent, limit, channel)</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Responded</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format($stats['responded']) }}</dd><dd class="text-xs text-slate-500">{{ number_format($stats['leads']) }} leads credited · {{ number_format($stats['deals']) }} deals</dd></div>
        <div class="card px-5 py-3"><dt class="text-xs text-slate-500">Approved sales credited <span class="badge bg-emerald-100 text-emerald-800">Actual</span></dt><dd class="text-xl font-semibold tabular-nums text-slate-900">₹ {{ Format::number($stats['sales'], 0) }}</dd><dd class="text-xs text-slate-500">{{ number_format($stats['orders']) }} orders, net of tax{{ $campaign->budget ? ' · budget ₹ '.Format::number($campaign->budget, 0) : '' }}</dd></div>
    </dl>
    <p class="text-xs text-slate-500">“Handed to the mail server” is as far as the system can know: delivery, opens and clicks need a provider’s report, and none is connected.</p>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card" aria-labelledby="sec-about">
            <div class="card-header"><h2 id="sec-about" class="text-sm font-semibold text-slate-900">The campaign</h2></div>
            <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-2">
                <div><dt class="text-xs text-slate-500">Kind</dt><dd class="text-sm text-slate-900">{{ \App\Modules\Marketing\Services\CampaignService::TYPES[$campaign->type] ?? $campaign->type }}</dd></div>
                <div><dt class="text-xs text-slate-500">Writes to</dt><dd class="text-sm text-slate-900">{{ $campaign->audience_kind === 'leads' ? 'Leads' : 'Customers' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Lead source</dt><dd class="text-sm text-slate-900">{{ $campaign->source?->name ?? '—' }}</dd></div>
                <div><dt class="text-xs text-slate-500">UTM</dt><dd class="text-sm text-slate-900">{{ collect([$campaign->utm_source, $campaign->utm_medium, $campaign->utm_campaign])->filter()->implode(' / ') ?: '—' }}</dd></div>
                @if ($campaign->objective)<div class="sm:col-span-2"><dt class="text-xs text-slate-500">For</dt><dd class="whitespace-pre-line text-sm text-slate-900">{{ $campaign->objective }}</dd></div>@endif
                <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Audience</dt><dd class="text-sm text-slate-900">
                    @php $aud = $campaign->audience ?? []; @endphp
                    {{ $aud === [] ? 'Everyone who is open' : collect($aud)->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.(is_array($v) ? implode(', ', $v) : $v))->implode(' · ') }}
                </dd></div>
                <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Leaves the sequence when their lead reaches</dt><dd class="text-sm text-slate-900">{{ $campaign->exit_stages ? implode(', ', $campaign->exit_stages) : 'no stage (only a response or an opt-out ends it)' }}</dd></div>
            </dl>
        </section>

        <section class="card" aria-labelledby="sec-steps">
            <div class="card-header"><h2 id="sec-steps" class="text-sm font-semibold text-slate-900">The messages, in order</h2></div>
            <ol class="divide-y divide-slate-100">
                @foreach ($campaign->steps as $step)
                    <li class="px-5 py-3 text-sm" wire:key="st-{{ $step->id }}">
                        <div class="font-medium text-slate-900">{{ $step->position }}. {{ $step->template?->name }} <span class="text-xs font-normal text-slate-500">{{ $step->template?->channel->label() }}, {{ $step->template?->purpose }}</span></div>
                        <div class="text-xs text-slate-500">{{ $step->position === 1 ? ($step->delay_hours === 0 ? 'At once' : $step->delay_hours.' hours after launch') : $step->delay_hours.' hours after step '.($step->position - 1) }}{{ $step->template?->subject ? ' · “'.$step->template->subject.'”' : '' }}</div>
                    </li>
                @endforeach
            </ol>
        </section>
    </div>

    @if ($campaign->launched_at)
        <section class="card" aria-labelledby="sec-members">
            <div class="card-header flex-wrap">
                <h2 id="sec-members" class="text-sm font-semibold text-slate-900">People</h2>
                <div><label for="m-f" class="sr-only">Show</label>
                    <select id="m-f" wire:model.live="people" class="input w-44"><option value="">Everyone</option><option value="active">In the sequence</option><option value="completed">Finished all steps</option><option value="exited">Left early</option><option value="suppressed">Not written to</option><option value="responded">Responded</option></select></div>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Name</th><th>Contact</th><th>State</th><th>Next step</th><th>Last message</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($members as $member)
                            <tr wire:key="mem-{{ $member->id }}">
                                <td class="font-medium text-slate-900">
                                    @if ($member->subject_type === 'lead')<a href="{{ route('crm.leads.show', $member->subject_id) }}" wire:navigate class="text-brand-700 hover:underline">{{ $member->name }}</a>
                                    @else<a href="{{ route('crm.customers.show', $member->subject_id) }}" wire:navigate class="text-brand-700 hover:underline">{{ $member->name }}</a>@endif
                                </td>
                                <td class="text-sm text-slate-600">{{ $member->email ?: $member->mobile ?: '—' }}</td>
                                <td class="text-sm">
                                    @if ($member->responded_at)<span class="badge bg-emerald-100 text-emerald-800">Responded</span>
                                    @elseif ($member->status === 'active')<span class="badge bg-sky-100 text-sky-800">In the sequence</span>
                                    @elseif ($member->status === 'completed')<span class="badge bg-slate-100 text-slate-700">Finished</span>
                                    @else<span class="badge bg-amber-100 text-amber-800">{{ $exitText[$member->exit_reason] ?? ucfirst($member->status) }}</span>@endif
                                </td>
                                <td class="whitespace-nowrap text-sm tabular-nums text-slate-600">{{ $member->status === 'active' && $member->next_due_at ? 'Step '.$member->next_step.' · '.Format::dateTime($member->next_due_at) : '—' }}</td>
                                <td class="whitespace-nowrap text-sm tabular-nums text-slate-600">{{ $member->last_sent_at ? Format::dateTime($member->last_sent_at) : '—' }}</td>
                                <td class="text-right">@if ($can['control'] && ! $member->responded_at)<button type="button" wire:click="openDialog('respond', {{ $member->id }})" class="btn-secondary">They responded</button>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-8 text-center text-slate-500">Nobody matches.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($members->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $members->links() }}</div>@endif
        </section>

        <section class="card" aria-labelledby="sec-msgs">
            <div class="card-header"><h2 id="sec-msgs" class="text-sm font-semibold text-slate-900">Latest messages</h2><a href="{{ route('marketing.messages', ['campaign' => $campaign->id]) }}" wire:navigate class="text-xs text-brand-700 hover:underline">All of them</a></div>
            <ul class="divide-y divide-slate-100">
                @forelse ($messages as $message)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2 text-sm" wire:key="msg-{{ $message->id }}"><span class="text-slate-800">{{ $message->to_name }} <span class="text-xs text-slate-500">· {{ $message->subject ?? $message->channel->label() }}</span></span><span class="text-xs text-slate-600">{{ $message->statusLabel() }} · {{ Format::dateTime($message->queued_at) }}</span></li>
                @empty
                    <li class="px-5 py-4 text-sm text-slate-500">Nothing has been sent yet.</li>
                @endforelse
            </ul>
        </section>
    @endif

    <livewire:shared.approval-panel type="campaign" :id="$campaign->id" :key="'ap-'.$campaign->id" />
    <livewire:shared.comments type="campaign" :id="$campaign->id" :key="'cm-'.$campaign->id" />

    @if ($dialog !== '')
        @php
            [$title, $action, $button] = match ($dialog) {
                'cancel' => ['Call the campaign off', 'cancelCampaign', 'Call off'],
                'reopen' => ['Re-open for editing', 'reopenCampaign', 'Re-open'],
                default => ['This person responded', 'markResponded', 'Mark as responded'],
            };
        @endphp
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="dialog-title">
            <form wire:submit="{{ $action }}" class="card w-full max-w-lg" novalidate wire:key="dialog-{{ $dialog }}">
                <div class="card-header"><h2 id="dialog-title" class="text-sm font-semibold text-slate-900">{{ $title }}</h2>
                    <button type="button" wire:click="closeDialog" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button></div>
                <div class="card-body space-y-3">
                    @if ($dialog === 'cancel')<p class="text-sm text-slate-600">Everyone still in the sequence leaves it, and nothing more is sent. What was already sent stays on record.</p>
                    @elseif ($dialog === 'reopen')<p class="text-sm text-slate-600">The campaign goes back to a draft that has to be approved again. This is possible only before it has been launched.</p>
                    @else<p class="text-sm text-slate-600">Their sequence ends: no further messages go to them from this campaign, and the campaign is credited with the response.</p>@endif
                    <div><label for="d-reason" class="label">{{ $dialog === 'respond' ? 'Note (optional)' : 'Why?' }}</label><textarea id="d-reason" rows="2" wire:model="reason" class="input" maxlength="500"></textarea>@error('reason') <p class="error">{{ $message }}</p> @enderror</div>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><button type="button" wire:click="closeDialog" class="btn-secondary">Cancel</button><button type="submit" class="btn-primary">{{ $button }}</button></div>
            </form>
        </div>
    @endif
</div>

@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-5">
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">Message log</h1>
        <p class="mt-1 text-sm text-slate-500">Every message the system sent or decided not to send, with the words as they went out. “Handed to the mail server” is as far as it can know — delivery, opens and clicks need a provider’s report. A message that was not sent says why: no consent, opted out, the weekly limit, or no provider connected for that channel.</p>
    </div>

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-end gap-3">
                <div><label for="m-q" class="block text-xs text-slate-500">Search</label><input id="m-q" type="search" wire:model.live.debounce.300ms="search" class="input w-52" placeholder="Name or address…"></div>
                <div><label for="m-ch" class="block text-xs text-slate-500">Channel</label><select id="m-ch" wire:model.live="channel" class="input w-36"><option value="">All</option>@foreach ($channels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                <div><label for="m-st" class="block text-xs text-slate-500">What happened</label><select id="m-st" wire:model.live="status" class="input w-56"><option value="">Anything</option><option value="sent">Handed to the mail server</option><option value="queued">Waiting</option><option value="failed">Failed</option><option value="suppressed">Not sent (consent or limit)</option><option value="not_connected">Not sent (channel not connected)</option></select></div>
                <div><label for="m-ca" class="block text-xs text-slate-500">Campaign</label><select id="m-ca" wire:model.live="campaign" class="input w-52"><option value="">Any</option>@foreach ($campaigns as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                <div><label for="m-from" class="block text-xs text-slate-500">From</label><input id="m-from" type="date" wire:model.live="from" class="input w-40"></div>
                <div><label for="m-to" class="block text-xs text-slate-500">To</label><input id="m-to" type="date" wire:model.live="to" class="input w-40"></div>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>When</th><th>To</th><th>Channel</th><th>Message</th><th>Campaign</th><th>What happened</th></tr></thead>
                <tbody>
                    @forelse ($messages as $m)
                        <tr wire:key="cm-{{ $m->id }}">
                            <td class="whitespace-nowrap text-sm tabular-nums text-slate-600">{{ Format::dateTime($m->queued_at) }}</td>
                            <td><div class="font-medium text-slate-900">{{ $m->to_name }}</div><div class="font-mono text-xs text-slate-500">{{ $m->to_address }}</div></td>
                            <td>{{ $m->channel->label() }}</td>
                            <td class="max-w-xs"><button type="button" wire:click="read({{ $m->id }})" class="truncate text-left text-sm text-brand-700 hover:underline">{{ $m->subject ?? \Illuminate\Support\Str::limit($m->body, 50) }}</button></td>
                            <td class="text-sm text-slate-600">{{ $m->campaign ? $m->campaign->code : '—' }}</td>
                            <td class="text-sm"><span @class(['badge', 'bg-emerald-100 text-emerald-800' => $m->status === 'sent', 'bg-rose-100 text-rose-800' => $m->status === 'failed', 'bg-amber-100 text-amber-800' => in_array($m->status, ['suppressed', 'not_connected'], true), 'bg-slate-100 text-slate-700' => in_array($m->status, ['queued', 'sending', 'cancelled'], true)])>{{ $m->statusLabel() }}</span>@if ($m->failure)<div class="mt-1 max-w-xs text-xs text-slate-500">{{ $m->failure }}</div>@endif</td>
                        </tr>
                        @if ($reading === $m->id)
                            <tr wire:key="cmb-{{ $m->id }}"><td colspan="6" class="bg-slate-50 px-5 py-3"><p class="text-xs font-medium text-slate-700">{{ $m->subject }}</p><p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $m->body }}</p></td></tr>
                        @endif
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-slate-500">No message matches.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($messages->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $messages->links() }}</div>@endif
    </div>
</div>

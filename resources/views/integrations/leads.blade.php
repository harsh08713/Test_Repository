<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Inbound lead log</h1>
            <p class="mt-1 text-sm text-slate-500">Every lead IndiaMART and Just Dial handed over, exactly as it arrived (without secrets), and what became of it. A delivery that failed keeps its contents: put right what its message says, then retry it. Rows are removed after the retention period set in Company settings.</p>
        </div>
        <a href="{{ route('admin.integrations') }}" wire:navigate class="btn-secondary">Back to integrations</a>
    </div>

    @if ($note !== '')
        <div class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-800" role="status">{{ $note }}</div>
    @endif

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <label for="g-search" class="sr-only">Search</label>
                <input id="g-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Provider id, lead number or name…" class="input max-w-xs">

                <label for="g-provider" class="sr-only">Provider</label>
                <select id="g-provider" wire:model.live="provider" class="input w-44">
                    <option value="">Every provider</option>
                    @foreach ($providers as $key => $p)<option value="{{ $key }}">{{ $p->label() }}</option>@endforeach
                </select>

                <label for="g-status" class="sr-only">Status</label>
                <select id="g-status" wire:model.live="status" class="input w-56">
                    <option value="">Any status</option>
                    @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
                </select>

                <label for="g-from" class="text-sm text-slate-600">From</label>
                <input id="g-from" type="date" wire:model.live="from" class="input w-40">
                <label for="g-to" class="text-sm text-slate-600">to</label>
                <input id="g-to" type="date" wire:model.live="to" class="input w-40">
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Received</th><th>Provider</th><th>Provider’s lead id</th><th>Status</th><th>Lead</th><th class="text-right">Tries</th><th class="text-right">Sent</th><th>What happened</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr wire:key="log-{{ $log->id }}">
                            <td class="whitespace-nowrap tabular-nums">{{ $log->created_at?->format('j M, H:i') }}<span class="block text-xs text-slate-400">{{ $log->via === 'webhook' ? 'pushed' : ($log->via === 'pull' ? 'pulled' : $log->via) }}</span></td>
                            <td>{{ $providers[$log->provider]?->label() ?? $log->provider }}</td>
                            <td class="font-mono text-xs">{{ $log->provider_lead_id }}</td>
                            <td><span class="badge {{ $log->status->badge() }}">{{ $log->status->label() }}</span></td>
                            <td class="whitespace-nowrap">
                                @if ($log->lead)
                                    @if (isset($openable[$log->lead->id]))
                                        <a href="{{ route('crm.leads.show', $log->lead) }}" wire:navigate class="text-brand-700 hover:underline">{{ $log->lead->lead_number }}</a>
                                    @else
                                        {{ $log->lead->lead_number }}
                                    @endif
                                @else — @endif
                            </td>
                            <td class="text-right tabular-nums">{{ $log->attempts }}</td>
                            <td class="text-right tabular-nums">{{ $log->received_count }}×</td>
                            <td class="max-w-md text-slate-600">{{ $log->error ?? ($log->status->value === 'linked' ? 'The same mobile number is already on this open lead; a note was left on it.' : '') }}</td>
                            <td class="whitespace-nowrap text-right">
                                <button type="button" wire:click="inspect({{ $log->id }})" class="btn-ghost btn-sm">View</button>
                                @if ($canRetry && $log->status->value === 'failed')
                                    <button type="button" wire:click="retry({{ $log->id }})" wire:loading.attr="disabled" class="btn-secondary btn-sm">Retry</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-10 text-center text-slate-500">Nothing has been received yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($logs->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $logs->links() }}</div>
        @endif
    </div>

    @if ($open)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="payload-title">
            <div class="card w-full max-w-2xl">
                <div class="card-header">
                    <h2 id="payload-title" class="text-sm font-semibold text-slate-900">{{ $providers[$open->provider]?->label() ?? $open->provider }} lead {{ $open->provider_lead_id }}</h2>
                    <button type="button" wire:click="closeInspector" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="card-body space-y-3">
                    <p class="text-sm text-slate-600">As received, with anything secret removed. It holds the buyer’s contact details: handle it like the lead itself.</p>
                    @if ($open->error)<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $open->error }}</p>@endif
                    <pre class="max-h-96 overflow-auto rounded-lg bg-slate-900 p-3 text-xs leading-relaxed text-slate-100">{{ json_encode($open->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
                <div class="flex justify-end border-t border-slate-200 px-5 py-3"><button type="button" wire:click="closeInspector" class="btn-secondary">Close</button></div>
            </div>
        </div>
    @endif
</div>

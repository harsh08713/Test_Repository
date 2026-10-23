<div class="mx-auto max-w-4xl space-y-5">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Channels</h1>
        <p class="mt-1 text-sm text-slate-500">WhatsApp and SMS for campaigns and workflows. E-mail needs nothing here. A message is only ever handed over to a channel that is connected, and only to someone who has agreed to it; a reply of STOP is recorded as an opt-out. The credentials are saved in
            <a href="{{ route('admin.settings') }}" wire:navigate class="text-brand-700 hover:underline">Company settings</a> (group “Channels”).</p>
    </div>

    <x-status />

    @foreach ($rows as $row)
        <div class="card" wire:key="ch-{{ $row['channel']->value }}">
            <div class="card-header flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-900">{{ $row['channel']->label() }}</h2>
                <span @class(['badge', 'bg-emerald-100 text-emerald-800' => $row['connected'] && $row['mode'] === 'live', 'bg-amber-100 text-amber-800' => $row['mode'] === 'sandbox', 'bg-slate-100 text-slate-700' => $row['mode'] === 'off', 'bg-rose-100 text-rose-800' => $row['mode'] === 'live' && ! $row['connected']])>
                    {{ $row['mode'] === 'live' && ! $row['connected'] ? 'Live — incomplete' : $row['modes'][$row['mode']] }}
                </span>
            </div>
            <div class="card-body space-y-2 text-sm text-slate-600">
                @if ($row['mode'] === 'off')
                    <p>Off: messages for this channel are kept as “not connected” and never sent.</p>
                @elseif ($row['mode'] === 'sandbox')
                    <p>Sandbox: messages are marked as handed over but nothing leaves the system. Use it to rehearse a campaign.</p>
                @elseif ($row['missing'] !== [])
                    <p class="text-rose-700">Live, but still missing: {{ implode(', ', $row['missing']) }}. Until then, messages are kept as “not connected”.</p>
                @else
                    <p>Live: messages are handed to the provider.</p>
                @endif

                @if ($row['mode'] !== 'off')
                    @if ($row['url'])
                        <div><span class="font-medium text-slate-800">Give the provider this address for delivery reports{{ $row['channel']->value === 'sms' ? ' and replies' : ' and messages' }}:</span>
                            <code class="mt-1 block break-all rounded bg-slate-50 px-2 py-1 text-xs text-slate-700">{{ $row['url'] }}</code></div>
                    @elseif (! $hasToken)
                        <p>No webhook address yet: make one below.</p>
                    @else
                        <p>The webhook address is shown to people who may change settings.</p>
                    @endif
                @endif
            </div>
        </div>
    @endforeach

    @if ($canEdit)
        <div class="card card-body flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
            <p>{{ $hasToken ? 'The webhook address holds a secret token. Make a new one if it may have leaked; the old address stops working at once.' : 'Make the secret webhook address that providers report to.' }}</p>
            <button type="button" wire:click="makeToken" @if ($hasToken) wire:confirm="Make a new address? The providers must be given the new one." @endif class="btn-secondary">{{ $hasToken ? 'Make a new address' : 'Make the address' }}</button>
        </div>
    @endif
</div>

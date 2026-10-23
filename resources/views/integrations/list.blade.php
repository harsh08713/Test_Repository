<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Integrations</h1>
            <p class="mt-1 text-sm text-slate-500">Where leads come from outside: IndiaMART and Just Dial. Each lead that arrives is written to a log first, then becomes a lead in the branch you choose — or, if the same mobile number is already on an open lead, a note on that one. The API key and the signing secret are kept in <a href="{{ route('admin.settings') }}" wire:navigate class="text-brand-700 underline">Company settings</a>.</p>
        </div>
        <a href="{{ route('admin.integrations.leads') }}" wire:navigate class="btn-secondary">Inbound lead log</a>
    </div>

    @foreach ($rows as $row)
        @php
            $provider = $row['provider'];
            $c = $row['connection'];
            $status = $c->status();
            $key = $provider->key();
            $note = $notes[$key] ?? null;
        @endphp
        <div class="card" wire:key="conn-{{ $key }}">
            <div class="card-header flex-wrap">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 class="text-lg font-semibold text-slate-900">{{ $provider->label() }}</h2>
                    <span class="badge {{ $status->badge() }}">{{ $status->label() }}</span>
                    @if ($c->enabled)
                        <span class="text-xs text-slate-500">{{ ['pull' => 'We pull its leads', 'webhook' => 'It pushes leads to us', 'both' => 'Pull and push'][$c->mode] }}</span>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($canRun && $provider->canPull())
                        <button type="button" wire:click="test('{{ $key }}')" wire:loading.attr="disabled" class="btn-secondary btn-sm">Test connection</button>
                        @if ($c->enabled && $c->pulls())
                            <button type="button" wire:click="pullNow('{{ $key }}')" wire:loading.attr="disabled" class="btn-secondary btn-sm">Pull now</button>
                        @endif
                    @endif
                    @if ($canEdit)
                        <button type="button" wire:click="edit('{{ $key }}')" class="btn-primary btn-sm">{{ $c->enabled ? 'Settings' : 'Set up' }}</button>
                    @endif
                </div>
            </div>

            <div class="card-body space-y-4">
                @if ($note)
                    <div @class([
                        'rounded-lg border px-3 py-2 text-sm',
                        'border-emerald-200 bg-emerald-50 text-emerald-800' => $note[0] === 'success',
                        'border-rose-200 bg-rose-50 text-rose-800' => $note[0] === 'error',
                        'border-sky-200 bg-sky-50 text-sky-800' => $note[0] === 'info',
                    ]) role="status">{{ $note[1] }}</div>
                @endif

                @if ($c->consecutive_failures > 0 && $c->last_error)
                    <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800" role="alert">
                        <strong>{{ $c->consecutive_failures }} failed attempt{{ $c->consecutive_failures === 1 ? '' : 's' }} in a row.</strong> {{ $c->last_error }}
                        @if ($c->next_pull_at && $c->pulls()) <span class="block text-xs">Next try {{ $c->next_pull_at->format('j M, H:i') }}.</span> @endif
                    </div>
                @endif

                <dl class="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div><dt class="text-slate-500">Last success</dt><dd class="font-medium text-slate-900">{{ $c->last_success_at?->format('j M, H:i') ?? ($c->receivesPushes() && $c->last_received_at ? 'Push at '.$c->last_received_at->format('j M, H:i') : '—') }}</dd></div>
                    <div><dt class="text-slate-500">Received today</dt><dd class="font-medium tabular-nums text-slate-900">{{ number_format($row['today']) }}</dd></div>
                    <div><dt class="text-slate-500">Waiting / failed</dt><dd class="font-medium tabular-nums text-slate-900">{{ number_format(($row['counts']['received'] ?? 0) + ($row['counts']['processing'] ?? 0)) }} / <span class="{{ ($row['counts']['failed'] ?? 0) > 0 ? 'text-rose-700' : '' }}">{{ number_format($row['counts']['failed'] ?? 0) }}</span></dd></div>
                    <div><dt class="text-slate-500">Leads made / added to existing</dt><dd class="font-medium tabular-nums text-slate-900">{{ number_format($row['counts']['created'] ?? 0) }} / {{ number_format($row['counts']['linked'] ?? 0) }}</dd></div>
                    <div><dt class="text-slate-500">New leads go to</dt><dd class="font-medium text-slate-900">{{ $c->branch?->name ?? '—' }}, {{ $c->assigned_to ? ($c->owner?->name ?? 'a person') : 'the pool (unassigned)' }}</dd></div>
                    <div><dt class="text-slate-500">Entered as</dt><dd class="font-medium text-slate-900">{{ $c->actor?->name ?? '—' }}</dd></div>
                    @if ($provider->canPull())
                        <div><dt class="text-slate-500">API key</dt><dd><span class="badge {{ $row['apiKeySet'] ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $row['apiKeySet'] ? 'Saved' : 'Not set' }}</span></dd></div>
                    @endif
                    @if ($c->receivesPushes())
                        <div><dt class="text-slate-500">Pushes are</dt><dd><span class="badge {{ $row['signed'] ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $row['signed'] ? 'Signed' : 'Unsigned (the address is the only secret)' }}</span></dd></div>
                    @endif
                </dl>

                @if ($canEdit && $c->receivesPushes())
                    <div>
                        <label class="label" for="url-{{ $key }}">Webhook address — give this to {{ $provider->label() }}</label>
                        <div class="flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                            <input id="url-{{ $key }}" type="text" readonly value="{{ $c->webhookUrl() }}" class="input max-w-2xl font-mono text-xs" x-ref="url" onfocus="this.select()">
                            <button type="button" class="btn-secondary btn-sm" x-on:click="navigator.clipboard.writeText($refs.url.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"><span x-show="!copied">Copy</span><span x-show="copied" x-cloak>Copied</span></button>
                            @if ($canRun)
                                <button type="button" wire:click="askForNewAddress('{{ $key }}')" class="btn-ghost btn-sm">New address…</button>
                            @endif
                        </div>
                        <p class="help">Anyone holding this address can send leads, so treat it like a password. @unless ($provider->canPull()) {{ $provider->label() }} has no self-service setting for it: send the address to your {{ $provider->label() }} account manager. @endunless</p>
                    </div>
                @endif

                @if ($confirming === $key)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alertdialog" aria-label="New webhook address">
                        <p>A new address stops the old one at once. Until {{ $provider->label() }} has the new one, nothing it sends will be accepted. Continue?</p>
                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="newAddress" class="btn-primary btn-sm">Make a new address</button>
                            <button type="button" wire:click="cancelNewAddress" class="btn-secondary btn-sm">Keep the old one</button>
                        </div>
                    </div>
                @endif

                @if ($editing === $key)
                    <form wire:submit="save" class="space-y-4 rounded-lg border border-slate-200 bg-slate-50 p-4" novalidate>
                        <label class="flex items-start gap-3 text-sm">
                            <input type="checkbox" wire:model="form.enabled" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <span><span class="font-medium text-slate-800">Switch {{ $provider->label() }} on</span><span class="help block">Only leads that arrive while it is on are taken.</span></span>
                        </label>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="f-mode" class="label">How leads reach us</label>
                                <select id="f-mode" wire:model="form.mode" class="input">
                                    @if ($provider->canPull())<option value="pull">We pull them (needs the API key)</option>@endif
                                    @if ($provider->canPush())<option value="webhook">The provider pushes them to our address</option>@endif
                                    @if ($provider->canPull() && $provider->canPush())<option value="both">Both</option>@endif
                                </select>
                                @error('form.mode') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="f-branch" class="label">New leads go to branch</label>
                                <select id="f-branch" wire:model.live="form.default_location_id" class="input">
                                    <option value="">Choose a branch…</option>
                                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                                </select>
                                @error('form.default_location_id') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="f-owner" class="label">Give them to</label>
                                <select id="f-owner" wire:model="form.assigned_to" class="input">
                                    <option value="">Nobody — leave them in the pool</option>
                                    @foreach ($owners as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                                </select>
                                <p class="help">The pool is what managers hand out from the Leads list.</p>
                                @error('form.assigned_to') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            @if ($provider->canPull())
                                <div>
                                    <label for="f-interval" class="label">Pull every (minutes)</label>
                                    <input id="f-interval" type="number" min="5" max="240" wire:model="form.pull_interval_minutes" class="input max-w-[8rem]">
                                    @error('form.pull_interval_minutes') <p class="error">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="f-lookback" class="label">The first pull looks back (hours)</label>
                                    <input id="f-lookback" type="number" min="1" max="168" wire:model="form.initial_lookback_hours" class="input max-w-[8rem]">
                                    @error('form.initial_lookback_hours') <p class="error">{{ $message }}</p> @enderror
                                </div>
                            @endif
                        </div>

                        <p class="text-xs text-slate-500">Leads are entered as <strong>you</strong>, so you must be someone who may create and hand out leads.</p>

                        <div class="flex justify-end gap-3">
                            <button type="button" wire:click="cancel" class="btn-secondary">Cancel</button>
                            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    @endforeach
</div>

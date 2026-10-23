@php
    $type = $ledger->ledger_type;
    $bank = $ledger->bankDetail;
    $methods = $ledger->payment_preferences ?? [];
    $field = fn (string $label, $value, bool $mono = false) => $value === null || $value === ''
        ? null
        : ['label' => $label, 'value' => $value, 'mono' => $mono];
    $details = array_values(array_filter([
        $field('Account code', $ledger->code, true),
        $field('Legal name', $ledger->legal_name),
        $field('Account group', $ledger->accountGroup?->name),
        $field('Customer kind', $ledger->customer_kind ? strtoupper($ledger->customer_kind) : null),
        $field('GSTIN', $ledger->gstin, true),
        $field('PAN', $ledger->pan, true),
        $field('E-mail', $ledger->email),
        $field('Phone', $ledger->phone),
        $field('Credit days', $ledger->credit_days),
        $field('Credit limit', $ledger->credit_limit !== null ? '₹ '.number_format((float) $ledger->credit_limit, 2) : null),
        $field('Lead time', $ledger->lead_time_days !== null ? $ledger->lead_time_days.' days' : null),
        $field('Payment preferences', $methods !== [] ? implode(', ', $methods) : null),
        $field('Remarks', $ledger->remarks),
    ]));
@endphp

<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-slate-500"><a href="{{ route('masters.ledgers.index') }}" wire:navigate class="hover:text-brand-700">Ledgers</a> › {{ $type->label() }}</p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $ledger->name }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="badge bg-slate-100 text-slate-700">{{ $type->label() }}</span>
                <span class="badge {{ $ledger->approval_status->badge() }}">{{ $ledger->approval_status->label() }}</span>
                <span class="badge {{ $ledger->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($ledger->status) }}</span>
                @if ($ledger->isUsable())<span class="text-xs text-emerald-700">Available in transactions</span>@else<span class="text-xs text-slate-500">Not available in transactions</span>@endif
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($type->value === 'customer')<a href="{{ route('crm.customers.show', $ledger) }}" wire:navigate class="btn-secondary">Customer 360</a>@endif
            @if ($canEdit)<a href="{{ route('masters.ledgers.edit', $ledger) }}" wire:navigate class="btn-primary">Edit</a>@endif
            @if ($canReopen)<button type="button" wire:click="openReopen" class="btn-secondary">Re-open for editing</button>@endif
            @if ($canChangeStatus)
                <button type="button" wire:click="toggleStatus" class="btn-secondary">{{ $ledger->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
            @endif
            @if ($canDelete)<button type="button" wire:click="delete" wire:confirm="Delete this ledger?" class="btn-ghost text-rose-600">Delete</button>@endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif
    @error('delete')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if ($missing !== [])
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            Before this can be submitted for approval it still needs: {{ implode('; ', $missing) }}.
        </div>
    @endif

    <section class="card" aria-labelledby="s-details">
        <div class="card-header"><h2 id="s-details" class="text-sm font-semibold text-slate-900">Details</h2></div>
        <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-2">
            @foreach ($details as $d)
                <div>
                    <dt class="text-xs text-slate-500">{{ $d['label'] }}</dt>
                    <dd @class(['text-sm text-slate-900', 'font-mono' => $d['mono']])>{{ $d['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card" aria-labelledby="s-addresses">
            <div class="card-header"><h2 id="s-addresses" class="text-sm font-semibold text-slate-900">Addresses</h2></div>
            <div class="card-body space-y-4">
                @forelse ($ledger->addresses as $address)
                    <address class="text-sm not-italic text-slate-700" wire:key="a-{{ $address->id }}">
                        <div class="mb-1 flex flex-wrap items-center gap-2">
                            <strong class="text-slate-900">{{ $address->label ?: 'Address '.$loop->iteration }}</strong>
                            @if ($address->is_billing)<span class="badge bg-slate-100 text-slate-700">Billing{{ $address->is_default_billing ? ' · default' : '' }}</span>@endif
                            @if ($address->is_shipping)<span class="badge bg-slate-100 text-slate-700">Shipping{{ $address->is_default_shipping ? ' · default' : '' }}</span>@endif
                        </div>
                        {{ $address->line1 }}@if ($address->line2), {{ $address->line2 }}@endif<br>
                        {{ collect([$address->city, $address->district?->name, $address->state?->name])->filter()->unique()->implode(', ') }} — {{ $address->pin_code }}
                        @if ($address->gstin)<br><span class="font-mono text-xs">GSTIN {{ $address->gstin }}</span>@endif
                        @if ($address->phone)<br>{{ $address->phone }}@endif
                    </address>
                @empty
                    <p class="text-sm text-slate-500">No addresses.</p>
                @endforelse
            </div>
        </section>

        <section class="card" aria-labelledby="s-contacts">
            <div class="card-header"><h2 id="s-contacts" class="text-sm font-semibold text-slate-900">Contacts</h2></div>
            <div class="card-body space-y-4">
                @forelse ($ledger->contacts as $contact)
                    <div class="text-sm text-slate-700" wire:key="c-{{ $contact->id }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <strong class="text-slate-900">{{ $contact->name }}</strong>
                            @if ($contact->designation)<span class="text-slate-500">{{ $contact->designation }}</span>@endif
                            @if ($contact->is_primary)<span class="badge bg-sky-100 text-sky-800">Primary</span>@endif
                        </div>
                        {{ collect([$contact->mobile, $contact->phone, $contact->email])->filter()->implode(' · ') }}
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No contacts.</p>
                @endforelse
            </div>
        </section>
    </div>

    @if ($type->allowsBankDetails() && $bank)
        <section class="card" aria-labelledby="s-bank">
            <div class="card-header">
                <h2 id="s-bank" class="text-sm font-semibold text-slate-900">Bank account</h2>
                @unless ($canSeeSensitive)<span class="text-xs text-slate-500">Account number is masked for your role</span>@endunless
            </div>
            <dl class="card-body grid gap-x-8 gap-y-3 sm:grid-cols-3">
                <div><dt class="text-xs text-slate-500">Bank</dt><dd class="text-sm text-slate-900">{{ $bank->bank_name }}@if ($bank->branch), {{ $bank->branch }}@endif</dd></div>
                <div><dt class="text-xs text-slate-500">Account number</dt><dd class="font-mono text-sm text-slate-900">{{ $canSeeSensitive ? $bank->account_number : $bank->masked() }}</dd></div>
                <div><dt class="text-xs text-slate-500">IFSC</dt><dd class="font-mono text-sm text-slate-900">{{ $bank->ifsc }}</dd></div>
                @if ($bank->account_holder)<div><dt class="text-xs text-slate-500">Account holder</dt><dd class="text-sm text-slate-900">{{ $bank->account_holder }}</dd></div>@endif
                @if ($bank->account_type)<div><dt class="text-xs text-slate-500">Account type</dt><dd class="text-sm text-slate-900">{{ ucfirst(str_replace('_', ' ', $bank->account_type)) }}</dd></div>@endif
            </dl>
        </section>
    @endif

    <livewire:shared.approval-panel type="ledger" :id="$ledger->id" :key="'ap-'.$ledger->id" />

    <livewire:shared.comments type="ledger" :id="$ledger->id" :key="'cm-'.$ledger->id" />

    @if ($history->isNotEmpty())
        <section class="card" aria-labelledby="s-history">
            <div class="card-header"><h2 id="s-history" class="text-sm font-semibold text-slate-900">History</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($history as $entry)
                    @php
                        $changed = collect($entry->properties['attributes'] ?? [])->keys()->reject(fn ($k) => in_array($k, ['updated_by', 'created_by'], true))->map(fn ($k) => str_replace('_', ' ', $k))->implode(', ');
                    @endphp
                    <li class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-2.5" wire:key="h-{{ $entry->id }}">
                        <span>
                            <span class="font-medium text-slate-900">{{ str_replace('_', ' ', ucfirst((string) ($entry->event ?? $entry->description))) }}</span>
                            @if ($changed !== '')<span class="text-slate-500"> — {{ $changed }}</span>@endif
                            @if (! empty($entry->properties['comment']))<span class="text-slate-600"> — “{{ $entry->properties['comment'] }}”</span>@endif
                        </span>
                        <span class="text-xs text-slate-500">{{ $entry->causer?->name ?? 'System' }} · {{ $entry->created_at?->format(config('formezy.display.datetime')) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($reopenOpen)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="reopen-title">
            <form wire:submit="reopen" class="card w-full max-w-lg" novalidate>
                <div class="card-header">
                    <h2 id="reopen-title" class="text-sm font-semibold text-slate-900">Re-open this ledger for editing</h2>
                    <button type="button" wire:click="cancelReopen" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="card-body space-y-3">
                    <p class="text-sm text-slate-600">An approved ledger is locked. Re-opening takes it back to a draft: it <strong>cannot be used in new transactions</strong> until it is submitted and approved again. The approval history is kept and this step is recorded.</p>
                    <div>
                        <label for="reopen-reason" class="label">Why are you re-opening it?</label>
                        <textarea id="reopen-reason" rows="3" wire:model="reason" class="input" maxlength="1000"></textarea>
                        @error('reason') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3">
                    <button type="button" wire:click="cancelReopen" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">Re-open</button>
                </div>
            </form>
        </div>
    @endif
</div>

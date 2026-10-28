<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold text-slate-900">{{ $territory->name }}</h1>
                <span class="badge {{ $territory->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($territory->status) }}</span>
            </div>
            <p class="mt-1 text-sm text-slate-500"><span class="font-mono text-xs">{{ $territory->code }}</span> · Owner: {{ $territory->owner?->name ?? 'nobody yet' }} · {{ $territory->branch?->name ?? 'Company-wide' }}</p>
            @if ($territory->remarks)<p class="mt-2 text-sm text-slate-600">{{ $territory->remarks }}</p>@endif
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('field.territories.index') }}" wire:navigate class="btn-secondary">All territories</a>
            @if ($canEdit)<a href="{{ route('field.territories.edit', $territory) }}" wire:navigate class="btn-primary">Edit</a>@endif
        </div>
    </div>

    <x-status />
    @error('territory') <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div> @enderror

    <div class="card">
        <div class="card-header"><h2 class="text-lg font-semibold text-slate-900">What it covers</h2></div>
        <div class="card-body space-y-5">
            <p class="text-sm text-slate-500">A place belongs to one territory. When a lead is looked up, the <strong>most specific</strong> cover wins: a customer, then a PIN code, a block, a district, a state — so a district can be given to one territory with a few PIN codes carved out for another.</p>

            @foreach ($kinds as $k)
                @php($rows = $covered->get($k->value, collect()))
                @if ($rows->isNotEmpty())
                    <div wire:key="cov-{{ $k->value }}">
                        <h3 class="text-sm font-semibold text-slate-700">{{ $k->label() }}{{ $rows->count() === 1 ? '' : 's' }} <span class="font-normal text-slate-500">({{ $rows->count() }})</span></h3>
                        <ul class="mt-2 flex flex-wrap gap-2">
                            @foreach ($rows as $area)
                                <li wire:key="area-{{ $area->id }}" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white py-1 pl-3 pr-1 text-sm">
                                    <span @class(['font-mono' => $k === \App\Modules\CRM\Enums\CoverageKind::PinCode])>{{ $area->label() }}</span>
                                    @if ($canCover)
                                        <button type="button" wire:click="uncover({{ $area->id }})" wire:loading.attr="disabled" class="btn-ghost btn-sm text-rose-600" aria-label="Take this off the territory">Remove</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach

            @if ($territory->areas->isEmpty())
                <p class="text-sm text-slate-500">It covers nothing yet.</p>
            @endif

            @if ($canCover)
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <h3 class="text-sm font-semibold text-slate-700">Add coverage</h3>
                    <div class="mt-3 grid gap-3 sm:grid-cols-[12rem_1fr]">
                        <div>
                            <label for="cv-kind" class="label">Kind</label>
                            <select id="cv-kind" wire:model.live="kind" class="input">
                                @foreach ($kinds as $k)<option value="{{ $k->value }}">{{ $k->label() }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label for="cv-term" class="label">{{ $kind === 'pin_code' ? 'Six-digit PIN code' : 'Search by name' }}</label>
                            <input id="cv-term" type="search" wire:model.live.debounce.300ms="term" class="input" autocomplete="off" inputmode="{{ $kind === 'pin_code' ? 'numeric' : 'text' }}" maxlength="{{ $kind === 'pin_code' ? 6 : 100 }}" placeholder="{{ $kind === 'state' ? 'All states are listed; type to narrow' : ($kind === 'pin_code' ? '380001' : 'Type at least two letters…') }}">
                        </div>
                    </div>

                    @error('coverage') <p class="error mt-2" role="alert">{{ $message }}</p> @enderror

                    @if ($kind === 'pin_code')
                        @if ($pin)
                            <div class="mt-3"><button type="button" wire:click="cover('{{ $pin }}')" wire:loading.attr="disabled" class="btn-primary btn-sm">Add PIN code {{ $pin }}</button></div>
                        @endif
                    @elseif (count($offers) > 0)
                        <ul class="mt-3 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white" aria-label="Matches">
                            @foreach ($offers as [$value, $label, $note])
                                <li wire:key="offer-{{ $kind }}-{{ $value }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                    <span>{{ $label }} @if ($note !== '')<span class="text-xs text-slate-500">{{ $note }}</span>@endif</span>
                                    <button type="button" wire:click="cover('{{ $value }}')" wire:loading.attr="disabled" class="btn-secondary btn-sm">Add</button>
                                </li>
                            @endforeach
                        </ul>
                    @elseif ($kind !== 'state' && mb_strlen(trim($term)) >= 2)
                        <p class="help mt-2">Nothing matches “{{ $term }}”.</p>
                    @endif
                </div>
            @elseif ($territory->status !== 'active' && $canEdit)
                <p class="text-sm text-slate-500">Switch the territory on to give it coverage.</p>
            @endif
        </div>
    </div>

    @if ($canDelete)
        <div class="text-right">
            <button type="button" wire:click="deleteTerritory" wire:confirm="Delete this territory? It covers nothing." class="btn-ghost btn-sm text-rose-600">Delete territory</button>
        </div>
    @endif
</div>

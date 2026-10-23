<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $editing ? 'Edit plan' : 'Plan a day' }}</h1>
            <p class="mt-1 text-sm text-slate-500">Who is going where, in what order. Give each stop a place on the map: that is the geofence attendance is checked against.</p>
        </div>
        <a href="{{ $editing ? route('field.plans.show', $planId) : route('field.plans.index') }}" wire:navigate class="btn-secondary">Cancel</a>
    </div>

    @if ($errors->any())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">
            @error('form'){{ $message }}@else Some details need attention. Please check the fields marked below.@enderror
        </div>
    @endif

    <form wire:submit="save" class="space-y-5" novalidate>
        <section class="card" aria-labelledby="sec-day">
            <div class="card-header"><h2 id="sec-day" class="text-sm font-semibold text-slate-900">The day</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="p-user" class="label">Salesperson</label>
                    <select id="p-user" wire:model="plan.user_id" class="input" required>
                        <option value="">Select…</option>
                        @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('plan.user_id') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="p-date" class="label">Day</label>
                    <input id="p-date" type="date" wire:model="plan.plan_date" class="input" required>
                    @error('plan.plan_date') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="p-branch" class="label">Branch</label>
                    <select id="p-branch" wire:model="plan.location_id" class="input" required>
                        <option value="">Select…</option>
                        @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('plan.location_id') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="p-title" class="label">Name of the round <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="p-title" type="text" wire:model="plan.title" class="input" maxlength="150" placeholder="e.g. Naroda and Vatva industrial estates">
                    @error('plan.title') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="p-radius" class="label">Geofence radius for the plan (m) <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="p-radius" type="number" min="50" max="5000" wire:model="plan.geofence_radius_m" class="input tabular-nums" placeholder="{{ $defaultRadius }} (company default)">
                    <p class="help">A stop can set its own.</p>
                    @error('plan.geofence_radius_m') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="sec-stops">
            <div class="card-header">
                <h2 id="sec-stops" class="text-sm font-semibold text-slate-900">Stops, in order</h2>
                @if (count($stops) < $max)<button type="button" wire:click="addStop" class="btn-secondary btn-sm">Add a stop</button>@endif
            </div>
            <div class="card-body space-y-4">
                @error('stops') <p class="error">{{ $message }}</p> @enderror

                @forelse ($stops as $i => $stop)
                    <div class="rounded-lg border border-slate-200 p-4" wire:key="stop-{{ $stop['key'] ?? $i }}">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold text-slate-900">Stop {{ $i + 1 }}</span>
                            <div class="flex gap-1">
                                <button type="button" wire:click="moveStop({{ $i }}, -1)" class="btn-ghost btn-sm" @disabled($i === 0) aria-label="Move stop {{ $i + 1 }} up">↑</button>
                                <button type="button" wire:click="moveStop({{ $i }}, 1)" class="btn-ghost btn-sm" @disabled($i === count($stops) - 1) aria-label="Move stop {{ $i + 1 }} down">↓</button>
                                <button type="button" wire:click="removeStop({{ $i }})" class="btn-ghost btn-sm text-rose-600">Remove</button>
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-4">
                            <div>
                                <label for="s{{ $i }}-type" class="label">Kind</label>
                                <select id="s{{ $i }}-type" wire:model.live="stops.{{ $i }}.stop_type" class="input">
                                    @foreach ($types as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach
                                </select>
                                @error("stops.$i.stop_type") <p class="error">{{ $message }}</p> @enderror
                            </div>

                            @if ($stop['stop_type'] === 'block' || $stop['stop_type'] === 'customer')
                                <div class="sm:col-span-3">
                                    <label for="s{{ $i }}-search" class="label">{{ $stop['stop_type'] === 'block' ? 'Find a block' : 'Find a customer' }}</label>
                                    <input id="s{{ $i }}-search" type="search" wire:model.live.debounce.300ms="stops.{{ $i }}.search" class="input" autocomplete="off" placeholder="Type part of the name…">
                                    @if (! empty($results[$i]))
                                        <ul class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                                            @foreach ($results[$i] as $hit)
                                                <li><button type="button" wire:click="pick({{ $i }}, {{ $hit['id'] }})" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">{{ $hit['label'] }}</button></li>
                                            @endforeach
                                        </ul>
                                    @elseif (trim((string) ($stop['search'] ?? '')) !== '')
                                        <p class="help">Nothing found{{ $stop['stop_type'] === 'customer' ? ' (only approved, active customers are offered)' : '' }}.</p>
                                    @endif
                                    @error("stops.$i.block_id") <p class="error">{{ $message }}</p> @enderror
                                    @error("stops.$i.ledger_id") <p class="error">{{ $message }}</p> @enderror
                                </div>
                            @elseif ($stop['stop_type'] === 'pin_code')
                                <div>
                                    <label for="s{{ $i }}-pin" class="label">PIN code</label>
                                    <input id="s{{ $i }}-pin" type="text" inputmode="numeric" maxlength="6" wire:model.live.debounce.400ms="stops.{{ $i }}.pin_code" class="input font-mono" autocomplete="off">
                                    @error("stops.$i.pin_code") <p class="error">{{ $message }}</p> @enderror
                                </div>
                            @endif

                            <div @class(['sm:col-span-2' => $stop['stop_type'] === 'area' || $stop['stop_type'] === 'pin_code', 'sm:col-span-4' => in_array($stop['stop_type'], ['block', 'customer'], true)])>
                                <label for="s{{ $i }}-label" class="label">Name of the stop</label>
                                <input id="s{{ $i }}-label" type="text" wire:model="stops.{{ $i }}.label" class="input" maxlength="150" placeholder="{{ $stop['stop_type'] === 'area' ? 'e.g. Naroda GIDC' : 'Filled in when you choose one' }}">
                                @error("stops.$i.label") <p class="error">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-5">
                            <div>
                                <label for="s{{ $i }}-lat" class="label">Latitude</label>
                                <input id="s{{ $i }}-lat" type="text" inputmode="decimal" wire:model="stops.{{ $i }}.latitude" class="input tabular-nums" autocomplete="off" placeholder="23.0225">
                                @error("stops.$i.latitude") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="s{{ $i }}-lng" class="label">Longitude</label>
                                <input id="s{{ $i }}-lng" type="text" inputmode="decimal" wire:model="stops.{{ $i }}.longitude" class="input tabular-nums" autocomplete="off" placeholder="72.5714">
                                @error("stops.$i.longitude") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="s{{ $i }}-radius" class="label">Radius (m)</label>
                                <input id="s{{ $i }}-radius" type="number" min="50" max="5000" wire:model="stops.{{ $i }}.radius_m" class="input tabular-nums" placeholder="plan / company">
                                @error("stops.$i.radius_m") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="s{{ $i }}-from" class="label">Visit from</label>
                                <input id="s{{ $i }}-from" type="time" wire:model="stops.{{ $i }}.visit_from" class="input">
                                @error("stops.$i.visit_from") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="s{{ $i }}-to" class="label">Visit to</label>
                                <input id="s{{ $i }}-to" type="time" wire:model="stops.{{ $i }}.visit_to" class="input">
                                @error("stops.$i.visit_to") <p class="error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No stops yet. Add the first one.</p>
                @endforelse
                <p class="help">A stop without a latitude and longitude is only a reminder. Right-click a place in Google Maps to copy its coordinates.</p>
            </div>
        </section>

        <section class="card" aria-labelledby="sec-notes">
            <div class="card-header"><h2 id="sec-notes" class="text-sm font-semibold text-slate-900">Notes</h2></div>
            <div class="card-body">
                <textarea id="p-remarks" rows="2" wire:model="plan.remarks" class="input" maxlength="1000" aria-label="Notes for the day"></textarea>
                @error('plan.remarks') <p class="error">{{ $message }}</p> @enderror
            </div>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ $editing ? route('field.plans.show', $planId) : route('field.plans.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $editing ? 'Save plan' : 'Save as draft' }}</button>
        </div>
    </form>
</div>
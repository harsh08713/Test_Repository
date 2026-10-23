<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $editing ? 'Edit lead' : 'Add a lead' }}@if ($leadNumber) <span class="font-mono text-base font-normal text-slate-500">{{ $leadNumber }}</span>@endif</h1>
            <p class="mt-1 text-sm text-slate-500">Only the name, the mobile number and the source are needed to start. The mobile number is how a lead that is already here is spotted.</p>
        </div>
        <a href="{{ $editing ? route('crm.leads.show', $leadId) : route('crm.leads.index') }}" wire:navigate class="btn-secondary">Cancel</a>
    </div>

    @if ($errors->any())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">
            @error('form'){{ $message }}@else Some details need attention. Please check the fields marked below.@enderror
        </div>
    @endif

    <form wire:submit="save" class="space-y-5" novalidate>
        <section class="card" aria-labelledby="sec-who">
            <div class="card-header"><h2 id="sec-who" class="text-sm font-semibold text-slate-900">Who</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                    <label for="l-name" class="label">Company or person</label>
                    <input id="l-name" type="text" wire:model="lead.name" class="input" maxlength="200" required autocomplete="off">
                    @error('lead.name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="l-contact" class="label">Contact person <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="l-contact" type="text" wire:model="lead.contact_person" class="input" maxlength="150" autocomplete="off">
                    @error('lead.contact_person') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="l-mobile" class="label">Mobile number</label>
                    <input id="l-mobile" type="tel" inputmode="numeric" wire:model.live.debounce.500ms="lead.mobile" class="input tabular-nums" maxlength="16" required autocomplete="off" placeholder="10 digits">
                    @error('lead.mobile') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="l-phone" class="label">Other phone <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="l-phone" type="tel" wire:model="lead.phone" class="input tabular-nums" maxlength="20" autocomplete="off">
                    @error('lead.phone') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="l-email" class="label">E-mail <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="l-email" type="email" wire:model="lead.email" class="input" maxlength="150" autocomplete="off">
                    @error('lead.email') <p class="error">{{ $message }}</p> @enderror
                </div>

                @if ($duplicates->isNotEmpty())
                    <div class="sm:col-span-6 rounded-lg border {{ $openDuplicates > 0 ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-slate-50' }} px-4 py-3" role="status" wire:key="dups">
                        <p class="text-sm font-medium {{ $openDuplicates > 0 ? 'text-amber-900' : 'text-slate-700' }}">
                            @if ($openDuplicates > 0)
                                This mobile number is already on {{ $openDuplicates === 1 ? 'an open lead' : $openDuplicates.' open leads' }}.
                            @else
                                This mobile number has been a lead before (now closed), so a new lead is fine.
                            @endif
                        </p>
                        <ul class="mt-2 space-y-1 text-sm text-slate-700">
                            @foreach ($duplicates as $other)
                                <li wire:key="dup-{{ $other->id }}">
                                    @if (isset($openable[$other->id]))
                                        <a href="{{ route('crm.leads.show', $other->id) }}" target="_blank" rel="noopener" class="font-mono text-xs text-brand-700 hover:underline">{{ $other->lead_number }}</a>
                                    @else
                                        <span class="font-mono text-xs">{{ $other->lead_number }}</span>
                                    @endif
                                    · {{ $other->name }} · <span class="badge {{ $other->stage->badge() }}">{{ $other->stage->label() }}</span>
                                    · with {{ $other->assignee?->name ?? 'nobody yet' }} · {{ $other->location?->name }} · {{ $other->lead_date->format('j M Y') }}
                                </li>
                            @endforeach
                        </ul>
                        @if ($openDuplicates > 0)
                            <label class="mt-3 flex items-start gap-3 text-sm">
                                <input type="checkbox" wire:model="lead.acknowledge_duplicate" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                <span><span class="font-medium text-slate-900">This is a separate inquiry — save as a separate lead</span><span class="help block">Otherwise, follow up on the lead that is already there instead of adding another.</span></span>
                            </label>
                        @endif
                    </div>
                @endif
            </div>
        </section>

        <section class="card" aria-labelledby="sec-lead">
            <div class="card-header"><h2 id="sec-lead" class="text-sm font-semibold text-slate-900">The lead</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-2">
                    <label for="l-source" class="label">Source</label>
                    <select id="l-source" wire:model="lead.lead_source_id" class="input" required>
                        <option value="">Select…</option>
                        @foreach ($sources as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('lead.lead_source_id') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="l-date" class="label">Lead date</label>
                    <input id="l-date" type="date" wire:model="lead.lead_date" max="{{ $today }}" class="input" required>
                    @error('lead.lead_date') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="l-branch" class="label">Branch</label>
                    @if ($editing)
                        <input id="l-branch" type="text" class="input" value="{{ $branches[$lead['location_id']] ?? '' }}" disabled>
                        <p class="help">The branch numbers the lead, so it does not change.</p>
                    @else
                        <select id="l-branch" wire:model.live="lead.location_id" class="input" required>
                            <option value="">Select…</option>
                            @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                        @error('lead.location_id') <p class="error">{{ $message }}</p> @enderror
                    @endif
                </div>
                <div class="sm:col-span-4">
                    <label for="l-req" class="label">What they are looking for <span class="font-normal text-slate-500">(optional)</span></label>
                    <textarea id="l-req" rows="2" wire:model="lead.requirement" class="input" maxlength="2000"></textarea>
                    @error('lead.requirement') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="l-value" class="label">Expected value (₹) <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="l-value" type="text" inputmode="decimal" wire:model="lead.expected_value" class="input tabular-nums" autocomplete="off" placeholder="e.g. 250000">
                    @error('lead.expected_value') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="l-owner" class="label">Owner</label>
                    @if ($canAssign)
                        <select id="l-owner" wire:model="lead.assigned_to" class="input">
                            <option value="">Unassigned — decide later</option>
                            @foreach ($owners as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                        <p class="help">Yourself or someone on a team you manage, who works in the lead’s branch.</p>
                        @error('lead.assigned_to') <p class="error">{{ $message }}</p> @enderror
                    @elseif ($editing)
                        <input id="l-owner" type="text" class="input" value="{{ $ownerName ?? 'Unassigned' }}" disabled>
                        <p class="help">A lead changes hands through “Transfer” on its page, which keeps the history.</p>
                    @else
                        <input id="l-owner" type="text" class="input" value="{{ auth()->user()->name }}" disabled>
                        <p class="help">A lead you enter is yours.</p>
                    @endif
                </div>
            </div>
        </section>

        @if (! $editing && ($campaigns !== [] || $fromLink))
            <section class="card" aria-labelledby="sec-camp">
                <div class="card-header"><h2 id="sec-camp" class="text-sm font-semibold text-slate-900">Campaign</h2><span class="text-xs text-slate-500">{{ $fromLink ? 'Filled in from the link you followed' : 'If this lead answered a campaign' }}</span></div>
                <div class="card-body grid gap-4 sm:grid-cols-6">
                    <div class="sm:col-span-6"><label for="l-camp" class="label">Campaign</label>
                        <select id="l-camp" wire:model="attribution.campaign_id" class="input"><option value="">None</option>@foreach ($campaigns as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                    <div class="sm:col-span-2"><label for="l-us" class="label">utm_source</label><input id="l-us" type="text" wire:model="attribution.utm_source" class="input" maxlength="100"></div>
                    <div class="sm:col-span-2"><label for="l-um" class="label">utm_medium</label><input id="l-um" type="text" wire:model="attribution.utm_medium" class="input" maxlength="100"></div>
                    <div class="sm:col-span-2"><label for="l-uc" class="label">utm_campaign</label><input id="l-uc" type="text" wire:model="attribution.utm_campaign" class="input" maxlength="100"></div>
                </div>
            </section>
        @endif

        <section class="card" aria-labelledby="sec-where">
            <div class="card-header"><h2 id="sec-where" class="text-sm font-semibold text-slate-900">Where</h2><span class="text-xs text-slate-500">All optional</span></div>
            <div class="card-body grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                    <label for="l-a1" class="label">Address</label>
                    <input id="l-a1" type="text" wire:model="lead.address_line1" class="input" maxlength="200" autocomplete="off">
                    @error('lead.address_line1') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="l-a2" class="label">Area / landmark</label>
                    <input id="l-a2" type="text" wire:model="lead.address_line2" class="input" maxlength="200" autocomplete="off">
                    @error('lead.address_line2') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="l-pin" class="label">PIN code</label>
                    <input id="l-pin" type="text" inputmode="numeric" maxlength="6" wire:model.live.debounce.400ms="lead.pin_code" class="input font-mono" autocomplete="off">
                    @if ($pinHint !== '')<p class="help" role="status">{{ $pinHint }}</p>@endif
                    @error('lead.pin_code') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="l-city" class="label">City / town</label>
                    <input id="l-city" type="text" wire:model="lead.city" class="input" maxlength="100" autocomplete="off">
                    @error('lead.city') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="l-state" class="label">State</label>
                    <select id="l-state" wire:model.live="lead.state_id" class="input">
                        <option value="">Select…</option>
                        @foreach ($states as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('lead.state_id') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="l-district" class="label">District</label>
                    <select id="l-district" wire:model.live="lead.district_id" class="input" @disabled($lead['state_id'] === '')>
                        <option value="">{{ $lead['state_id'] === '' ? 'Choose the state first' : 'Select…' }}</option>
                        @foreach ($districts as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('lead.district_id') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="l-block" class="label">Block</label>
                    <select id="l-block" wire:model="lead.block_id" class="input" @disabled($lead['district_id'] === '')>
                        <option value="">{{ $lead['district_id'] === '' ? 'Choose the district first' : 'Select…' }}</option>
                        @foreach ($blocks as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('lead.block_id') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="sec-items">
            <div class="card-header">
                <h2 id="sec-items" class="text-sm font-semibold text-slate-900">Items of interest <span class="font-normal text-slate-500">(optional)</span></h2>
                @if (count($interests) < $maxInterests)<button type="button" wire:click="addInterest" class="btn-secondary btn-sm">Add an item</button>@endif
            </div>
            <div class="card-body space-y-3">
                @error('interests') <p class="error">{{ $message }}</p> @enderror

                @forelse ($interests as $i => $row)
                    <div class="grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-12" wire:key="int-{{ $row['key'] ?? $i }}">
                        <div class="sm:col-span-6">
                            @if (($row['item_id'] ?? '') !== '')
                                <span class="label">Item</span>
                                <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                    <span class="truncate text-slate-900">{{ $row['item_label'] !== '' ? $row['item_label'] : 'Item #'.$row['item_id'] }}</span>
                                    <button type="button" wire:click="clearItem({{ $i }})" class="shrink-0 text-xs text-brand-700 hover:underline">Change</button>
                                </div>
                                @error("interests.$i.item_id") <p class="error">{{ $message }}</p> @enderror
                            @else
                                <label for="i{{ $i }}-search" class="label">Find an item</label>
                                <input id="i{{ $i }}-search" type="search" wire:model.live.debounce.300ms="interests.{{ $i }}.search" class="input" autocomplete="off" placeholder="Code, name or HSN…">
                                @if (! empty($results[$i]))
                                    <ul class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                                        @foreach ($results[$i] as $hit)
                                            <li><button type="button" wire:click="pickItem({{ $i }}, {{ $hit['id'] }})" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">{{ $hit['label'] }}</button></li>
                                        @endforeach
                                    </ul>
                                @elseif (trim((string) ($row['search'] ?? '')) !== '')
                                    <p class="help">Nothing found (only approved, active items are offered). Describe it in words instead.</p>
                                @endif
                                @error("interests.$i.item_id") <p class="error">{{ $message }}</p> @enderror
                                <label for="i{{ $i }}-desc" class="label mt-2">…or describe it</label>
                                <input id="i{{ $i }}-desc" type="text" wire:model="interests.{{ $i }}.description" class="input" maxlength="200" autocomplete="off" placeholder="e.g. Stainless fasteners, mixed sizes">
                                @error("interests.$i.description") <p class="error">{{ $message }}</p> @enderror
                            @endif
                        </div>
                        <div class="sm:col-span-2">
                            <label for="i{{ $i }}-qty" class="label">Quantity</label>
                            <input id="i{{ $i }}-qty" type="text" inputmode="decimal" wire:model="interests.{{ $i }}.quantity" class="input tabular-nums" autocomplete="off">
                            @error("interests.$i.quantity") <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-3">
                            <label for="i{{ $i }}-unit" class="label">Unit</label>
                            <select id="i{{ $i }}-unit" wire:model="interests.{{ $i }}.unit_id" class="input">
                                <option value="">—</option>
                                @foreach ((($row['item_id'] ?? '') !== '' ? ($itemUnits[$i] ?? []) : $units) as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                            @error("interests.$i.unit_id") <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex items-end justify-end sm:col-span-1">
                            <button type="button" wire:click="removeInterest({{ $i }})" class="btn-ghost btn-sm text-rose-600" aria-label="Remove item {{ $i + 1 }}">Remove</button>
                        </div>
                        @error("interests.$i.id") <p class="error sm:col-span-12">{{ $message }}</p> @enderror
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Nothing listed. Add an item if you know what they want.</p>
                @endforelse
            </div>
        </section>

        <section class="card" aria-labelledby="sec-notes">
            <div class="card-header"><h2 id="sec-notes" class="text-sm font-semibold text-slate-900">Remarks</h2></div>
            <div class="card-body">
                <textarea id="l-remarks" rows="2" wire:model="lead.remarks" class="input" maxlength="1000" aria-label="Remarks"></textarea>
                @error('lead.remarks') <p class="error">{{ $message }}</p> @enderror
            </div>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ $editing ? route('crm.leads.show', $leadId) : route('crm.leads.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $editing ? 'Save lead' : 'Add lead' }}</button>
        </div>
    </form>
</div>

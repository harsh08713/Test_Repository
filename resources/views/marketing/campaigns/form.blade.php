@php
    $isLeads = ($c['audience_kind'] ?? 'leads') === 'leads';
@endphp
<div class="mx-auto max-w-5xl space-y-5">
    <div>
        <p class="text-sm"><a href="{{ route('marketing.campaigns.index') }}" wire:navigate class="text-brand-700 hover:underline">Campaigns</a></p>
        <h1 class="text-2xl font-semibold text-slate-900">{{ $editing ? 'Change the campaign' : 'New campaign' }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-500">A campaign is written down, approved by someone else, and then launched. Its audience, its messages and its timing are what the approver signs, so they cannot change once it has gone for approval.</p>
    </div>

    <form wire:submit="save" class="space-y-5" novalidate>
        @error('form')<p class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror

        <section class="card" aria-labelledby="sec-details">
            <div class="card-header"><h2 id="sec-details" class="text-sm font-semibold text-slate-900">The campaign</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-4"><label for="c-name" class="label">Name</label><input id="c-name" type="text" wire:model="c.name" class="input" maxlength="150">@error('name') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-2"><label for="c-type" class="label">Kind</label><select id="c-type" wire:model="c.type" class="input">@foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('type') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-6"><label for="c-obj" class="label">What it is for <span class="font-normal text-slate-400">(optional)</span></label><textarea id="c-obj" rows="2" wire:model="c.objective" class="input" maxlength="2000"></textarea>@error('objective') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-2"><label for="c-owner" class="label">Owner</label><select id="c-owner" wire:model.live="c.owner_id" class="input">@foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select><p class="help">The audience is worked out with the owner’s reach.</p>@error('owner_id') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-2"><label for="c-branch" class="label">Branch</label>
                    @if ($editing)<input id="c-branch" type="text" class="input" value="{{ $branches[(int) ($c['location_id'] ?? 0)] ?? '' }}" disabled>
                    @else<select id="c-branch" wire:model="c.location_id" class="input"><option value="">Select…</option>@foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>@endif
                    @error('location_id') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-2"><label for="c-budget" class="label">Budget (₹) <span class="font-normal text-slate-400">(optional)</span></label><input id="c-budget" type="text" inputmode="decimal" wire:model="c.budget" class="input">@error('budget') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-2"><label for="c-start" class="label">Starts</label><input id="c-start" type="date" wire:model="c.start_date" class="input">@error('start_date') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-2"><label for="c-end" class="label">Ends <span class="font-normal text-slate-400">(optional)</span></label><input id="c-end" type="date" wire:model="c.end_date" class="input">@error('end_date') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-2"><label for="c-source" class="label">Lead source for its leads <span class="font-normal text-slate-400">(optional)</span></label><select id="c-source" wire:model="c.lead_source_id" class="input"><option value="">—</option>@foreach ($sources as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>@error('lead_source_id') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-2"><label for="c-us" class="label">utm_source</label><input id="c-us" type="text" wire:model="c.utm_source" class="input" maxlength="100">@error('utm_source') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-2"><label for="c-um" class="label">utm_medium</label><input id="c-um" type="text" wire:model="c.utm_medium" class="input" maxlength="100">@error('utm_medium') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-2"><label for="c-uc" class="label">utm_campaign</label><input id="c-uc" type="text" wire:model="c.utm_campaign" class="input" maxlength="100">@error('utm_campaign') <p class="error">{{ $message }}</p> @enderror</div>
            </div>
        </section>

        <section class="card" aria-labelledby="sec-audience">
            <div class="card-header"><h2 id="sec-audience" class="text-sm font-semibold text-slate-900">Who it is for</h2></div>
            <div class="card-body space-y-4">
                <div class="max-w-xs"><label for="c-kind" class="label">Write to</label>
                    <select id="c-kind" wire:model.live="c.audience_kind" class="input"><option value="leads">Leads</option><option value="customers">Customers</option></select>@error('audience_kind') <p class="error">{{ $message }}</p> @enderror</div>

                @if ($isLeads)
                    <fieldset><legend class="label">Lead stage <span class="font-normal text-slate-400">(none ticked = every open lead)</span></legend>
                        <div class="flex flex-wrap gap-x-5 gap-y-2">@foreach ($stages as $value => $label)<label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="a.stages" value="{{ $value }}" class="rounded border-slate-300"> {{ $label }}</label>@endforeach</div></fieldset>
                    <div class="grid gap-4 sm:grid-cols-4">
                        <div><label for="a-branch" class="label">Branch</label><select id="a-branch" wire:model.live="a.branch" class="input"><option value="">Any</option>@foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                        <div><label for="a-from" class="label">Lead date from</label><input id="a-from" type="date" wire:model.live="a.from" class="input"></div>
                        <div><label for="a-to" class="label">to</label><input id="a-to" type="date" wire:model.live="a.to" class="input"></div>
                        <div><label for="a-text" class="label">Name or requirement contains</label><input id="a-text" type="text" wire:model.live.debounce.500ms="a.text" class="input" maxlength="100"></div>
                    </div>
                    <fieldset><legend class="label">Lead source <span class="font-normal text-slate-400">(none ticked = any)</span></legend>
                        <div class="flex flex-wrap gap-x-5 gap-y-2">@foreach ($sources as $id => $name)<label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="a.sources" value="{{ $id }}" class="rounded border-slate-300"> {{ $name }}</label>@endforeach</div></fieldset>
                @else
                    <fieldset><legend class="label">Kind of customer <span class="font-normal text-slate-400">(none ticked = all)</span></legend>
                        <div class="flex gap-5"><label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="a.kinds" value="b2b" class="rounded border-slate-300"> Business (B2B)</label><label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="a.kinds" value="b2c" class="rounded border-slate-300"> Individual (B2C)</label></div></fieldset>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div><label for="a-ctext" class="label">Name contains</label><input id="a-ctext" type="text" wire:model.live.debounce.500ms="a.text" class="input" maxlength="100"></div>
                        <div><label for="a-ord" class="label">Ordered in the last (days)</label><input id="a-ord" type="number" min="1" max="3650" wire:model.live.debounce.500ms="a.ordered_within_days" class="input"></div>
                        <div><label for="a-nord" class="label">Has not ordered in the last (days)</label><input id="a-nord" type="number" min="1" max="3650" wire:model.live.debounce.500ms="a.not_ordered_within_days" class="input"></div>
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" wire:click="previewAudience" class="btn-secondary">Preview the audience</button>
                    @error('owner_id') <span class="error">{{ $message }}</span> @enderror
                </div>
                @if ($preview)
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm" role="status">
                        <p class="font-medium text-slate-900">{{ number_format($preview['total']) }} {{ $isLeads ? 'leads' : 'customers' }} match (with {{ $preview['owner'] }}’s reach){{ $preview['too_many'] ? ' — too many: a campaign holds at most '.number_format(\App\Modules\Marketing\Services\AudienceBuilder::MAX).'.' : '.' }}</p>
                        @if ($preview['has_template'])
                            <ul class="mt-2 space-y-0.5 text-slate-700">
                                <li>{{ number_format($preview['reachable']) }} can be written to on {{ $preview['channel'] }} now.</li>
                                <li>{{ number_format($preview['held_back']) }} have an address but no consent for it (or have opted out).</li>
                                <li>{{ number_format($preview['no_address']) }} have no usable {{ $preview['channel'] === 'E-mail' ? 'e-mail address' : 'mobile number' }}.</li>
                            </ul>
                        @else
                            <p class="mt-2 text-slate-600">Choose the first message below to see how many can be written to.</p>
                        @endif
                        @if ($preview['sample'] !== [])<p class="mt-2 text-xs text-slate-500">For example: {{ implode(', ', $preview['sample']) }}</p>@endif
                    </div>
                @endif
            </div>
        </section>

        <section class="card" aria-labelledby="sec-steps">
            <div class="card-header"><h2 id="sec-steps" class="text-sm font-semibold text-slate-900">The messages, in order</h2></div>
            <div class="card-body space-y-3">
                @error('steps') <p class="error">{{ $message }}</p> @enderror
                @foreach ($steps as $i => $step)
                    <div class="grid items-end gap-3 sm:grid-cols-6" wire:key="step-{{ $i }}">
                        <div class="sm:col-span-1"><span class="label">Step {{ $i + 1 }}</span></div>
                        <div class="sm:col-span-3"><label for="s-t-{{ $i }}" class="label">Template</label>
                            <select id="s-t-{{ $i }}" wire:model.live="steps.{{ $i }}.message_template_id" class="input"><option value="">Choose…</option>
                                @foreach ($templates as $template)<option value="{{ $template->id }}">{{ $template->name }} · {{ $template->channel->label() }}{{ $template->isApproved() && $template->status === 'active' ? '' : ' (not approved yet)' }}</option>@endforeach</select>
                            @error("steps.$i.message_template_id") <p class="error">{{ $message }}</p> @enderror</div>
                        <div class="sm:col-span-1"><label for="s-d-{{ $i }}" class="label">{{ $i === 0 ? 'Hours after launch' : 'Hours after step '.$i }}</label><input id="s-d-{{ $i }}" type="number" min="0" max="8760" wire:model="steps.{{ $i }}.delay_hours" class="input">@error("steps.$i.delay_hours") <p class="error">{{ $message }}</p> @enderror</div>
                        <div class="sm:col-span-1 text-right"><button type="button" wire:click="removeStep({{ $i }})" class="text-xs text-rose-700 hover:underline">Remove</button></div>
                    </div>
                @endforeach
                <button type="button" wire:click="addStep" class="btn-secondary">Add a step</button>
                <p class="text-xs text-slate-500">Every template used must be approved and on before the campaign can be sent for approval. Marketing messages go only to people who have agreed, are limited to a few a week for each address, and carry an unsubscribe link.</p>
            </div>
        </section>

        <section class="card" aria-labelledby="sec-exit">
            <div class="card-header"><h2 id="sec-exit" class="text-sm font-semibold text-slate-900">When a person leaves the sequence</h2></div>
            <div class="card-body space-y-2">
                <p class="text-sm text-slate-600">A person always leaves when they respond, opt out, or the campaign ends. Also when their lead reaches:</p>
                <div class="flex flex-wrap gap-x-5 gap-y-2">@foreach ($exitChoices as $value => $label)<label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="exit" value="{{ $value }}" class="rounded border-slate-300"> {{ $label }}</label>@endforeach</div>
            </div>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ $editing ? route('marketing.campaigns.show', $campaignId) : route('marketing.campaigns.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary">Save the campaign</button>
        </div>
    </form>
</div>

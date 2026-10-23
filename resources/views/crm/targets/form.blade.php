<div class="mx-auto max-w-3xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $editing ? 'Edit target' : 'Set a target' }}</h1>
            <p class="mt-1 text-sm text-slate-500">For one salesperson or one team, for a month or a quarter, by value and/or quantity. Two targets for the same person, item group and branch cannot cover the same days.</p>
        </div>
        <a href="{{ route('field.targets.index') }}" wire:navigate class="btn-secondary">Cancel</a>
    </div>

    @if ($errors->any())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">
            @error('form'){{ $message }}@else Some details need attention. Please check the fields marked below.@enderror
        </div>
    @endif

    <form wire:submit="save" class="space-y-5" novalidate>
        <section class="card" aria-labelledby="sec-who">
            <div class="card-header"><h2 id="sec-who" class="text-sm font-semibold text-slate-900">Who and when</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="g-subject" class="label">Target for</label>
                    <select id="g-subject" wire:model.live="target.subject" class="input">
                        <option value="user">One salesperson</option>
                        <option value="team">A team</option>
                    </select>
                    @error('target.subject') <p class="error">{{ $message }}</p> @enderror
                </div>

                @if (($target['subject'] ?? 'user') === 'team')
                    <div>
                        <label for="g-team" class="label">Team</label>
                        <select id="g-team" wire:model="target.sales_team_id" class="input" required>
                            <option value="">Select…</option>
                            @foreach ($teams as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                        <p class="help">The total of the people on the team, counted for the days each was on it.</p>
                        @error('target.sales_team_id') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label for="g-user" class="label">Salesperson</label>
                        <select id="g-user" wire:model="target.user_id" class="input" required>
                            <option value="">Select…</option>
                            @foreach ($people as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                        @error('target.user_id') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div>
                    <label for="g-type" class="label">Period</label>
                    <select id="g-type" wire:model.live="target.period_type" class="input">
                        <option value="monthly">A month</option>
                        <option value="quarterly">A quarter of the financial year</option>
                    </select>
                    @error('target.period_type') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="g-year" class="label">{{ $quarterly ? 'Financial year starting' : 'Year' }}</label>
                        <input id="g-year" type="number" wire:model.live="year" min="2000" max="2100" class="input tabular-nums">
                    </div>
                    <div>
                        <label for="g-slot" class="label">{{ $quarterly ? 'Quarter' : 'Month' }}</label>
                        <select id="g-slot" wire:model.live="slot" class="input">
                            @foreach (($quarterly ? $quarters : $months) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <p class="sm:col-span-2 -mt-2 text-sm text-slate-600">@if ($preview !== '') Setting the target for <strong class="text-slate-900">{{ $preview }}</strong>. @endif</p>
                @error('target.period_start') <p class="error sm:col-span-2">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="card" aria-labelledby="sec-goal">
            <div class="card-header"><h2 id="sec-goal" class="text-sm font-semibold text-slate-900">The goal</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="g-value" class="label">Order value (₹, before tax)</label>
                    <input id="g-value" type="text" inputmode="decimal" wire:model="target.target_value" class="input tabular-nums" autocomplete="off" placeholder="e.g. 500000">
                    @error('target.target_value') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="g-qty" class="label">Quantity (in each item’s base unit)</label>
                    <input id="g-qty" type="text" inputmode="decimal" wire:model="target.target_quantity" class="input tabular-nums" autocomplete="off" placeholder="e.g. 2500">
                    @error('target.target_quantity') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="g-group" class="label">Item group <span class="font-normal text-slate-500">(optional)</span></label>
                    <select id="g-group" wire:model="target.item_group_id" class="input">
                        <option value="">All items</option>
                        @foreach ($groups as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('target.item_group_id') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="g-branch" class="label">Branch <span class="font-normal text-slate-500">(optional)</span></label>
                    <select id="g-branch" wire:model="target.location_id" class="input">
                        <option value="">All branches</option>
                        @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @error('target.location_id') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="flex items-start gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="target.allow_overlap" class="mt-0.5 rounded border-slate-300">
                        <span>Allow this target to overlap others for the same person and scope<br><span class="text-xs text-slate-500">For a campaign target that runs alongside the regular one. Both then count.</span></span>
                    </label>
                </div>
                <div>
                    <label for="g-status" class="label">Status</label>
                    <select id="g-status" wire:model="target.status" class="input">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label for="g-remarks" class="label">Remarks <span class="font-normal text-slate-500">(optional)</span></label>
                    <textarea id="g-remarks" rows="2" wire:model="target.remarks" class="input" maxlength="1000"></textarea>
                    @error('target.remarks') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <div class="flex justify-end gap-3">
            <a href="{{ route('field.targets.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $editing ? 'Save target' : 'Set target' }}</button>
        </div>
    </form>
</div>
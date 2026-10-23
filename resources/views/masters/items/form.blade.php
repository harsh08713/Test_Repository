@php
    $purchase = $section === 'purchase';
    $unitName = fn ($id) => $units[(int) $id] ?? null;
@endphp

<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ $editing ? ($purchase ? 'Purchase details' : 'Inventory details') : 'New item' }}@if ($editing && $summary): <span class="font-normal text-slate-500">{{ $summary->name }}</span>@endif</h1>
            <p class="mt-1 text-sm text-slate-500">
                @if ($purchase) Stage 1 of 3, Purchase: what the item is, how it is counted and taxed. When complete, send it to Inventory.
                @else Stage 2 of 3, Inventory: assign the item code, other names it goes by and the stock levels per branch. Then submit it for approval. @endif
            </p>
        </div>
        <a href="{{ $editing ? route('masters.items.show', $itemId) : route('masters.items.index') }}" wire:navigate class="btn-secondary">Cancel</a>
    </div>

    @if ($errors->any())
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">
            @error('form'){{ $message }}@else Some details need attention. Please check the fields marked below.@enderror
        </div>
    @endif

    @if ($approvedBefore)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            This item has been approved before, so it may already be on documents: its @if ($purchase) base unit and existing unit conversions @else code @endif can no longer be changed. @if ($purchase) You can still add new units. @endif
        </div>
    @endif

    @if (! $purchase && $summary)
        <section class="card" aria-labelledby="sec-summary">
            <div class="card-header"><h2 id="sec-summary" class="text-sm font-semibold text-slate-900">From Purchase</h2></div>
            <dl class="card-body grid gap-x-8 gap-y-3 text-sm sm:grid-cols-3">
                <div><dt class="text-xs text-slate-500">Group</dt><dd>{{ $summary->itemGroup?->name }}</dd></div>
                <div><dt class="text-xs text-slate-500">Base unit</dt><dd>{{ $summary->baseUnit?->name }} ({{ $summary->baseUnit?->code }})</dd></div>
                <div><dt class="text-xs text-slate-500">HSN / GST</dt><dd>{{ $summary->hsn_code }} · {{ rtrim(rtrim((string) $summary->gst_rate, '0'), '.') }}%</dd></div>
                @foreach ($summary->uoms as $u)
                    <div><dt class="text-xs text-slate-500">Conversion</dt><dd>1 {{ $u->unit->name }} = {{ rtrim(rtrim($u->factor, '0'), '.') }} {{ $summary->baseUnit?->name }}</dd></div>
                @endforeach
            </dl>
        </section>
    @endif

    @if ($purchase)
        <form wire:submit="savePurchase" class="space-y-5" novalidate>
            <section class="card" aria-labelledby="sec-item">
                <div class="card-header"><h2 id="sec-item" class="text-sm font-semibold text-slate-900">Item</h2></div>
                <div class="card-body grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="i-name" class="label">Name</label>
                        <input id="i-name" type="text" wire:model="item.name" class="input" maxlength="200" required>
                        @error('item.name') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="i-group" class="label">Item group</label>
                        <select id="i-group" wire:model="item.item_group_id" class="input" required>
                            <option value="">Select…</option>
                            @foreach ($groups as $id => $path)<option value="{{ $id }}">{{ $path }}</option>@endforeach
                        </select>
                        @error('item.item_group_id') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="i-brand" class="label">Brand</label>
                        <select id="i-brand" wire:model="item.brand_id" class="input">
                            <option value="">No brand</option>
                            @foreach ($brands as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                        </select>
                        @error('item.brand_id') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="i-industry" class="label">Industry</label>
                        <select id="i-industry" wire:model="item.industry_template_id" class="input">
                            <option value="">Not assigned</option>
                            @foreach ($industries as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                        </select>
                        <p class="help">Places this product’s sales in an industry on the dashboard. Only industries your company has switched on.</p>
                        @error('item.industry_template_id') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="i-cost" class="label">Standard cost (₹ per base unit)</label>
                        <input id="i-cost" type="text" wire:model="item.standard_cost" class="input" inputmode="decimal" autocomplete="off">
                        <p class="help">What one base unit costs you. An invoice line copies it when the invoice is made, so profit can be worked out later. Leave blank if not known: the cost of a sale is then shown as unknown, never as zero.</p>
                        @error('item.standard_cost') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="i-unit" class="label">Base unit</label>
                        <select id="i-unit" wire:model.live="item.base_unit_id" class="input" @disabled($approvedBefore) required>
                            <option value="">Select…</option>
                            @foreach ($units as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                        </select>
                        <p class="help">The unit stock is kept in. Other units are converted to it.</p>
                        @error('item.base_unit_id') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="i-hsn" class="label">HSN / SAC code</label>
                        <input id="i-hsn" type="text" wire:model="item.hsn_code" class="input font-mono" maxlength="12" inputmode="numeric" autocomplete="off">
                        <p class="help">4, 6 or 8 digits. Needed before the item can go to Inventory.</p>
                        @error('item.hsn_code') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="i-gst" class="label">GST rate (%)</label>
                        <select id="i-gst" wire:model="item.gst_rate" class="input">
                            <option value="">Select…</option>
                            @foreach ($gstRates as $rate)<option value="{{ $rate }}">{{ $rate }}%</option>@endforeach
                        </select>
                        @error('item.gst_rate') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="i-tol" class="label">PO tolerance (%)</label>
                        <input id="i-tol" type="number" min="0" max="100" step="0.01" wire:model="item.po_tolerance_percent" class="input" inputmode="decimal">
                        <p class="help">How far above the ordered quantity a receipt may go.</p>
                        @error('item.po_tolerance_percent') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="i-desc" class="label">Description</label>
                        <textarea id="i-desc" rows="2" wire:model="item.description" class="input" maxlength="1000"></textarea>
                        @error('item.description') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            <section class="card" aria-labelledby="sec-uoms">
                <div class="card-header">
                    <h2 id="sec-uoms" class="text-sm font-semibold text-slate-900">Other units <span class="font-normal text-slate-500">(optional)</span></h2>
                    @if (count($uoms) < $maxRows['uoms'])
                        <button type="button" wire:click="addUom" class="btn-secondary btn-sm">Add unit</button>
                    @endif
                </div>
                <div class="card-body space-y-3">
                    @error('uoms') <p class="error">{{ $message }}</p> @enderror
                    @forelse ($uoms as $i => $row)
                        <div class="grid items-start gap-3 sm:grid-cols-[1fr_auto_1fr_auto]" wire:key="uom-{{ $row['key'] ?? $i }}">
                            <div>
                                <label for="u{{ $i }}-unit" class="label">Unit</label>
                                <select id="u{{ $i }}-unit" wire:model.live="uoms.{{ $i }}.unit_id" class="input">
                                    <option value="">Select…</option>
                                    @foreach ($units as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                                </select>
                                @error("uoms.$i.unit_id") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div class="pt-8 text-sm text-slate-600">= </div>
                            <div>
                                <label for="u{{ $i }}-factor" class="label">How many {{ $baseUnit?->name ?? 'base units' }}?</label>
                                <input id="u{{ $i }}-factor" type="text" inputmode="decimal" wire:model.live="uoms.{{ $i }}.factor" class="input" autocomplete="off">
                                @if ($unitName($row['unit_id'] ?? 0) && ($row['factor'] ?? '') !== '' && $baseUnit)
                                    <p class="help">1 {{ \Illuminate\Support\Str::before($unitName($row['unit_id']), ' (') }} = {{ $row['factor'] }} {{ $baseUnit->name }}</p>
                                @endif
                                @error("uoms.$i.factor") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div class="pt-7">
                                <button type="button" wire:click="removeUom({{ $i }})" wire:confirm="Remove this unit?" class="btn-ghost btn-sm text-rose-600">Remove</button>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Only the base unit. Add a unit such as a Box when the item is also bought or sold in it (1 Box = 12 Nos).</p>
                    @endforelse
                </div>
            </section>

            <div class="flex justify-end gap-3">
                <a href="{{ $editing ? route('masters.items.show', $itemId) : route('masters.items.index') }}" wire:navigate class="btn-secondary">Cancel</a>
                <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $editing ? 'Save' : 'Start item' }}</button>
            </div>
        </form>
    @else
        <form wire:submit="saveInventory" class="space-y-5" novalidate>
            <section class="card" aria-labelledby="sec-code">
                <div class="card-header"><h2 id="sec-code" class="text-sm font-semibold text-slate-900">Item code and names</h2></div>
                <div class="card-body space-y-4">
                    <div class="max-w-sm">
                        <label for="inv-code" class="label">Item code</label>
                        <div class="flex gap-2">
                            <input id="inv-code" type="text" wire:model="inventory.code" class="input uppercase font-mono" maxlength="30" autocomplete="off" @disabled($approvedBefore && ($inventory['code'] ?? '') !== '')>
                            @unless ($approvedBefore)<button type="button" wire:click="suggestCode" class="btn-secondary btn-sm shrink-0">Use {{ $suggestedCode }}</button>@endunless
                        </div>
                        <p class="help">Unique within the company. Required before the item can be submitted.</p>
                        @error('inventory.code') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label for="inv-tracking" class="label">Tracking</label>
                            <select id="inv-tracking" wire:model="inventory.tracking" class="input">
                                <option value="none">None (count only)</option>
                                <option value="batch">By batch / lot</option>
                            </select>
                            @error('inventory.tracking') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <span class="label">Expires</span>
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="inventory.has_expiry" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"> Has an expiry date</label>
                        </div>
                        <div>
                            <label for="inv-shelf" class="label">Shelf life (days)</label>
                            <input id="inv-shelf" type="number" min="1" wire:model="inventory.shelf_life_days" class="input" inputmode="numeric">
                            @error('inventory.shelf_life_days') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <span class="label mb-0">Alias names</span>
                            @if (count($aliases) < $maxRows['aliases'])<button type="button" wire:click="addAlias" class="btn-secondary btn-sm">Add alias</button>@endif
                        </div>
                        @error('aliases') <p class="error">{{ $message }}</p> @enderror
                        @forelse ($aliases as $i => $row)
                            <div class="mb-2 flex items-start gap-2" wire:key="alias-{{ $row['key'] ?? $i }}">
                                <div class="flex-1">
                                    <label for="al{{ $i }}" class="sr-only">Alias {{ $i + 1 }}</label>
                                    <input id="al{{ $i }}" type="text" wire:model="aliases.{{ $i }}.alias" class="input" maxlength="150" placeholder="Another name people use for it">
                                    @error("aliases.$i.alias") <p class="error">{{ $message }}</p> @enderror
                                </div>
                                <button type="button" wire:click="removeAlias({{ $i }})" class="btn-ghost btn-sm text-rose-600">Remove</button>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">No aliases. They let people find the item by the other names it goes by.</p>
                        @endforelse
                    </div>
                </div>
            </section>

            <section class="card" aria-labelledby="sec-stock">
                <div class="card-header">
                    <h2 id="sec-stock" class="text-sm font-semibold text-slate-900">Stock levels per branch <span class="font-normal text-slate-500">(in {{ $summary?->baseUnit?->name ?? 'the base unit' }})</span></h2>
                    @if (count($locations) < $maxRows['locations'])<button type="button" wire:click="addLocation" class="btn-secondary btn-sm">Add branch</button>@endif
                </div>
                <div class="card-body space-y-4">
                    @error('locations') <p class="error">{{ $message }}</p> @enderror
                    @forelse ($locations as $i => $row)
                        <div class="grid items-start gap-3 sm:grid-cols-[1.4fr_1fr_1fr_1fr_auto]" wire:key="loc-{{ $row['key'] ?? $i }}">
                            <div>
                                <label for="lo{{ $i }}-branch" class="label">Branch</label>
                                <select id="lo{{ $i }}-branch" wire:model="locations.{{ $i }}.location_id" class="input">
                                    <option value="">Select…</option>
                                    @foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                                </select>
                                @error("locations.$i.location_id") <p class="error">{{ $message }}</p> @enderror
                            </div>
                            @foreach (['minimum_stock' => 'Minimum', 'reorder_level' => 'Reorder at', 'maximum_stock' => 'Maximum'] as $field => $label)
                                <div>
                                    <label for="lo{{ $i }}-{{ $field }}" class="label">{{ $label }}</label>
                                    <input id="lo{{ $i }}-{{ $field }}" type="text" inputmode="decimal" wire:model="locations.{{ $i }}.{{ $field }}" class="input tabular-nums" autocomplete="off">
                                    @error("locations.$i.$field") <p class="error">{{ $message }}</p> @enderror
                                </div>
                            @endforeach
                            <div class="pt-7"><button type="button" wire:click="removeLocation({{ $i }})" wire:confirm="Remove this branch?" class="btn-ghost btn-sm text-rose-600">Remove</button></div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">No stock levels yet. Add a branch to set its minimum, reorder level and maximum (minimum ≤ reorder ≤ maximum).</p>
                    @endforelse
                </div>
            </section>

            <div class="flex justify-end gap-3">
                <a href="{{ route('masters.items.show', $itemId) }}" wire:navigate class="btn-secondary">Cancel</a>
                <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save</button>
            </div>
        </form>
    @endif
</div>

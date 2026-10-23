{{--
    The item lines an inquiry or a deal is made of. The form passes:
      $prop         the public array that holds the rows ('interests' or 'items'), so wire:model paths and error keys line up
      $rows         that array
      $itemUnits    per row: the units its item is sold in
      $results      per row: what its open search found
      $title        the card heading; $emptyText what to say when there are no rows
      $max          most rows allowed
--}}
<section class="card" aria-labelledby="sec-items">
    <div class="card-header">
        <h2 id="sec-items" class="text-sm font-semibold text-slate-900">{{ $title }}</h2>
        @if (count($rows) < $max)<button type="button" wire:click="addRow" class="btn-secondary btn-sm">Add an item</button>@endif
    </div>
    <div class="card-body space-y-3">
        @error($prop) <p class="error">{{ $message }}</p> @enderror

        @forelse ($rows as $i => $row)
            <div class="grid gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-12" wire:key="row-{{ $row['key'] ?? $i }}">
                <div class="sm:col-span-4">
                    @if (($row['item_id'] ?? '') !== '')
                        <span class="label">Item</span>
                        <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                            <span class="truncate text-slate-900">{{ ($row['item_label'] ?? '') !== '' ? $row['item_label'] : 'Item #'.$row['item_id'] }}</span>
                            @if (! ($row['locked_item'] ?? false))<button type="button" wire:click="clearItem({{ $i }})" class="shrink-0 text-xs text-brand-700 hover:underline">Change</button>@endif
                        </div>
                        @error("$prop.$i.item_id") <p class="error">{{ $message }}</p> @enderror
                    @else
                        <label for="r{{ $i }}-search" class="label">Find an item</label>
                        <input id="r{{ $i }}-search" type="search" wire:model.live.debounce.300ms="{{ $prop }}.{{ $i }}.search" class="input" autocomplete="off" placeholder="Code, name or HSN…">
                        @if (! empty($results[$i]))
                            <ul class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                                @foreach ($results[$i] as $hit)
                                    <li><button type="button" wire:click="pickItem({{ $i }}, {{ $hit['id'] }})" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">{{ $hit['label'] }}</button></li>
                                @endforeach
                            </ul>
                        @elseif (trim((string) ($row['search'] ?? '')) !== '')
                            <p class="help">Nothing found: only approved, active items can be chosen.</p>
                        @endif
                        @if (($row['description'] ?? '') !== '')<p class="help">The lead described this as “{{ $row['description'] }}”. Choose the item it is.</p>@endif
                        @error("$prop.$i.item_id") <p class="error">{{ $message }}</p> @enderror
                    @endif
                </div>
                <div class="sm:col-span-2">
                    <label for="r{{ $i }}-qty" class="label">Quantity</label>
                    <input id="r{{ $i }}-qty" type="text" inputmode="decimal" wire:model.live.debounce.600ms="{{ $prop }}.{{ $i }}.quantity" class="input tabular-nums" autocomplete="off">
                    @error("$prop.$i.quantity") <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="r{{ $i }}-unit" class="label">Unit</label>
                    <select id="r{{ $i }}-unit" wire:model="{{ $prop }}.{{ $i }}.unit_id" class="input">
                        <option value="">—</option>
                        @foreach ($itemUnits[$i] ?? [] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @error("$prop.$i.unit_id") <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="r{{ $i }}-rate" class="label">Rate expected (₹)</label>
                    <input id="r{{ $i }}-rate" type="text" inputmode="decimal" wire:model.live.debounce.600ms="{{ $prop }}.{{ $i }}.expected_rate" class="input tabular-nums" autocomplete="off">
                    @error("$prop.$i.expected_rate") <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-end justify-end sm:col-span-1">
                    @if (($row['lead_interest_id'] ?? '') === '')
                        <button type="button" wire:click="removeRow({{ $i }})" class="btn-ghost btn-sm text-rose-600" aria-label="Remove item {{ $i + 1 }}">Remove</button>
                    @else
                        <span class="text-xs text-slate-400" title="The lead listed this item, so it comes across to the deal.">From lead</span>
                    @endif
                </div>
                @error("$prop.$i.id") <p class="error sm:col-span-12">{{ $message }}</p> @enderror
                @error("$prop.$i.lead_interest_id") <p class="error sm:col-span-12">{{ $message }}</p> @enderror
            </div>
        @empty
            <p class="text-sm text-slate-500">{{ $emptyText }}</p>
        @endforelse
    </div>
</section>

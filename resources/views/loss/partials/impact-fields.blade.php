{{--
    The fields of one impact figure. The parent passes:
      $prop      the public array they bind to ('impact' on the report form, 'line' on the incident page)
      $formula   the chosen formula's definition from LossCalculationService::formulas(), or null
      $formulas  all of them; $types the impact types
      $lockType  true when recalculating: kind and formula stay as they were
      $kind      the kind currently chosen (actual / exposure / opportunity)
--}}
<div class="card-body grid gap-4 sm:grid-cols-3">
    <div>
        <label for="{{ $prop }}-type" class="label">Kind</label>
        <select id="{{ $prop }}-type" wire:model.live="{{ $prop }}.impact_type" class="input" @disabled($lockType)>
            @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        @error($prop.'.impact_type')<p class="error">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <label for="{{ $prop }}-formula" class="label">Worked out as</label>
        <select id="{{ $prop }}-formula" wire:model.live="{{ $prop }}.formula_key" class="input" @disabled($lockType)>
            @foreach ($formulas as $key => $f)<option value="{{ $key }}">{{ $f['label'] }}</option>@endforeach
        </select>
        @error($prop.'.formula_key')<p class="error">{{ $message }}</p>@enderror
    </div>
    @foreach ($formula['inputs'] ?? [] as $name => [$label, $kind, $required])
        <div wire:key="{{ $prop }}-in-{{ $name }}">
            <label for="{{ $prop }}-in-{{ $name }}" class="label">{{ $label }}@unless ($required) <span class="text-xs text-slate-500">(optional)</span>@endunless</label>
            <input id="{{ $prop }}-in-{{ $name }}" type="text" inputmode="decimal" wire:model="{{ $prop }}.inputs.{{ $name }}" class="input tabular-nums" autocomplete="off">
            @error($prop.'.inputs.'.$name)<p class="error">{{ $message }}</p>@enderror
        </div>
    @endforeach
    @error($prop.'.inputs')<p class="error sm:col-span-3">{{ $message }}</p>@enderror
    <div class="sm:col-span-3">
        <label for="{{ $prop }}-ass" class="label">Assumptions{{ ($formula !== null && array_key_exists('amount', $formula['inputs'])) || $kind === 'opportunity' ? '' : ' (optional)' }}</label>
        <textarea id="{{ $prop }}-ass" rows="2" wire:model="{{ $prop }}.assumptions" class="input" maxlength="2000"></textarea>
        @error($prop.'.assumptions')<p class="error">{{ $message }}</p>@enderror
    </div>
    @if ($kind === 'opportunity')
        <div>
            <label for="{{ $prop }}-conf" class="label">Confidence (%)</label>
            <input id="{{ $prop }}-conf" type="text" inputmode="numeric" wire:model="{{ $prop }}.confidence_pct" class="input tabular-nums">
            @error($prop.'.confidence_pct')<p class="error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="{{ $prop }}-period" class="label">Target period</label>
            <input id="{{ $prop }}-period" type="text" wire:model="{{ $prop }}.target_period" class="input" maxlength="40" placeholder="e.g. Q4 FY26-27">
            @error($prop.'.target_period')<p class="error">{{ $message }}</p>@enderror
        </div>
    @endif
</div>

<div class="mx-auto max-w-3xl space-y-5">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Company settings</h1>
        <p class="mt-1 text-sm text-slate-500">Behaviour that applies to the company you are working in. Approval levels and number series have their own pages.</p>
    </div>

    <x-status />

    <form wire:submit="save" class="space-y-5" novalidate>
        @foreach ($groups as $group => $settings)
            <div class="card" wire:key="group-{{ $group }}">
                <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">{{ $group }}</h2></div>
                <div class="card-body space-y-5">
                    @foreach ($settings as $setting)
                        <div wire:key="setting-{{ $setting['field'] }}">
                            @switch($setting['type'])
                                @case('bool')
                                    <label class="flex items-start gap-3 text-sm">
                                        <input type="checkbox" wire:model="values.{{ $setting['field'] }}" @disabled(! $canEdit) class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        <span><span class="font-medium text-slate-800">{{ $setting['label'] }}</span>
                                            @isset($setting['help'])<span class="help block">{{ $setting['help'] }}</span>@endisset</span>
                                    </label>
                                    @break

                                @case('select')
                                    <label for="s-{{ $setting['field'] }}" class="label">{{ $setting['label'] }}</label>
                                    <select id="s-{{ $setting['field'] }}" wire:model="values.{{ $setting['field'] }}" @disabled(! $canEdit) class="input max-w-xs">
                                        @foreach ($setting['options'] as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @isset($setting['help'])<p class="help">{{ $setting['help'] }}</p>@endisset
                                    @break

                                @case('int')
                                    <label for="s-{{ $setting['field'] }}" class="label">{{ $setting['label'] }}</label>
                                    <input id="s-{{ $setting['field'] }}" type="number" min="{{ $setting['min'] ?? 0 }}" max="{{ $setting['max'] ?? '' }}" wire:model="values.{{ $setting['field'] }}" @disabled(! $canEdit) class="input max-w-xs">
                                    @isset($setting['help'])<p class="help">{{ $setting['help'] }}</p>@endisset
                                    @break

                                @case('secret')
                                    <label for="s-{{ $setting['field'] }}" class="label">{{ $setting['label'] }}
                                        <span class="badge ml-2 {{ $setting['is_set'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $setting['is_set'] ? 'Saved' : 'Not set' }}</span>
                                    </label>
                                    <input id="s-{{ $setting['field'] }}" type="password" autocomplete="off" wire:model="secrets.{{ $setting['field'] }}" @disabled(! $canEdit)
                                           placeholder="{{ $setting['is_set'] ? 'Enter a new value to replace the saved one' : 'Paste the value' }}" class="input max-w-md">
                                    @if ($setting['is_set'] && $canEdit)
                                        <label class="mt-2 flex items-center gap-2 text-xs text-slate-600">
                                            <input type="checkbox" wire:model="clear.{{ $setting['field'] }}" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500"> Remove the saved value
                                        </label>
                                    @endif
                                    @isset($setting['help'])<p class="help">{{ $setting['help'] }}</p>@endisset
                                    <p class="help">Stored encrypted. The saved value is never shown again.</p>
                                    @break

                                @default
                                    <label for="s-{{ $setting['field'] }}" class="label">{{ $setting['label'] }}</label>
                                    <input id="s-{{ $setting['field'] }}" type="text" wire:model="values.{{ $setting['field'] }}" @disabled(! $canEdit) class="input max-w-md">
                            @endswitch

                            @error('values.'.$setting['field']) <p class="error">{{ $message }}</p> @enderror
                            @error('secrets.'.$setting['field']) <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        @if ($canEdit)
            <div class="flex justify-end"><button type="submit" class="btn-primary" wire:loading.attr="disabled">Save settings</button></div>
        @endif
    </form>
</div>

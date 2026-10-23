<div class="mx-auto max-w-6xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('industries.index') }}" wire:navigate class="hover:text-brand-700">Industries</a></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $industry->name }}
            <span class="text-base font-normal text-slate-500">· version {{ $config['version'] }}</span></h1>
        <p class="mt-1 text-sm text-slate-600">{{ $industry->description }}</p>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <span class="badge {{ $config['enabled'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $config['enabled'] ? 'On for this company' : 'Off for this company' }}</span>
            @if ($behind)
                <span class="text-xs text-amber-700">A newer version ({{ $industry->version }}) exists.</span>
                @if ($canConfigure)<button type="button" wire:click="moveToCurrentVersion" class="btn-secondary btn-sm">Move to version {{ $industry->version }}</button>@endif
            @endif
        </div>
        @error('version')<p class="error">{{ $message }}</p>@enderror
    </div>

    <x-status />

    <div class="card">
        <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">KPIs and thresholds</h2></div>
        <p class="px-5 pt-3 text-xs text-slate-500">The starting numbers come with the template; they are examples, not standards. Set your own: <strong>green</strong> is the good side of <strong>amber</strong>. Leave a box empty to use the template’s number.</p>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>KPI</th><th>Formula</th><th>Better when</th><th>Green</th><th>Amber</th><th>Red</th><th></th></tr></thead>
                <tbody>
                    @foreach ($config['kpis'] as $kpi)
                        <tr wire:key="kpi-{{ $kpi->code }}">
                            <td class="font-medium text-slate-900">{{ $kpi->name }}@if ($kpi->unit !== '') <span class="text-xs text-slate-500">({{ $kpi->unit }})</span>@endif
                                @isset($overrides[$kpi->code])<span class="badge bg-brand-50 text-brand-700">Your numbers</span>@endisset</td>
                            <td class="text-xs text-slate-600">
                                {{ $kpi->numerator_desc }}@if ($kpi->denominator_desc) ÷ {{ $kpi->denominator_desc }}@endif
                            </td>
                            <td class="text-xs">{{ $kpi->direction === 'higher_better' ? 'Higher' : 'Lower' }}</td>
                            @foreach (['green', 'amber', 'red'] as $level)
                                <td class="w-28">
                                    @if ($canConfigure)
                                        <label class="sr-only" for="t-{{ $kpi->code }}-{{ $level }}">{{ $kpi->name }} {{ $level }}</label>
                                        <input id="t-{{ $kpi->code }}-{{ $level }}" type="text" inputmode="decimal" wire:model="thresholds.{{ $kpi->code }}.{{ $level }}" class="input">
                                    @else
                                        {{ $thresholds[$kpi->code][$level] ?? '' }}
                                    @endif
                                </td>
                            @endforeach
                            <td class="whitespace-nowrap">
                                @if ($canConfigure)<button type="button" wire:click="saveThresholds('{{ $kpi->code }}')" class="btn-secondary btn-sm">Save</button>@endif
                                @error('kpi-'.$kpi->code)<p class="error">{{ $message }}</p>@enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Loss categories</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($config['categories'] as $category)
                    <li class="flex justify-between gap-3 px-5 py-2"><span>{{ $category->name }}</span><span class="text-xs text-slate-500">{{ $category->default_formula_key }} · {{ $category->default_severity }}</span></li>
                @endforeach
            </ul>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Extra incident fields</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($config['fields'] as $field)
                    <li class="flex justify-between gap-3 px-5 py-2">
                        <span>{{ $field->label }}@if ($field->is_required) <span class="text-rose-600" title="Required">*</span>@endif @if ($field->unit)<span class="text-xs text-slate-500">({{ $field->unit }})</span>@endif</span>
                        <span class="text-xs text-slate-500">{{ $field->type }}@if ($field->options) · {{ implode(', ', $field->options) }}@endif</span>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Workflow</h2></div>
            <div class="card-body space-y-2 text-sm">
                <p class="flex flex-wrap gap-1">
                    @foreach ($config['states'] as $state)
                        <span class="badge bg-slate-100 text-slate-700">{{ $state->name }}</span>
                    @endforeach
                </p>
                <p class="text-xs text-slate-500">{{ $config['transitions']->count() }} allowed moves. Loss incidents use this workflow from the Loss Management phase.</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Recommended reports</h2></div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($config['reports'] as $report)
                    <li class="px-5 py-2">{{ $report->name }}</li>
                @endforeach
            </ul>
        </div>
    </div>
</div>

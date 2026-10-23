{{-- Period, industry and branch. Lives in the query string; every value is re-checked on the server (AnalyticsFilter). --}}
<div class="card card-body flex flex-wrap items-end gap-3" role="search" aria-label="Filters">
    <div>
        <label class="label" for="flt-range">Period</label>
        <select id="flt-range" wire:model.live="range" class="input">
            @foreach ($presets as $key => $name)
                <option value="{{ $key }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>
    @if ($range === 'custom')
        <div><label class="label" for="flt-from">From</label><input id="flt-from" type="date" wire:model.live="from" class="input"></div>
        <div><label class="label" for="flt-to">To</label><input id="flt-to" type="date" wire:model.live="to" class="input"></div>
    @endif
    <div>
        <label class="label" for="flt-industry">Industry</label>
        <select id="flt-industry" wire:model.live="industry" class="input">
            <option value="">All industries</option>
            @foreach ($industries as $row)
                <option value="{{ $row['id'] }}">{{ $row['name'] }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="label" for="flt-branch">Branch</label>
        <select id="flt-branch" wire:model.live="branch" class="input">
            <option value="">All my branches</option>
            @foreach ($branches as $row)
                <option value="{{ $row['id'] }}">{{ $row['name'] }}</option>
            @endforeach
        </select>
    </div>
    <p class="ml-auto text-xs text-slate-500">{{ $f->label() }}</p>
</div>

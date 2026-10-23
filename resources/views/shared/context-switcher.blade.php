<form method="POST" action="{{ route('context.switch') }}" class="flex min-w-0 items-center gap-2">
    @csrf
    @if ($companies->count() > 1)
        <label class="sr-only" for="ctx-company">Company</label>
        <select id="ctx-company" name="company_id" onchange="this.form.submit()"
                class="input min-w-0 max-w-full flex-1 truncate py-1.5 pr-8 font-medium lg:max-w-56 lg:flex-none">
            @foreach ($companies as $company)
                <option value="{{ $company->id }}" @selected($company->id === $activeCompanyId)>{{ $company->name }}</option>
            @endforeach
        </select>
    @else
        <span class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-700">
            <x-icon name="building" class="size-4 text-slate-400" /> {{ $companies->first()?->name ?? 'No company' }}
        </span>
    @endif

    @if ($locations->count() > 0)
        <label class="sr-only" for="ctx-location">Branch</label>
        <select id="ctx-location" name="location_id" onchange="this.form.submit()"
                class="input min-w-0 max-w-full flex-1 truncate py-1.5 pr-8 lg:max-w-56 lg:flex-none">
            @if ($locations->count() > 1)
                <option value="">All my branches</option>
            @endif
            @foreach ($locations as $location)
                <option value="{{ $location->id }}" @selected($location->id === $activeLocationId)>{{ $location->code }} · {{ $location->name }}</option>
            @endforeach
        </select>
    @endif
</form>

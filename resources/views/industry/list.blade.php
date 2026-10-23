<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Industries</h1>
        <p class="mt-1 text-sm text-slate-500">Industry templates set the KPIs, loss categories, extra incident fields and workflow your company works with. Switch on the ones that apply to you; the shared template is never changed by a company.</p>
    </div>

    <x-status />
    @error('list')
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>
    @enderror

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($templates as $template)
            @php $on = $pinned->has($template->id); @endphp
            <div class="card" wire:key="tpl-{{ $template->id }}">
                <div class="card-body space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="text-base font-semibold text-slate-900">
                            <a href="{{ route('industries.show', $template->code) }}" wire:navigate class="hover:text-brand-700">{{ $template->name }}</a>
                        </h2>
                        <span class="badge {{ $on ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $on ? 'On' : 'Off' }}</span>
                    </div>
                    <p class="text-sm text-slate-600">{{ $template->description }}</p>
                    <p class="text-xs text-slate-500">
                        {{ $template->kpi_count }} KPIs · {{ $template->category_count }} loss categories · version {{ $on ? $pinned[$template->id] : $template->version }}
                    </p>
                    <div class="flex gap-2">
                        <a href="{{ route('industries.show', $template->code) }}" wire:navigate class="btn-secondary btn-sm">Open</a>
                        @if ($canConfigure)
                            @if ($on)
                                <button type="button" wire:click="disableTemplate({{ $template->id }})" wire:confirm="Switch off {{ $template->name }} for this company?" class="btn-ghost btn-sm">Switch off</button>
                            @else
                                <button type="button" wire:click="enableTemplate({{ $template->id }})" class="btn-primary btn-sm">Switch on</button>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>

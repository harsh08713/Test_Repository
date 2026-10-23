@php use App\Modules\Shared\Support\Format; @endphp
<div class="space-y-5">
    <div class="max-w-3xl">
        <h1 class="text-2xl font-semibold text-slate-900">Consent and opt-outs</h1>
        <p class="mt-1 text-sm text-slate-500">What each e-mail address and mobile number has agreed to or refused, channel by channel. The newest answer decides; an opt-out always stops marketing until the person opts in again. Every answer is kept, with where it came from and who recorded it.</p>
    </div>

    <x-status />

    @if ($canRecord)
        <form wire:submit="record" class="card" novalidate aria-label="Record an answer">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Record an answer</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-1"><label for="f-ch" class="label">Channel</label><select id="f-ch" wire:model="f.channel" class="input">@foreach ($channels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('channel') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-2"><label for="f-addr" class="label">E-mail address or mobile number</label><input id="f-addr" type="text" wire:model="f.address" class="input" maxlength="150">@error('address') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-1"><label for="f-st" class="label">Answer</label><select id="f-st" wire:model="f.status" class="input"><option value="opted_out">Opted out</option><option value="opted_in">Opted in</option></select>@error('status') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-2"><label for="f-note" class="label">Note <span class="font-normal text-slate-400">(where it came from)</span></label><input id="f-note" type="text" wire:model="f.note" class="input" maxlength="300"></div>
            </div>
            <div class="flex justify-end border-t border-slate-200 px-5 py-3"><button type="submit" class="btn-primary">Record</button></div>
        </form>
    @endif

    <div class="card">
        <div class="card-header flex-wrap">
            <div class="flex flex-1 flex-wrap items-end gap-3">
                <div><label for="k-q" class="block text-xs text-slate-500">Search</label><input id="k-q" type="search" wire:model.live.debounce.300ms="search" class="input w-56" placeholder="Part of an address…"></div>
                <div><label for="k-ch" class="block text-xs text-slate-500">Channel</label><select id="k-ch" wire:model.live="channel" class="input w-36"><option value="">All</option>@foreach ($channels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                <div><label for="k-st" class="block text-xs text-slate-500">Answer</label><select id="k-st" wire:model.live="status" class="input w-36"><option value="">Any</option><option value="opted_in">Opted in</option><option value="opted_out">Opted out</option></select></div>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Address</th><th>Channel</th><th>Answer</th><th>Where from</th><th>When</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php $key = $row->channel->value.'|'.$row->address; @endphp
                        <tr wire:key="co-{{ $row->id }}">
                            <td class="font-mono text-sm text-slate-900">{{ $row->address }}</td>
                            <td>{{ $row->channel->label() }}</td>
                            <td><span class="badge {{ $row->status === 'opted_in' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}">{{ $row->status === 'opted_in' ? 'Opted in' : 'Opted out' }}</span></td>
                            <td class="text-sm text-slate-600">{{ $row->source }}{{ $row->recorder ? ' · '.$row->recorder->name : '' }}{{ $row->note ? ' · '.$row->note : '' }}</td>
                            <td class="whitespace-nowrap text-sm tabular-nums text-slate-600">{{ Format::dateTime($row->created_at) }}</td>
                            <td class="text-right"><button type="button" wire:click="toggleHistory('{{ $key }}')" class="text-xs text-brand-700 hover:underline">{{ $open === $key ? 'Hide history' : 'History' }}</button></td>
                        </tr>
                        @if ($open === $key)
                            <tr wire:key="coh-{{ $row->id }}"><td colspan="6" class="bg-slate-50 px-5 py-3">
                                <ul class="space-y-1 text-xs text-slate-700">@foreach ($history as $h)<li wire:key="h-{{ $h->id }}">{{ Format::dateTime($h->created_at) }} — {{ $h->status === 'opted_in' ? 'opted in' : 'opted out' }} ({{ $h->source }}{{ $h->recorder ? ', '.$h->recorder->name : '' }}){{ $h->note ? ': '.$h->note : '' }}</li>@endforeach</ul>
                            </td></tr>
                        @endif
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-slate-500">No answer recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($rows->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $rows->links() }}</div>@endif
    </div>
</div>

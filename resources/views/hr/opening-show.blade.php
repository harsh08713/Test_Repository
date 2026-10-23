<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-slate-500"><a href="{{ route('hr.openings') }}" wire:navigate class="hover:text-brand-700">Job openings</a></p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $o->title }} <span class="font-mono text-base font-normal text-slate-500">{{ $o->code }}</span></h1>
            <p class="mt-1 text-sm text-slate-500">{{ $o->designation?->name ?? 'No designation' }}{{ $o->department ? ' · '.$o->department->name : '' }}{{ $o->location ? ' · '.$o->location->name : '' }} · {{ $hired }} of {{ $o->positions }} filled</p>
            <p class="mt-2"><span @class(['badge', 'bg-emerald-100 text-emerald-800' => $o->state === 'open', 'bg-amber-100 text-amber-800' => $o->state === 'draft', 'bg-slate-100 text-slate-600' => $o->state === 'closed'])>{{ ['draft' => 'Draft', 'open' => 'Open', 'closed' => 'Closed'][$o->state] }}</span>@if ($o->close_reason)<span class="ml-2 text-xs text-slate-500">{{ $o->close_reason }}</span>@endif</p>
        </div>
        @if ($canEdit)
            <div class="flex flex-wrap gap-2">
                @if ($o->state !== 'open')<button type="button" wire:click="openIt" class="btn-primary">{{ $o->state === 'closed' ? 'Re-open' : 'Open for applications' }}</button>@endif
                @if ($o->state === 'open')<button type="button" wire:click="openPanel('apply')" class="btn-secondary">Add an applicant</button>@endif
                @if ($o->state !== 'closed')<button type="button" wire:click="openPanel('close')" class="btn-danger">Close</button>@endif
            </div>
        @endif
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('state')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if ($panel === 'close')
        <form wire:submit="closeIt" class="card card-body flex flex-wrap items-end gap-3" novalidate>
            <div class="min-w-64 flex-1"><label for="oc-why" class="label">Why is it closed?</label><input id="oc-why" type="text" wire:model="closeReason" class="input" maxlength="300">@error('close_reason')<p class="error">{{ $message }}</p>@enderror</div>
            <button type="submit" class="btn-danger">Close the opening</button>
        </form>
    @elseif ($panel === 'apply')
        <form wire:submit="addApplicant" class="card" novalidate>
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Add an applicant</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-3"><label for="ap-cand" class="label">A candidate already on file</label><select id="ap-cand" wire:model.live="candidateId" class="input"><option value="">— or fill in a new one below —</option>@foreach ($candidates as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('candidate_id')<p class="error">{{ $message }}</p>@enderror</div>
                @if ($candidateId === '')
                    <div><label for="ap-name" class="label">Name</label><input id="ap-name" type="text" wire:model="new.name" class="input" maxlength="150">@error('name')<p class="error">{{ $message }}</p>@enderror</div>
                    <div><label for="ap-mob" class="label">Mobile</label><input id="ap-mob" type="text" inputmode="numeric" wire:model="new.mobile" class="input" maxlength="10">@error('mobile')<p class="error">{{ $message }}</p>@enderror</div>
                    <div><label for="ap-mail" class="label">E-mail</label><input id="ap-mail" type="email" wire:model="new.email" class="input" maxlength="150">@error('email')<p class="error">{{ $message }}</p>@enderror</div>
                    <div><label for="ap-src" class="label">Source</label><select id="ap-src" wire:model="new.source" class="input">@foreach ($sources as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>@error('source')<p class="error">{{ $message }}</p>@enderror</div>
                    <div><label for="ap-emp" class="label">Current employer</label><input id="ap-emp" type="text" wire:model="new.current_employer" class="input" maxlength="150"></div>
                    <div><label for="ap-exp" class="label">Years of experience</label><input id="ap-exp" type="text" inputmode="decimal" wire:model="new.experience_years" class="input">@error('experience_years')<p class="error">{{ $message }}</p>@enderror</div>
                @endif
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><button type="submit" class="btn-primary">Add applicant</button></div>
        </form>
    @endif

    @if ($o->description)<div class="card card-body text-sm text-slate-700 whitespace-pre-line">{{ $o->description }}</div>@endif

    <div class="card">
        <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Applicants ({{ $applications->count() }})</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Candidate</th><th>Contact</th><th>Source</th><th>Stage</th></tr></thead>
                <tbody>
                    @forelse ($applications as $a)
                        <tr wire:key="app-{{ $a->id }}">
                            <td><a href="{{ route('hr.applications.show', $a) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $a->candidate?->name }}</a></td>
                            <td class="text-slate-600">{{ collect([$a->candidate?->mobile, $a->candidate?->email])->filter()->implode(' · ') }}</td>
                            <td>{{ $sources[$a->candidate?->source] ?? '—' }}</td>
                            <td><span @class(['badge', 'bg-emerald-100 text-emerald-800' => $a->stage === 'hired', 'bg-rose-100 text-rose-800' => $a->stage === 'rejected', 'bg-slate-100 text-slate-600' => $a->stage === 'withdrawn', 'bg-sky-100 text-sky-800' => in_array($a->stage, ['interview', 'offer'], true), 'bg-amber-100 text-amber-800' => in_array($a->stage, ['applied', 'screening'], true)])>{{ $stages[$a->stage] }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-10 text-center text-slate-500">Nobody has applied yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

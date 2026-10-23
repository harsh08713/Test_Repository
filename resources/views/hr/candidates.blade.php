<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="max-w-3xl">
            <h1 class="text-2xl font-semibold text-slate-900">Candidates</h1>
            <p class="mt-1 text-sm text-slate-500">Everyone on file. A person is entered once (the mobile number is unique) and can apply to several openings.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('hr.openings') }}" wire:navigate class="btn-secondary">Job openings</a>
            @if ($canCreate)<button type="button" wire:click="create" class="btn-primary">Add candidate</button>@endif
        </div>
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if ($formOpen)
        <form wire:submit="save" class="card" novalidate>
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">{{ $editingId ? 'Change the candidate' : 'New candidate' }}</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2"><label for="cd-name" class="label">Name</label><input id="cd-name" type="text" wire:model="f.name" class="input" maxlength="150">@error('name')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="cd-src" class="label">Source</label><select id="cd-src" wire:model="f.source" class="input">@foreach ($sources as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>@error('source')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="cd-mob" class="label">Mobile</label><input id="cd-mob" type="text" inputmode="numeric" wire:model="f.mobile" class="input" maxlength="10">@error('mobile')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="cd-mail" class="label">E-mail</label><input id="cd-mail" type="email" wire:model="f.email" class="input" maxlength="150">@error('email')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="cd-exp" class="label">Years of experience</label><input id="cd-exp" type="text" inputmode="decimal" wire:model="f.experience_years" class="input">@error('experience_years')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="sm:col-span-3"><label for="cd-emp" class="label">Current employer</label><input id="cd-emp" type="text" wire:model="f.current_employer" class="input" maxlength="150"></div>
                <div class="sm:col-span-3"><label for="cd-notes" class="label">Notes</label><textarea id="cd-notes" rows="2" wire:model="f.notes" class="input" maxlength="4000"></textarea></div>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><button type="button" wire:click="cancel" class="btn-secondary">Cancel</button><button type="submit" class="btn-primary">Save</button></div>
        </form>
        @if ($editingId)
            <div class="card card-body flex flex-wrap items-end gap-3">
                <div class="min-w-64 flex-1">
                    <label for="cd-resume" class="label">Résumé (PDF or Word, up to 5 MB)</label>
                    <input id="cd-resume" type="file" wire:model="resume" class="input" accept=".pdf,.doc,.docx">
                    @error('resume')<p class="error">{{ $message }}</p>@enderror
                </div>
                <button type="button" wire:click="saveResume" class="btn-secondary">Save résumé</button>
                @if ($current?->resume_path)
                    <a href="{{ route('hr.candidates.resume', $current->id) }}" class="text-sm text-brand-700 hover:underline">{{ $current->resume_name }}</a>
                    <button type="button" wire:click="dropResume" wire:confirm="Remove the résumé?" class="text-sm text-red-600 hover:underline">Remove</button>
                @endif
            </div>
        @endif
    @endif

    <div class="card">
        <div class="card-header"><label for="cd-q" class="sr-only">Search</label><input id="cd-q" type="search" wire:model.live.debounce.400ms="search" placeholder="Name, mobile or e-mail" class="input w-64" maxlength="80"></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Name</th><th>Contact</th><th>Source</th><th>Employer</th><th class="text-right">Experience</th><th class="text-right">Applications</th><th></th></tr></thead>
                <tbody>
                    @forelse ($candidates as $c)
                        <tr wire:key="cd-{{ $c->id }}">
                            <td class="font-medium text-slate-900">{{ $c->name }}@if ($c->resume_path) <a href="{{ route('hr.candidates.resume', $c->id) }}" class="ml-2 text-xs font-normal text-brand-700 hover:underline">Résumé</a>@endif</td>
                            <td class="text-slate-600">{{ collect([$c->mobile, $c->email])->filter()->implode(' · ') }}</td>
                            <td>{{ $sources[$c->source] ?? $c->source }}</td>
                            <td>{{ $c->current_employer ?? '—' }}</td>
                            <td class="text-right tabular-nums">{{ $c->experience_years !== null ? rtrim(rtrim($c->experience_years, '0'), '.').' yrs' : '—' }}</td>
                            <td class="text-right tabular-nums">{{ $c->applications_count }}</td>
                            <td class="text-right">@can('recruitment.edit')<button type="button" wire:click="edit({{ $c->id }})" class="text-xs text-brand-700 hover:underline">Edit</button>@endcan</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-slate-500">Nobody on file.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($candidates->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $candidates->links() }}</div>@endif
    </div>
</div>

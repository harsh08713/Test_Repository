@php
    $tabs = ['overview' => 'Overview', 'history' => 'History'] + ($sensitive ? ['contacts' => 'Emergency contacts', 'papers' => 'Papers'] : []);
    $current = array_key_exists($tab, $tabs) ? $tab : 'overview';
    $statusLabel = ['active' => 'Active', 'notice' => 'On notice', 'exited' => 'Left'][$e->employment_status] ?? $e->employment_status;
@endphp
<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-slate-500"><a href="{{ route('hr.employees.index') }}" wire:navigate class="hover:text-brand-700">Employees</a></p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $e->name }} <span class="font-mono text-base font-normal text-slate-500">{{ $e->code }}</span></h1>
            <p class="mt-1 text-sm text-slate-500">{{ $e->designation?->name ?? 'No designation' }}{{ $e->department ? ' · '.$e->department->name : '' }}{{ $e->location ? ' · '.$e->location->name : '' }}</p>
            <p class="mt-2 flex items-center gap-2">
                <span @class(['badge', 'bg-emerald-100 text-emerald-800' => $e->employment_status === 'active', 'bg-amber-100 text-amber-800' => $e->employment_status === 'notice', 'bg-slate-100 text-slate-600' => $e->employment_status === 'exited'])>{{ $statusLabel }}</span>
                <span class="badge bg-slate-100 text-slate-700">{{ $types[$e->employment_type] ?? $e->employment_type }}</span>
                @if ($e->employment_status === 'notice')<span class="text-xs text-amber-700">last working day {{ $e->exit_date?->format('d M Y') }}</span>@endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canEdit)
                <a href="{{ route('hr.employees.edit', $e) }}" wire:navigate class="btn-secondary">Edit details</a>
                <button type="button" wire:click="openPanel('job')" class="btn-secondary">Change job</button>
                @if ($e->confirmation_date === null && $e->employment_type !== 'permanent')<button type="button" wire:click="openPanel('confirm')" class="btn-secondary">Confirm</button>@endif
                @if ($e->user_id === null)<button type="button" wire:click="openPanel('link')" class="btn-secondary">Link login</button>@endif
            @endif
            @if ($canExit && $e->employment_status === 'active')<button type="button" wire:click="openPanel('notice')" class="btn-danger">Record notice</button>@endif
            @if ($canExit && $e->employment_status === 'notice')<button type="button" wire:click="withdrawNotice" wire:confirm="Withdraw the notice? The person stays." class="btn-secondary">Withdraw notice</button>@endif
            @if ($canExit && $e->employment_status === 'exited')<button type="button" wire:click="openPanel('rejoin')" class="btn-secondary">Re-join</button>@endif
        </div>
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('employee')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    {{-- Action panels --}}
    @if ($panel === 'job')
        <form wire:submit="saveJob" class="card" novalidate>
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Change job</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div><label for="j-dept" class="label">Department</label><select id="j-dept" wire:model="job.department_id" class="input"><option value="">—</option>@foreach ($departments as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('department_id')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="j-desig" class="label">Designation</label><select id="j-desig" wire:model="job.designation_id" class="input"><option value="">—</option>@foreach ($designations as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('designation_id')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="j-branch" class="label">Branch</label><select id="j-branch" wire:model="job.location_id" class="input">@foreach ($branches as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('location_id')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="j-mgr" class="label">Reports to</label><select id="j-mgr" wire:model="job.reports_to_id" class="input"><option value="">— nobody —</option>@foreach ($managers as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('reports_to_id')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="j-type" class="label">Employment type</label><select id="j-type" wire:model="job.employment_type" class="input">@foreach ($types as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>@error('employment_type')<p class="error">{{ $message }}</p>@enderror @error('probation_end_date')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="j-on" class="label">Effective from</label><input id="j-on" type="date" wire:model="job.effective_on" class="input">@error('effective_on')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="sm:col-span-3"><label for="j-rem" class="label">Why (optional)</label><input id="j-rem" type="text" wire:model="job.remarks" class="input" maxlength="500"></div>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><button type="button" wire:click="openPanel('job')" class="btn-secondary">Cancel</button><button type="submit" class="btn-primary">Save change</button></div>
        </form>
    @elseif ($panel === 'confirm')
        <form wire:submit="confirmEmployee" class="card card-body flex flex-wrap items-end gap-3" novalidate>
            <div><label for="c-on" class="label">Confirmed on</label><input id="c-on" type="date" wire:model="confirmOn" class="input">@error('confirmed_on')<p class="error">{{ $message }}</p>@enderror</div>
            <button type="submit" class="btn-primary">Confirm</button><button type="button" wire:click="openPanel('confirm')" class="btn-secondary">Cancel</button>
        </form>
    @elseif ($panel === 'link')
        <form wire:submit="linkLogin" class="card card-body flex flex-wrap items-end gap-3" novalidate>
            <div class="min-w-64 flex-1"><label for="l-user" class="label">Login</label><select id="l-user" wire:model="linkUserId" class="input"><option value="">Select…</option>@foreach ($logins as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('user_id')<p class="error">{{ $message }}</p>@enderror</div>
            <button type="submit" class="btn-primary">Link</button><button type="button" wire:click="openPanel('link')" class="btn-secondary">Cancel</button>
        </form>
    @elseif ($panel === 'notice')
        <form wire:submit="giveNotice" class="card" novalidate>
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Record notice</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div><label for="n-day" class="label">Last working day</label><input id="n-day" type="date" wire:model="notice.exit_date" class="input">@error('exit_date')<p class="error">{{ $message }}</p>@enderror<p class="help">Today means the person leaves now and their login is switched off.</p></div>
                <div class="sm:col-span-2"><label for="n-why" class="label">Reason</label><input id="n-why" type="text" wire:model="notice.exit_reason" class="input" maxlength="500">@error('exit_reason')<p class="error">{{ $message }}</p>@enderror</div>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><button type="button" wire:click="openPanel('notice')" class="btn-secondary">Cancel</button><button type="submit" class="btn-danger">Record notice</button></div>
        </form>
    @elseif ($panel === 'rejoin')
        <form wire:submit="rejoinEmployee" class="card card-body flex flex-wrap items-end gap-3" novalidate>
            <div><label for="r-on" class="label">Came back on</label><input id="r-on" type="date" wire:model="rejoinOn" class="input">@error('rejoin_on')<p class="error">{{ $message }}</p>@enderror</div>
            <button type="submit" class="btn-primary">Re-join</button><button type="button" wire:click="openPanel('rejoin')" class="btn-secondary">Cancel</button>
        </form>
    @endif

    <div class="border-b border-slate-200">
        <nav class="-mb-px flex gap-5" aria-label="Sections">
            @foreach ($tabs as $key => $name)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['border-b-2 px-1 pb-2 text-sm font-medium', 'border-brand-600 text-brand-700' => $current === $key, 'border-transparent text-slate-500 hover:text-slate-700' => $current !== $key])>{{ $name }}</button>
            @endforeach
        </nav>
    </div>

    @if ($current === 'overview')
        <div class="grid gap-5 lg:grid-cols-3">
            <dl class="card card-body grid gap-4 sm:grid-cols-2 lg:col-span-2">
                <div><dt class="text-xs text-slate-500">Joined</dt><dd class="text-sm text-slate-900">{{ $e->joining_date->format('d M Y') }}</dd></div>
                <div><dt class="text-xs text-slate-500">Reports to</dt><dd class="text-sm text-slate-900">@if ($e->manager)<a href="{{ route('hr.employees.show', $e->manager) }}" wire:navigate class="text-brand-700 hover:underline">{{ $e->manager->name }}</a>@else — @endif</dd></div>
                <div><dt class="text-xs text-slate-500">Work e-mail</dt><dd class="text-sm text-slate-900">{{ $e->work_email ?? '—' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Mobile</dt><dd class="text-sm text-slate-900">{{ $e->mobile ?? '—' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Login</dt><dd class="text-sm text-slate-900">{{ $e->user ? $e->user->name.' ('.$e->user->email.')' : 'No login linked' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Confirmed</dt><dd class="text-sm text-slate-900">{{ $e->confirmation_date?->format('d M Y') ?? ($e->employment_type === 'probation' && $e->probation_end_date ? 'Probation ends '.$e->probation_end_date->format('d M Y') : '—') }}</dd></div>
                @if ($sensitive)
                    <div><dt class="text-xs text-slate-500">Date of birth</dt><dd class="text-sm text-slate-900">{{ $e->date_of_birth?->format('d M Y') ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Personal e-mail</dt><dd class="text-sm text-slate-900">{{ $e->personal_email ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Gender · marital status · blood group</dt><dd class="text-sm text-slate-900">{{ collect([$e->gender ? ucfirst($e->gender) : null, $e->marital_status, $e->blood_group])->filter()->implode(' · ') ?: '—' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Address</dt><dd class="text-sm text-slate-900">{{ collect([$e->address, $e->city, $e->state, $e->pin_code])->filter()->implode(', ') ?: '—' }}</dd></div>
                @endif
                @if ($e->employment_status !== 'active' && $e->exit_reason)<div class="sm:col-span-2"><dt class="text-xs text-slate-500">Reason for leaving</dt><dd class="text-sm text-slate-900">{{ $e->exit_reason }}</dd></div>@endif
                @if ($e->remarks)<div class="sm:col-span-2"><dt class="text-xs text-slate-500">Remarks</dt><dd class="text-sm text-slate-900">{{ $e->remarks }}</dd></div>@endif
            </dl>
            <div class="card">
                <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Direct reports ({{ $reports->count() }})</h2></div>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse ($reports as $r)
                        <li class="px-4 py-2" wire:key="rep-{{ $r->id }}"><a href="{{ route('hr.employees.show', $r) }}" wire:navigate class="text-brand-700 hover:underline">{{ $r->name }}</a> <span class="text-xs text-slate-400">{{ $r->code }}</span></li>
                    @empty
                        <li class="px-4 py-6 text-center text-slate-500">Nobody reports to this person.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    @elseif ($current === 'history')
        <div class="card">
            <ul class="divide-y divide-slate-100">
                @foreach ($events as $ev)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-3" wire:key="ev-{{ $ev->id }}">
                        <div>
                            <p class="text-sm font-medium text-slate-900">{{ $ev->label() }}</p>
                            <p class="text-sm text-slate-600">@if ($ev->from_value !== null || $ev->to_value !== null){{ $ev->from_value ?? '—' }} → {{ $ev->to_value ?? '—' }}@endif @if ($ev->remarks)<span class="text-slate-500"> · {{ $ev->remarks }}</span>@endif</p>
                        </div>
                        <p class="text-xs text-slate-500">{{ $ev->effective_on->format('d M Y') }} · recorded by {{ $ev->recorder?->name ?? 'the system' }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    @elseif ($current === 'contacts')
        <div class="card">
            <div class="card-header flex items-center justify-between"><h2 class="text-sm font-semibold text-slate-900">Emergency contacts</h2>@if ($canEdit)<button type="button" wire:click="openPanel('contact')" class="btn-secondary btn-sm">Add contact</button>@endif</div>
            @if ($panel === 'contact')
                <form wire:submit="addContact" class="grid gap-3 border-b border-slate-200 p-5 sm:grid-cols-4" novalidate>
                    <div><label for="k-name" class="label">Name</label><input id="k-name" type="text" wire:model="contact.name" class="input" maxlength="150">@error('name')<p class="error">{{ $message }}</p>@enderror</div>
                    <div><label for="k-rel" class="label">Relationship</label><input id="k-rel" type="text" wire:model="contact.relationship" class="input" maxlength="50">@error('relationship')<p class="error">{{ $message }}</p>@enderror</div>
                    <div><label for="k-mob" class="label">Mobile</label><input id="k-mob" type="text" inputmode="numeric" wire:model="contact.mobile" class="input" maxlength="10">@error('mobile')<p class="error">{{ $message }}</p>@enderror</div>
                    <div class="flex items-end gap-3"><label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="contact.is_primary" value="1"> First to call</label><button type="submit" class="btn-primary">Add</button></div>
                </form>
            @endif
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($contacts as $c)
                    <li class="flex items-center justify-between px-5 py-3" wire:key="ct-{{ $c->id }}">
                        <span><span class="font-medium text-slate-900">{{ $c->name }}</span> <span class="text-slate-500">· {{ $c->relationship }} · {{ $c->mobile }}</span> @if ($c->is_primary)<span class="badge bg-brand-50 text-brand-700">First to call</span>@endif</span>
                        @if ($canEdit)<button type="button" wire:click="removeContact({{ $c->id }})" wire:confirm="Remove this contact?" class="text-xs text-rose-600 hover:underline">Remove</button>@endif
                    </li>
                @empty
                    <li class="px-5 py-8 text-center text-slate-500">No emergency contact on file.</li>
                @endforelse
            </ul>
        </div>
    @elseif ($current === 'papers')
        <div class="card">
            <div class="card-header flex items-center justify-between"><h2 class="text-sm font-semibold text-slate-900">Papers on file</h2>@if ($canPapers)<button type="button" wire:click="openPanel('paper')" class="btn-secondary btn-sm">Add a paper</button>@endif</div>
            @if ($panel === 'paper')
                <form wire:submit="savePaper" class="grid gap-3 border-b border-slate-200 p-5 sm:grid-cols-4" novalidate>
                    <div><label for="p-type" class="label">Kind</label><select id="p-type" wire:model="paper.doc_type" class="input">@foreach ($docTypes as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>@error('doc_type')<p class="error">{{ $message }}</p>@enderror</div>
                    <div><label for="p-title" class="label">Title</label><input id="p-title" type="text" wire:model="paper.title" class="input" maxlength="150">@error('title')<p class="error">{{ $message }}</p>@enderror</div>
                    <div><label for="p-exp" class="label">Expires on (optional)</label><input id="p-exp" type="date" wire:model="paper.expires_on" class="input">@error('expires_on')<p class="error">{{ $message }}</p>@enderror</div>
                    <div><label for="p-file" class="label">File (PDF, image, Word; up to 5 MB)</label><input id="p-file" type="file" wire:model="file" class="input">@error('file')<p class="error">{{ $message }}</p>@enderror</div>
                    <div class="sm:col-span-4"><button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="file,savePaper">Upload</button></div>
                </form>
            @endif
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse ($papers as $p)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3" wire:key="pp-{{ $p->id }}">
                        <span>
                            <a href="{{ route('hr.documents.download', $p) }}" class="font-medium text-brand-700 hover:underline">{{ $p->title }}</a>
                            <span class="text-slate-500">· {{ $docTypes[$p->doc_type] ?? $p->doc_type }} · {{ number_format($p->size / 1024) }} KB</span>
                            @if ($p->expires_on)<span @class(['badge', 'bg-rose-100 text-rose-800' => $p->expires_on->isPast(), 'bg-slate-100 text-slate-600' => ! $p->expires_on->isPast()])>{{ $p->expires_on->isPast() ? 'Expired' : 'Expires' }} {{ $p->expires_on->format('d M Y') }}</span>@endif
                        </span>
                        @if ($canPapers)<button type="button" wire:click="removePaper({{ $p->id }})" wire:confirm="Remove this paper from the file?" class="text-xs text-rose-600 hover:underline">Remove</button>@endif
                    </li>
                @empty
                    <li class="px-5 py-8 text-center text-slate-500">No papers on file.</li>
                @endforelse
            </ul>
        </div>
    @endif
</div>

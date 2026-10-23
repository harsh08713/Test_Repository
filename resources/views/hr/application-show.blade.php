@php
    $hasHireRec = $interviews->where('state', 'done')->where('recommendation', 'hire')->isNotEmpty();
    $stageBadge = ['hired' => 'bg-emerald-100 text-emerald-800', 'rejected' => 'bg-rose-100 text-rose-800', 'withdrawn' => 'bg-slate-100 text-slate-600', 'interview' => 'bg-sky-100 text-sky-800', 'offer' => 'bg-sky-100 text-sky-800', 'applied' => 'bg-amber-100 text-amber-800', 'screening' => 'bg-amber-100 text-amber-800'];
@endphp
<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-slate-500"><a href="{{ route('hr.openings.show', $a->job_opening_id) }}" wire:navigate class="hover:text-brand-700">{{ $a->opening?->title }}</a></p>
            <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $a->candidate?->name }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ collect([$a->candidate?->mobile, $a->candidate?->email, $a->candidate?->current_employer])->filter()->implode(' · ') }}</p>
            <p class="mt-2"><span class="badge {{ $stageBadge[$a->stage] ?? '' }}">{{ $stages[$a->stage] }}</span>@if ($a->reject_reason)<span class="ml-2 text-xs text-slate-500">{{ $a->reject_reason }}</span>@endif
                @if ($employee)<a href="{{ route('hr.employees.show', $employee) }}" wire:navigate class="ml-2 text-sm text-brand-700 hover:underline">Employee file {{ $employee->code }}</a>@endif</p>
        </div>
        @if ($canEdit)
            <div class="flex flex-wrap gap-2">
                @if (in_array('screening', $moves, true))<button type="button" wire:click="advanceTo('screening')" class="btn-secondary">Start screening</button>@endif
                @if (in_array($a->stage, ['applied', 'screening', 'interview'], true))<button type="button" wire:click="openPanel('interview')" class="btn-secondary">Book an interview</button>@endif
                @if ($a->stage === 'interview' && ! $offer)<button type="button" wire:click="openPanel('offer')" @disabled(! $hasHireRec) title="{{ $hasHireRec ? '' : 'An interviewer must recommend hiring first' }}" class="btn-primary">Make an offer</button>@endif
                @if ($a->stage === 'offer' && $offer?->state === 'sent')<button type="button" wire:click="openPanel('answer')" class="btn-primary">Record the answer</button>@endif
                @if ($a->stage === 'offer' && $offer?->state === 'accepted' && $canHire)<button type="button" wire:click="hireIt" wire:confirm="Make the employee file for this person?" class="btn-primary">Hire</button>@endif
                @if (in_array('rejected', $moves, true))<button type="button" wire:click="startReason('rejected')" class="btn-danger">Reject</button>@endif
                @if (in_array('withdrawn', $moves, true))<button type="button" wire:click="startReason('withdrawn')" class="btn-secondary">Mark withdrawn</button>@endif
            </div>
        @endif
    </div>

    <x-status />
    @error('action')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('stage')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror
    @error('state')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

    @if ($panel === 'reason')
        <form wire:submit="confirmReason" class="card card-body flex flex-wrap items-end gap-3" novalidate>
            <div class="min-w-64 flex-1"><label for="ar-why" class="label">Why is it {{ $reasonTo }}?</label><input id="ar-why" type="text" wire:model="note" class="input" maxlength="300">@error('note')<p class="error">{{ $message }}</p>@enderror</div>
            <button type="submit" class="btn-danger">{{ $reasonTo === 'rejected' ? 'Reject' : 'Mark as withdrawn' }}</button>
        </form>
    @elseif ($panel === 'interview')
        <form wire:submit="scheduleIt" class="card" novalidate>
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Book an interview</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div><label for="iv-at" class="label">Date and time</label><input id="iv-at" type="datetime-local" wire:model="slot.scheduled_at" class="input">@error('scheduled_at')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="iv-mode" class="label">How</label><select id="iv-mode" wire:model="slot.mode" class="input">@foreach ($modes as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>@error('mode')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="iv-who" class="label">Interviewer</label><select id="iv-who" wire:model="slot.interviewer_id" class="input"><option value="">Select…</option>@foreach ($people as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('interviewer_id')<p class="error">{{ $message }}</p>@enderror</div>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><button type="submit" class="btn-primary">Book it</button></div>
        </form>
    @elseif ($panel === 'offer')
        <form wire:submit="makeTheOffer" class="card" novalidate>
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Make an offer</h2><p class="text-xs text-slate-500">Money is not part of it here: salary is set in payroll.</p></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div><label for="of-desig" class="label">Designation</label><select id="of-desig" wire:model="offerForm.designation_id" class="input"><option value="">The opening’s designation</option>@foreach ($designations as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@error('designation_id')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="of-join" class="label">Joining date</label><input id="of-join" type="date" wire:model="offerForm.joining_date" class="input">@error('joining_date')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="of-note" class="label">Note</label><input id="of-note" type="text" wire:model="offerForm.note" class="input" maxlength="300"></div>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-3"><button type="submit" class="btn-primary">Send the offer</button></div>
        </form>
    @elseif ($panel === 'answer')
        <form wire:submit="answerOffer" class="card card-body flex flex-wrap items-end gap-3" novalidate>
            <div><label for="an-what" class="label">The candidate</label><select id="an-what" wire:model="answer.answer" class="input"><option value="accepted">Accepted</option><option value="declined">Declined</option></select></div>
            <div><label for="an-on" class="label">On</label><input id="an-on" type="date" wire:model="answer.on" class="input">@error('answer')<p class="error">{{ $message }}</p>@enderror</div>
            <button type="submit" class="btn-primary">Record</button>
        </form>
    @endif

    @if ($offer)
        <div class="card card-body text-sm">
            <p><span class="font-medium text-slate-900">Offer</span> · {{ ['draft' => 'Draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined', 'withdrawn' => 'Withdrawn'][$offer->state] }} · joining {{ $offer->joining_date->format('d M Y') }}{{ $offer->responded_on ? ' · answered '.$offer->responded_on->format('d M Y') : '' }}{{ $offer->note ? ' · '.$offer->note : '' }}</p>
        </div>
    @endif

    <div class="card">
        <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Interviews</h2></div>
        <ul class="divide-y divide-slate-100 text-sm">
            @forelse ($interviews as $i)
                <li class="px-5 py-3" wire:key="iv-{{ $i->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span><span class="font-medium text-slate-900">Round {{ $i->round }}</span> · {{ $i->scheduled_at->format('d M Y, H:i') }} · {{ $modes[$i->mode] }} · {{ $i->interviewer?->name }}</span>
                        <span class="flex items-center gap-3">
                            <span @class(['badge', 'bg-amber-100 text-amber-800' => $i->state === 'scheduled', 'bg-emerald-100 text-emerald-800' => $i->state === 'done', 'bg-slate-100 text-slate-600' => in_array($i->state, ['cancelled', 'no_show'], true)])>{{ ['scheduled' => 'Planned', 'done' => 'Done', 'cancelled' => 'Cancelled', 'no_show' => 'No-show'][$i->state] }}</span>
                            @if ($canEdit && $i->state === 'scheduled')
                                <button type="button" wire:click="closeInterviewAs({{ $i->id }}, 'cancelled')" class="text-xs text-rose-600 hover:underline">Cancel</button>
                                <button type="button" wire:click="closeInterviewAs({{ $i->id }}, 'no_show')" class="text-xs text-slate-500 hover:underline">No-show</button>
                            @endif
                        </span>
                    </div>
                    @if ($i->state === 'done')<p class="mt-1 text-slate-600">Rated {{ $i->rating }} of 5 · recommends: <span class="font-medium">{{ $i->recommendation }}</span> — {{ $i->feedback }}</p>@endif
                </li>
            @empty
                <li class="px-5 py-6 text-center text-slate-500">No interview yet.</li>
            @endforelse
        </ul>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">History</h2></div>
        <ul class="divide-y divide-slate-100 text-sm">
            @foreach ($events as $e)
                <li class="flex flex-wrap justify-between gap-2 px-5 py-2.5" wire:key="ev-{{ $e->id }}">
                    <span>{{ $e->from_stage ? $stages[$e->from_stage].' → ' : '' }}<span class="font-medium text-slate-900">{{ $stages[$e->to_stage] }}</span>{{ $e->note ? ' · '.$e->note : '' }}</span>
                    <span class="text-xs text-slate-500">{{ $e->created_at->format('d M Y H:i') }} · {{ $e->recorder?->name ?? 'the system' }}</span>
                </li>
            @endforeach
        </ul>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2 class="text-sm font-semibold text-slate-900">Approval</h2>
            <p class="mt-0.5 text-xs text-slate-500">{{ $document->approvalTitle() }}</p>
        </div>
        <span class="badge {{ $document->approval_status->badge() }}">{{ $document->approval_status->label() }}</span>
    </div>

    <div class="card-body space-y-4">
        @error('approval')
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>
        @enderror

        @if ($changed)
            <div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="alert">
                This document was changed after it was submitted, so it cannot be approved as it stands.
                @if ($canDecide) Reject it so the owner can review and resubmit. @endif
            </div>
        @endif

        @if ($canSubmit)
            <div class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-4 py-3">
                <p class="text-sm text-slate-600">
                    {{ $document->approval_status === \App\Modules\Shared\Enums\ApprovalStatus::Rejected ? 'This was rejected. Fix the issues and send it again.' : 'This is still a draft.' }}
                </p>
                <button type="button" wire:click="submit" wire:confirm="Send this for approval? You will not be able to edit it while it is pending." class="btn-primary shrink-0" wire:loading.attr="disabled">Submit for approval</button>
            </div>
        @endif

        @if ($canDecide)
            <div class="space-y-3 rounded-lg border border-amber-200 bg-amber-50/60 p-4">
                <p class="text-sm font-medium text-amber-900">Your decision is needed.</p>
                <div>
                    <label for="decision-comment" class="label">Comment <span class="font-normal text-slate-500">(required to reject)</span></label>
                    <textarea id="decision-comment" wire:model="comment" rows="2" class="input" maxlength="1000"></textarea>
                    @error('comment') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="flex gap-2">
                    <button type="button" wire:click="approve" class="btn-primary" wire:loading.attr="disabled">Approve</button>
                    <button type="button" wire:click="reject" class="btn-danger" wire:loading.attr="disabled">Reject</button>
                </div>
            </div>
        @endif

        <div>
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">History</h3>
            @forelse ($history as $cycle => $levels)
                <div class="mb-3 last:mb-0">
                    <p class="mb-1.5 text-xs font-medium text-slate-500">
                        Submission {{ $cycle }} · by {{ $levels->first()->submitter?->name ?? 'unknown' }} · {{ \App\Modules\Shared\Support\Format::dateTime($levels->first()->created_at) }}
                    </p>
                    <ol class="space-y-1.5">
                        @foreach ($levels as $approval)
                            <li class="flex items-start gap-3 rounded-lg border border-slate-100 px-3 py-2 text-sm">
                                <span class="badge {{ $approval->status->badge() }}">{{ $approval->status->label() }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-slate-700">
                                        Level {{ $approval->level }}@if ($approval->level_name) · {{ $approval->level_name }}@endif
                                        @if ($approval->actor) — {{ $approval->actor->name }}, {{ \App\Modules\Shared\Support\Format::dateTime($approval->acted_at) }}@endif
                                    </p>
                                    @if ($approval->comment) <p class="mt-0.5 text-slate-500">“{{ $approval->comment }}”</p> @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @empty
                <p class="text-sm text-slate-500">Not submitted yet.</p>
            @endforelse
        </div>
    </div>
</div>

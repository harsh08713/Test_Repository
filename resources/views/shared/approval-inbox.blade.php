<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Pending approvals</h1>
        <p class="mt-1 text-sm text-slate-500">Documents waiting for you to approve or reject. Rejecting needs a comment so the author knows what to fix.</p>
    </div>

    @error('inbox')
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>
    @enderror

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Document</th><th>Type</th><th>Level</th><th>Submitted by</th><th>Submitted</th><th class="text-right">Action</th></tr></thead>
                <tbody>
                    @forelse ($this->items as $approval)
                        <tr wire:key="approval-{{ $approval->id }}">
                            <td class="font-medium text-slate-900">
                                @if ($url = $approval->approvable->approvalUrl())
                                    <a href="{{ $url }}" class="text-brand-600 hover:underline">{{ $approval->approvable->approvalTitle() }}</a>
                                @else
                                    {{ $approval->approvable->approvalTitle() }}
                                @endif
                            </td>
                            <td>{{ $approval->document_type->label() }}</td>
                            <td>{{ $approval->level }}@if ($approval->level_name) · {{ $approval->level_name }}@endif</td>
                            <td>{{ $approval->submitter?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap">{{ \App\Modules\Shared\Support\Format::dateTime($approval->created_at) }}</td>
                            <td class="text-right">
                                @if ($deciding === $approval->id)
                                    <div class="ml-auto w-64 space-y-2 text-left">
                                        <textarea wire:model="comment" rows="2" class="input" placeholder="Reason for rejecting…" aria-label="Rejection reason"></textarea>
                                        @error('comment') <p class="error">{{ $message }}</p> @enderror
                                        <div class="flex justify-end gap-2">
                                            <button type="button" wire:click="$set('deciding', null)" class="btn-ghost btn-sm">Cancel</button>
                                            <button type="button" wire:click="reject" class="btn-danger btn-sm">Confirm reject</button>
                                        </div>
                                    </div>
                                @else
                                    <div class="flex justify-end gap-2">
                                        <button type="button" wire:click="approve({{ $approval->id }})" class="btn-primary btn-sm">Approve</button>
                                        <button type="button" wire:click="startReject({{ $approval->id }})" class="btn-secondary btn-sm">Reject</button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-12 text-center text-slate-500">Nothing is waiting for you.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

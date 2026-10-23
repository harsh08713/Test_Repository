<div class="card">
    <div class="card-header">
        <h2 class="text-sm font-semibold text-slate-900">Comments</h2>
        <span class="text-xs text-slate-500">{{ $comments->count() }}</span>
    </div>

    <div class="card-body space-y-4">
        @if ($canComment)
            <form wire:submit="add" class="space-y-2">
                <label for="comment-body" class="sr-only">Add a comment</label>
                <textarea id="comment-body" wire:model="body" rows="2" class="input" placeholder="Add a comment…" maxlength="2000"></textarea>
                @error('body') <p class="error">{{ $message }}</p> @enderror
                <div class="flex justify-end">
                    <button type="submit" class="btn-primary btn-sm" wire:loading.attr="disabled">Post comment</button>
                </div>
            </form>
        @else
            <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">You can read the comments but do not have permission to add new ones.</p>
        @endif

        <ul class="space-y-3">
            @forelse ($comments as $comment)
                <li class="flex gap-3" wire:key="comment-{{ $comment->id }}">
                    <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">
                        {{ \Illuminate\Support\Str::substr($comment->user?->name ?? '?', 0, 1) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-slate-500">
                            <span class="font-medium text-slate-700">{{ $comment->user?->name ?? 'Former user' }}</span>
                            · {{ \App\Modules\Shared\Support\Format::dateTime($comment->created_at) }}
                            @if ($comment->user_id === auth()->id() || $canModerate)
                                · <button type="button" wire:click="delete({{ $comment->id }})" wire:confirm="Delete this comment?" class="text-rose-600 hover:underline">Delete</button>
                            @endif
                        </p>
                        <p class="mt-0.5 whitespace-pre-line break-words text-sm text-slate-800">{{ $comment->body }}</p>
                    </div>
                </li>
            @empty
                <li class="py-4 text-center text-sm text-slate-500">No comments yet.</li>
            @endforelse
        </ul>
    </div>
</div>

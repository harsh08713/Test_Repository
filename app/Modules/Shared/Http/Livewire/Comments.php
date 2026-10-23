<?php

namespace App\Modules\Shared\Http\Livewire;

use App\Modules\Shared\Contracts\CommentableRecord;
use App\Modules\Shared\Enums\CommentVisibility;
use App\Modules\Shared\Models\Comment;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Comment thread for any record that uses HasComments:
 *
 *     <livewire:shared.comments type="item" :id="$item->id" />
 *
 * Reading needs `view` on the record; posting needs the policy's `comment` ability, which is
 * granted by `{form}.comment` or `{form}.edit` — so view-only users (Operations on Item Master)
 * can annotate a record they cannot change.
 */
class Comments extends Component
{
    #[Locked]
    public string $type = '';

    #[Locked]
    public int $id = 0;

    public string $body = '';

    public function mount(string $type, int $id): void
    {
        $this->type = $type;
        $this->id = $id;

        $this->record();
    }

    #[Computed]
    public function record(): CommentableRecord&Model
    {
        $class = Relation::getMorphedModel($this->type);

        abort_unless($class !== null && is_subclass_of($class, CommentableRecord::class), 404);

        $record = $class::query()->findOrFail($this->id);

        Gate::authorize('view', $record);

        return $record;
    }

    public function add(): void
    {
        $record = $this->record();
        Gate::authorize('comment', $record);

        $this->validate(['body' => ['required', 'string', 'max:2000']]);

        $comment = new Comment(['user_id' => Auth::id(), 'body' => trim($this->body)]);
        $comment->forceFill([
            'visibility' => CommentVisibility::Internal->value,   // the staff UI only writes internal notes
            'company_id' => $record->getAttribute('company_id'),
            'commentable_type' => $record->getMorphClass(),
            'commentable_id' => $record->getKey(),
        ])->save();

        $this->reset('body');
    }

    public function delete(int $commentId): void
    {
        $comment = Comment::query()->whereMorphedTo('commentable', $this->record())->findOrFail($commentId);

        // Authors can retract their own comment; deleting anyone else's is the explicit `comment.moderate` right.
        abort_unless($comment->user_id === Auth::id() || Auth::user()->can('comment.moderate'), 403);

        $comment->delete();
    }

    public function render(): View
    {
        $record = $this->record();

        return view('shared.comments', [
            'comments' => Comment::query()->whereMorphedTo('commentable', $record)->with('user:id,name')->latest()->latest('id')->limit(100)->get(),
            'canComment' => Gate::allows('comment', $record),
            'canModerate' => Auth::user()->can('comment.moderate'),
        ]);
    }
}

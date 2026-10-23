<?php

namespace App\Modules\Shared\Http\Livewire;

use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Drop-in approval widget for any ApprovableDocument's detail screen:
 *
 *     <livewire:shared.approval-panel type="quotation" :id="$quotation->id" />
 *
 * `type` is the model's morph alias, never a class name, and both props are locked
 * so they cannot be tampered with from the browser.
 */
class ApprovalPanel extends Component
{
    #[Locked]
    public string $type = '';

    #[Locked]
    public int $id = 0;

    public string $comment = '';

    public function mount(string $type, int $id): void
    {
        $this->type = $type;
        $this->id = $id;

        $this->document(); // fail fast (404 / 403) instead of on first render
    }

    #[Computed]
    public function document(): ApprovableDocument&Model
    {
        $class = Relation::getMorphedModel($this->type);

        abort_unless($class !== null && is_subclass_of($class, ApprovableDocument::class), 404);

        $document = $class::query()->findOrFail($this->id);

        Gate::authorize('view', $document);

        return $document;
    }

    public function submit(ApprovalService $approvals): void
    {
        $document = $this->document();
        Gate::authorize('submit', $document);

        $this->run(fn () => $approvals->submit($document, Auth::user()));
    }

    public function approve(ApprovalService $approvals): void
    {
        $document = $this->document();
        Gate::authorize('approve', $document);

        $this->run(fn () => $approvals->approve($document, Auth::user(), $this->comment ?: null));
    }

    public function reject(ApprovalService $approvals): void
    {
        $this->validate(
            ['comment' => ['required', 'string', 'min:3', 'max:1000']],
            ['comment.required' => 'Please explain why you are rejecting this.'],
        );

        $document = $this->document();
        Gate::authorize('approve', $document);

        $this->run(fn () => $approvals->reject($document, Auth::user(), $this->comment));
    }

    private function run(callable $action): void
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (ApprovalStateException|AuthorizationException $e) {
            $this->addError('approval', $e->getMessage());

            return;
        }

        $this->reset('comment');
        unset($this->document);
        $this->dispatch('approval-changed');
    }

    public function render(ApprovalService $approvals): View
    {
        $document = $this->document();
        $user = Auth::user();

        return view('shared.approval-panel', [
            'document' => $document,
            'history' => $approvals->history($document)->groupBy('cycle')->sortKeysDesc(),
            'canSubmit' => $document->isEditableByWorkflow() && Gate::allows('submit', $document),
            'changed' => $document->isPendingApproval() && $approvals->changedSinceSubmission($document),
            'canDecide' => $document->isPendingApproval()
                && Gate::allows('approve', $document)
                && $approvals->canAct($user, $document),
        ]);
    }
}

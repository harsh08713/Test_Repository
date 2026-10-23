<?php

namespace App\Modules\Shared\Http\Livewire;

use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Approval;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Everything currently waiting for the signed-in user to approve or reject.
 *
 * @property-read Collection<int, Approval> $items
 */
#[Title('Pending approvals')]
class ApprovalInbox extends Component
{
    public ?int $deciding = null;      // approval row being rejected (comment box open)

    public string $comment = '';

    /** @return Collection<int, Approval> */
    #[Computed]
    public function items(): Collection
    {
        return app(ApprovalService::class)->pendingFor(Auth::user());
    }

    public function approve(int $approvalId, ApprovalService $service): void
    {
        $this->decide($approvalId, fn ($document) => $service->approve($document, Auth::user()));
    }

    public function startReject(int $approvalId): void
    {
        $this->deciding = $approvalId;
        $this->comment = '';
    }

    public function reject(ApprovalService $service): void
    {
        $this->validate(['comment' => ['required', 'string', 'min:3', 'max:1000']]);

        $this->decide((int) $this->deciding, fn ($document) => $service->reject($document, Auth::user(), $this->comment));
    }

    private function decide(int $approvalId, callable $action): void
    {
        $approval = $this->items->firstWhere('id', $approvalId);

        if ($approval === null) {
            $this->addError('inbox', 'That item is no longer waiting for you.');
            unset($this->items);

            return;
        }

        try {
            $action($approval->approvable);
        } catch (ApprovalStateException|AuthorizationException $e) {
            $this->addError('inbox', $e->getMessage());
        }

        $this->deciding = null;
        $this->comment = '';
        unset($this->items);
    }

    public function render(): View
    {
        return view('shared.approval-inbox');
    }
}

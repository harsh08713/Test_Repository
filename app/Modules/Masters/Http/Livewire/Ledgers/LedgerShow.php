<?php

namespace App\Modules\Masters\Http\Livewire\Ledgers;

use App\Models\User;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A ledger at a glance: its details, addresses, contacts and (masked) bank account, with the approval panel,
 * comments and history. Everything it shows or does is authorised against the ledger through its policy.
 */
#[Title('Ledger')]
class LedgerShow extends Component
{
    #[Locked]
    public int $ledgerId = 0;

    public bool $reopenOpen = false;

    public string $reason = '';

    public function mount(Ledger $ledger): void
    {
        Gate::authorize('view', $ledger);

        $this->ledgerId = $ledger->id;
    }

    #[Computed]
    public function ledger(): Ledger
    {
        $ledger = Ledger::query()->with(['accountGroup', 'addresses.state', 'addresses.district', 'contacts', 'bankDetail'])->findOrFail($this->ledgerId);
        Gate::authorize('view', $ledger);

        return $ledger;
    }

    #[On('approval-changed')]
    public function refreshAfterApproval(): void
    {
        unset($this->ledger);
    }

    public function toggleStatus(): void
    {
        $ledger = $this->ledger();
        Gate::authorize('changeStatus', $ledger);

        $ledger->forceFill(['status' => $ledger->status === 'active' ? 'inactive' : 'active'])->save();
        unset($this->ledger);
    }

    public function openReopen(): void
    {
        Gate::authorize('reopen', $this->ledger());

        $this->reason = '';
        $this->resetErrorBag();
        $this->reopenOpen = true;
    }

    public function cancelReopen(): void
    {
        $this->reopenOpen = false;
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function reopen(ApprovalService $approvals): void
    {
        $ledger = $this->ledger();
        Gate::authorize('reopen', $ledger);

        $this->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:1000']],
            ['reason.required' => 'Please say why you are re-opening this ledger.', 'reason.min' => 'Please say why you are re-opening this ledger.'],
        );

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            $approvals->reopen($ledger, $user, $this->reason);
        } catch (ApprovalStateException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }

        session()->flash('status', 'The ledger is open for editing again. It cannot be used in transactions until it is approved again.');
        $this->redirectRoute('masters.ledgers.edit', $ledger, navigate: false);
    }

    public function delete(): void
    {
        $ledger = $this->ledger();
        Gate::authorize('delete', $ledger);

        if ($this->deleteBlocker($ledger) !== null) {
            $this->addError('delete', (string) $this->deleteBlocker($ledger));

            return;
        }

        $ledger->delete();
        session()->flash('status', 'Ledger deleted.');
        $this->redirectRoute('masters.ledgers.index', navigate: false);
    }

    /** Why this ledger must be deactivated rather than deleted, or null when deleting is fine. */
    private function deleteBlocker(Ledger $ledger): ?string
    {
        // Once approved a ledger may have been used; later phases add the transactions that refer to it.
        if ($ledger->approvals()->where('status', 'approved')->exists()) {
            return 'This ledger has been approved before, so it cannot be deleted. Deactivate it instead.';
        }

        return null;
    }

    public function render(): View
    {
        $ledger = $this->ledger();
        $canAudit = Gate::allows('viewAudit', $ledger);

        return view('masters.ledgers.show', [
            'ledger' => $ledger,
            'canEdit' => Gate::allows('update', $ledger),
            'canChangeStatus' => Gate::allows('changeStatus', $ledger),
            'canReopen' => Gate::allows('reopen', $ledger),
            'canDelete' => Gate::allows('delete', $ledger) && $this->deleteBlocker($ledger) === null,
            'canSeeSensitive' => Gate::allows('viewSensitive', $ledger),
            'missing' => $ledger->isEditableByWorkflow() ? $ledger->missingForApproval() : [],
            'history' => $canAudit
                ? ActivityLog::query()->with('causer')->where('subject_type', $ledger->getMorphClass())->where('subject_id', $ledger->id)->latest('id')->limit(30)->get()
                : collect(),
        ]);
    }
}

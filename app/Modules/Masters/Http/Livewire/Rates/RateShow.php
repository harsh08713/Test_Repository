<?php

namespace App\Modules\Masters\Http\Livewire\Rates;

use App\Models\User;
use App\Modules\Masters\Models\Rate;
use App\Modules\Masters\Services\RateMaintenance;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A rate at a glance, with the other rates for the same item, branch, unit and price list (so a change of price can
 * be planned without overlaps), the approval panel, comments and history. Every action authorises against the rate
 * through RatePolicy; the rules live in RateMaintenance and ApprovalService.
 */
#[Title('Rate')]
class RateShow extends Component
{
    #[Locked]
    public int $rateId = 0;

    /** Which dialog is open: '', 'reopen' or 'end'. */
    public string $dialog = '';

    public string $reason = '';

    public string $endOn = '';

    public function mount(Rate $rate): void
    {
        Gate::authorize('view', $rate);

        $this->rateId = $rate->id;
    }

    #[Computed]
    public function rateModel(): Rate
    {
        $rate = Rate::query()->with(['item', 'location', 'unit', 'priceList'])->findOrFail($this->rateId);
        Gate::authorize('view', $rate);

        return $rate;
    }

    #[On('approval-changed')]
    public function refreshAfterApproval(): void
    {
        unset($this->rateModel);
    }

    public function toggleStatus(): void
    {
        $rate = $this->rateModel();
        Gate::authorize('changeStatus', $rate);

        $rate->forceFill(['status' => $rate->status === 'active' ? 'inactive' : 'active'])->save();
        unset($this->rateModel);
    }

    public function openDialog(string $which): void
    {
        $rate = $this->rateModel();

        match ($which) {
            'reopen' => Gate::authorize('reopen', $rate),
            'end' => Gate::authorize('endDate', $rate),
            default => abort(404),
        };

        $this->dialog = $which;
        $this->reason = '';
        $this->endOn = '';
        $this->resetErrorBag();
    }

    public function closeDialog(): void
    {
        $this->dialog = '';
        $this->reason = '';
        $this->endOn = '';
        $this->resetErrorBag();
    }

    public function reopen(ApprovalService $approvals): void
    {
        $rate = $this->rateModel();
        Gate::authorize('reopen', $rate);

        $this->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:1000']],
            ['reason.required' => 'Please say why you are re-opening this rate.', 'reason.min' => 'Please say why you are re-opening this rate.'],
        );

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            $approvals->reopen($rate, $user, $this->reason);
        } catch (ApprovalStateException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }

        session()->flash('status', 'The rate is open for editing again. It will not be offered to quotations or orders until it is approved again.');
        $this->redirectRoute('masters.rates.edit', $rate, navigate: false);
    }

    public function endEarly(RateMaintenance $maintenance): void
    {
        $rate = $this->rateModel();
        Gate::authorize('endDate', $rate);

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            $maintenance->endDate($rate, $this->endOn, $this->reason, $user);
        } catch (ValidationException $e) {
            // The service names its fields "on" and "reason"; so do this dialog's.
            $this->setErrorBag($e->validator->errors());

            return;
        } catch (ApprovalStateException $e) {
            $this->addError('endOn', $e->getMessage());

            return;
        }

        unset($this->rateModel);
        $this->closeDialog();
        session()->flash('status', 'The rate now ends on the date you chose.');
        $this->redirectRoute('masters.rates.show', $this->rateId, navigate: false);
    }

    public function delete(): void
    {
        $rate = $this->rateModel();
        Gate::authorize('delete', $rate);

        if ($this->deleteBlocker($rate) !== null) {
            $this->addError('delete', (string) $this->deleteBlocker($rate));

            return;
        }

        $rate->delete();
        session()->flash('status', 'Rate deleted.');
        $this->redirectRoute('masters.rates.index', navigate: false);
    }

    /** Why this rate must be switched off rather than deleted, or null when deleting is fine. */
    private function deleteBlocker(Rate $rate): ?string
    {
        if ($rate->approvals()->where('status', 'approved')->exists()) {
            return 'This rate has been approved before, so it cannot be deleted. Deactivate it instead.';
        }

        return null;
    }

    public function render(): View
    {
        $rate = $this->rateModel();
        $today = now()->toDateString();
        $canAudit = Gate::allows('viewAudit', $rate);

        return view('masters.rates.show', [
            'rate' => $rate,
            'today' => $today,
            'canEdit' => Gate::allows('update', $rate),
            'canChangeStatus' => Gate::allows('changeStatus', $rate),
            'canReopen' => Gate::allows('reopen', $rate),
            'canEnd' => Gate::allows('endDate', $rate),
            'canCopy' => Gate::allows('create', Rate::class),
            'canDelete' => Gate::allows('delete', $rate) && $this->deleteBlocker($rate) === null,
            'missing' => $rate->isEditableByWorkflow() ? $rate->missingForApproval() : [],
            'siblings' => Rate::query()->with('priceList:id,name')->inSlot($rate->item_id, $rate->location_id, $rate->unit_id, $rate->price_list_id)
                ->orderBy('effective_from')->limit(25)->get(),
            'history' => $canAudit
                ? ActivityLog::query()->with('causer')->where('subject_type', $rate->getMorphClass())->where('subject_id', $rate->id)->latest('id')->limit(30)->get()
                : collect(),
        ]);
    }
}

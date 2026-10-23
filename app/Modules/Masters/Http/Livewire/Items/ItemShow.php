<?php

namespace App\Modules\Masters\Http\Livewire\Items;

use App\Models\User;
use App\Modules\Finance\Models\ItemCostEntry;
use App\Modules\Masters\Enums\ItemStage;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Services\ItemWorkflow;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\ApprovalLevel;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * An item at a glance, and the one place its workflow is driven from: send to Inventory, return to Purchase,
 * submit, approve, reject (naming the stage it goes back to), re-open, switch on/off. Every action authorises
 * against the item through ItemPolicy and then lets ItemWorkflow check the item's state.
 */
#[Title('Item')]
class ItemShow extends Component
{
    #[Locked]
    public int $itemId = 0;

    /** Which dialog is open: '', 'return', 'approve', 'reject' or 'reopen'. */
    public string $dialog = '';

    public string $comment = '';

    /** Where a rejection or re-opening sends the item. */
    public string $returnTo = 'inventory_pending';

    public function mount(Item $item): void
    {
        Gate::authorize('view', $item);

        $this->itemId = $item->id;
    }

    #[Computed]
    public function item(): Item
    {
        $item = Item::query()->with(['itemGroup', 'brand', 'industryTemplate', 'baseUnit', 'uoms.unit', 'aliases', 'locationParameters.location'])->findOrFail($this->itemId);
        Gate::authorize('view', $item);

        return $item;
    }

    // ---- stage moves ------------------------------------------------------------------------------------------

    public function sendToInventory(ItemWorkflow $workflow): void
    {
        $item = $this->item();
        Gate::authorize('sendToInventory', $item);

        $this->run(fn (User $user) => $workflow->sendToInventory($item, $user), 'The item is now with Inventory.');
    }

    public function submitForApproval(ItemWorkflow $workflow): void
    {
        $item = $this->item();
        Gate::authorize('submit', $item);

        $this->run(fn (User $user) => $workflow->submit($item, $user), 'Submitted for approval.');
    }

    // ---- dialogs ----------------------------------------------------------------------------------------------

    public function openDialog(string $which): void
    {
        $item = $this->item();

        match ($which) {
            'return' => Gate::authorize('returnToPurchase', $item),
            'approve', 'reject' => Gate::authorize('approve', $item),
            'reopen' => Gate::authorize('reopen', $item),
            default => abort(404),
        };

        $this->dialog = $which;
        $this->comment = '';
        // Default to Inventory (the lighter correction); someone who runs only Purchase can only go back there.
        $this->returnTo = $which === 'reopen' && ! Gate::allows('reopen', [$item, ItemStage::InventoryPending]) ? 'purchase_draft' : 'inventory_pending';
        $this->resetErrorBag();
    }

    public function closeDialog(): void
    {
        $this->dialog = '';
        $this->comment = '';
        $this->resetErrorBag();
    }

    public function returnToPurchase(ItemWorkflow $workflow): void
    {
        $item = $this->item();
        Gate::authorize('returnToPurchase', $item);
        $this->validateComment('Please say what Purchase needs to fix.');

        $this->run(fn (User $user) => $workflow->returnToPurchase($item, $user, $this->comment), 'Returned to Purchase.');
    }

    public function approve(ItemWorkflow $workflow): void
    {
        $item = $this->item();
        Gate::authorize('approve', $item);
        $this->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        $this->run(fn (User $user) => $workflow->approve($item, $user, $this->comment !== '' ? $this->comment : null), 'Approved. The item can now be used in transactions.');
    }

    public function reject(ItemWorkflow $workflow): void
    {
        $item = $this->item();
        Gate::authorize('approve', $item);
        $this->validateComment('Please explain why you are rejecting this item.');
        $to = $this->stageFromChoice();

        $this->run(fn (User $user) => $workflow->reject($item, $user, $this->comment, $to), "Rejected and returned to {$to->label()}.");
    }

    public function reopen(ItemWorkflow $workflow): void
    {
        $item = $this->item();
        $to = $this->stageFromChoice();
        Gate::authorize('reopen', [$item, $to]);
        $this->validateComment('Please say why you are re-opening this item.');

        $this->run(fn (User $user) => $workflow->reopen($item, $user, $this->comment, $to), "Re-opened: the item is back with {$to->label()}. It cannot be used in transactions until it is approved again.");
    }

    // ---- availability & deletion ----------------------------------------------------------------------------------

    public function toggleStatus(): void
    {
        $item = $this->item();
        Gate::authorize('changeStatus', $item);

        $item->forceFill(['status' => $item->status === 'active' ? 'inactive' : 'active'])->save();
        unset($this->item);
    }

    public function delete(): void
    {
        $item = $this->item();
        Gate::authorize('delete', $item);

        $item->delete();
        session()->flash('status', 'Item deleted.');
        $this->redirectRoute('masters.items.index', navigate: false);
    }

    // ---- render ---------------------------------------------------------------------------------------------

    public function render(ApprovalService $approvals): View
    {
        $item = $this->item();
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $routed = ApprovalLevel::withoutGlobalScopes()->where('company_id', $item->company_id)->where('document_type', 'item')->exists()
            ? 'the approvers configured for this company'
            : 'the "'.$item->defaultApproverRole().'" role';

        return view('masters.items.show', [
            'item' => $item,
            'canEdit' => Gate::allows('update', $item),
            // what the item has cost over time: only for those who may see stock (cost is not for everyone)
            'costHistory' => $user->can('stock.view') ? ItemCostEntry::query()->where('item_id', $item->id)->with(['changer:id,name'])->orderByDesc('id')->limit(15)->get() : collect(),
            'canSend' => Gate::allows('sendToInventory', $item),
            'canReturn' => Gate::allows('returnToPurchase', $item),
            'canSubmit' => Gate::allows('submit', $item),
            'canApprove' => Gate::allows('approve', $item),
            'canDecide' => $item->workflow_stage === ItemStage::ApprovalPending && Gate::allows('approve', $item) && $approvals->canAct($user, $item),
            'canReopen' => Gate::allows('reopen', $item),
            'reopenStages' => array_values(array_filter([ItemStage::PurchaseDraft, ItemStage::InventoryPending], fn (ItemStage $s) => Gate::allows('reopen', [$item, $s]))),
            'canChangeStatus' => Gate::allows('changeStatus', $item),
            'canDelete' => Gate::allows('delete', $item),
            'missing' => match ($item->workflow_stage) {
                ItemStage::PurchaseDraft => $item->missingForPurchaseStage(),
                ItemStage::InventoryPending => $item->missingForApproval(),
                default => [],
            },
            'routedTo' => $routed,
            'history' => $item->stageHistory()->with('actor')->get(),
            'decisions' => $approvals->history($item)->groupBy('cycle'),
        ]);
    }

    // ---- helpers --------------------------------------------------------------------------------------------

    /** @param  callable(User): mixed  $action */
    private function run(callable $action, string $done): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            $action($user);
        } catch (ApprovalStateException|AuthorizationException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        }

        unset($this->item);
        $this->closeDialog();
        session()->flash('status', $done);
        $this->redirectRoute('masters.items.show', $this->itemId, navigate: false);
    }

    private function validateComment(string $message): void
    {
        $this->validate(['comment' => ['required', 'string', 'min:3', 'max:1000']], ['comment.required' => $message, 'comment.min' => $message]);
    }

    private function stageFromChoice(): ItemStage
    {
        $stage = ItemStage::tryFrom($this->returnTo);

        abort_unless($stage !== null && $stage->isWorkStage(), 422);

        return $stage;
    }
}

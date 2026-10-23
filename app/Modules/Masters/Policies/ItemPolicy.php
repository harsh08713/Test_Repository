<?php

namespace App\Modules\Masters\Policies;

use App\Models\User;
use App\Modules\Masters\Enums\ItemStage;
use App\Modules\Masters\Models\Item;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may do what to an item, stage by stage.
 *
 * Each department owns one stage and has its own permission for it: `item.manage_purchase_stage` (name, group,
 * units, tolerance, HSN, GST) and `item.manage_inventory_stage` (code, aliases, stock levels). Holding a stage
 * permission is not enough on its own — the item must also be *in* that stage and not locked by the approval.
 * Operations holds neither, so it can view and comment but every change is refused. Approving needs `item.approve`
 * and an item awaiting approval; who is designated is the approval engine's business.
 */
class ItemPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'item';
    }

    private function purchaseDesk(User $user): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $user->can('item.manage_purchase_stage');
    }

    private function inventoryDesk(User $user): bool
    {
        return $this->allows($user, PermissionAction::Edit) && $user->can('item.manage_inventory_stage');
    }

    /** Purchase starts items. */
    public function create(User $user): bool
    {
        return $this->allows($user, PermissionAction::Create) && $user->can('item.manage_purchase_stage');
    }

    /** May this user change the Purchase part of this item right now? */
    public function editPurchase(User $user, Item $item): bool
    {
        return $this->purchaseDesk($user) && $this->withinTenant($user, $item)
            && $item->workflow_stage === ItemStage::PurchaseDraft && $item->isEditableByWorkflow();
    }

    /** May this user change the Inventory part of this item right now? */
    public function editInventory(User $user, Item $item): bool
    {
        return $this->inventoryDesk($user) && $this->withinTenant($user, $item)
            && $item->workflow_stage === ItemStage::InventoryPending && $item->isEditableByWorkflow();
    }

    /** Can this user edit *something* on the item now? (Which screen they get depends on the stage.) */
    public function update(User $user, Model $model): bool
    {
        return $model instanceof Item && ($this->editPurchase($user, $model) || $this->editInventory($user, $model));
    }

    /** Purchase hands a complete stage 1 to Inventory. */
    public function sendToInventory(User $user, Item $item): bool
    {
        return $this->editPurchase($user, $item);
    }

    /** Inventory can hand the item back to Purchase, with a reason. */
    public function returnToPurchase(User $user, Item $item): bool
    {
        return $this->editInventory($user, $item);
    }

    /** Inventory submits the finished item for approval. */
    public function submit(User $user, Model $model): bool
    {
        return $model instanceof Item && $this->editInventory($user, $model);
    }

    /** Deciding needs the approve permission and an item that is actually waiting for a decision. */
    public function approve(User $user, Model $model): bool
    {
        return $model instanceof Item && parent::approve($user, $model) && $model->workflow_stage === ItemStage::ApprovalPending;
    }

    /** Drafts can be deleted; an item that was ever approved can only be deactivated. */
    public function delete(User $user, Model $model): bool
    {
        return $model instanceof Item && parent::delete($user, $model) && ! $model->everApproved();
    }

    /** Activate / deactivate: a change of availability, not of content, so it is allowed on an approved item. */
    public function changeStatus(User $user, Item $item): bool
    {
        return ($this->purchaseDesk($user) || $this->inventoryDesk($user)) && $this->withinTenant($user, $item);
    }

    /**
     * Take an approved item back for correction. The stage it returns to must be one the user runs:
     * Purchase to change the item's identity, Inventory to change codes, aliases or stock levels.
     */
    public function reopen(User $user, Item $item, ?ItemStage $to = null): bool
    {
        if (! $item->isApproved() || ! $this->withinTenant($user, $item)) {
            return false;
        }

        return match ($to) {
            ItemStage::PurchaseDraft => $this->purchaseDesk($user),
            ItemStage::InventoryPending => $this->inventoryDesk($user),
            default => $this->purchaseDesk($user) || $this->inventoryDesk($user),
        };
    }

    public function viewAudit(User $user, Item $item): bool
    {
        return $user->can('item.view_audit') && $this->view($user, $item);
    }
}

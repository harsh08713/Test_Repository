<?php

namespace App\Modules\Masters\Services;

use App\Models\User;
use App\Modules\Masters\Enums\ItemStage;
use App\Modules\Masters\Models\Item;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moves an item from one department to the next:
 *
 *   Purchase ──send──▶ Inventory ──submit──▶ awaiting approval ──approve──▶ approved
 *      ▲                  │                        │
 *      └───── return ─────┘◀──── reject (to either) ┘        approved ──re-open (to either)──▶ back for rework
 *
 * The transitions that involve the approval engine (submit, approve, reject, re-open) go through ApprovalService —
 * the approval ledger, maker–checker, fingerprint and events all apply — and the Item model keeps its stage in step
 * and writes the stage history. The two that do not (send to Inventory, return to Purchase) are plain stage moves.
 *
 * It checks the *state* of the item, not the user's rights: callers authorise with ItemPolicy first.
 */
final class ItemWorkflow
{
    public function __construct(private readonly ApprovalService $approvals) {}

    /** Purchase hands a complete stage 1 to Inventory. */
    public function sendToInventory(Item $item, User $by): Item
    {
        return DB::transaction(function () use ($item, $by) {
            $this->lock($item);
            $this->assertStage($item, ItemStage::PurchaseDraft, 'Only an item in the Purchase stage can be sent to Inventory.');

            $missing = $item->missingForPurchaseStage();
            if ($missing !== []) {
                throw ApprovalStateException::notReady('Complete the Purchase details before sending the item to Inventory: '.implode('; ', $missing).'.');
            }

            return $this->move($item, ItemStage::InventoryPending, $by, null);
        });
    }

    /** Inventory hands the item back to Purchase, saying what is wrong. */
    public function returnToPurchase(Item $item, User $by, string $comment): Item
    {
        if (trim($comment) === '') {
            throw ApprovalStateException::returnReasonRequired();
        }

        return DB::transaction(function () use ($item, $by, $comment) {
            $this->lock($item);
            $this->assertStage($item, ItemStage::InventoryPending, 'Only an item in the Inventory stage can be returned to Purchase.');

            return $this->move($item, ItemStage::PurchaseDraft, $by, $comment);
        });
    }

    /** Inventory submits the finished item. Both stages must be complete and someone must be able to decide it. */
    public function submit(Item $item, User $by): Item
    {
        return $this->through($item, $by, null, null, fn () => $this->approvals->submit($item, $by));
    }

    public function approve(Item $item, User $by, ?string $comment = null): Item
    {
        return $this->through($item, $by, $comment, null, fn () => $this->approvals->approve($item, $by, $comment));
    }

    /** Reject, and name the stage the item goes back to: the one whose work has to change. */
    public function reject(Item $item, User $by, string $comment, ItemStage $returnTo): Item
    {
        $this->assertWorkStage($returnTo);

        return $this->through($item, $by, $comment, $returnTo, fn () => $this->approvals->reject($item, $by, $comment));
    }

    /** Take an approved item back for correction, to Purchase (its identity) or Inventory (code, aliases, stock levels). */
    public function reopen(Item $item, User $by, string $reason, ItemStage $returnTo): Item
    {
        $this->assertWorkStage($returnTo);

        return $this->through($item, $by, $reason, $returnTo, fn () => $this->approvals->reopen($item, $by, $reason));
    }

    // ---- plumbing -----------------------------------------------------------------------------------------------

    /**
     * Run an approval-engine action with the actor, comment and return stage on the item, so the stage history it
     * writes names the right person and reason; clear them afterwards (a multi-level approval may not move the stage).
     *
     * @param  callable(): mixed  $action
     */
    private function through(Item $item, User $by, ?string $comment, ?ItemStage $returnTo, callable $action): Item
    {
        $item->stageActor = $by;
        $item->stageComment = $comment;
        $item->returnStage = $returnTo;

        try {
            DB::transaction(fn () => $action());
        } finally {
            $item->stageActor = $item->stageComment = $item->returnStage = null;
        }

        return $item->refresh();
    }

    private function move(Item $item, ItemStage $to, User $by, ?string $comment): Item
    {
        $item->stageActor = $by;
        $item->stageComment = $comment;
        $item->forceFill(['workflow_stage' => $to])->save();

        return $item->refresh();
    }

    /** Re-read the row under a lock so two people cannot move the same item at once. */
    private function lock(Item $item): void
    {
        $fresh = Item::query()->lockForUpdate()->findOrFail($item->getKey());
        $item->setRawAttributes($fresh->getAttributes(), true);
    }

    private function assertStage(Item $item, ItemStage $expected, string $message): void
    {
        if ($item->workflow_stage !== $expected || ! in_array($item->approval_status, [ApprovalStatus::Draft, ApprovalStatus::Rejected], true)) {
            throw ApprovalStateException::notReady($message);
        }
    }

    private function assertWorkStage(ItemStage $stage): void
    {
        if (! $stage->isWorkStage()) {
            throw new InvalidArgumentException('An item can only be sent back to Purchase or Inventory.');
        }
    }
}

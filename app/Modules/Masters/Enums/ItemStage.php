<?php

namespace App\Modules\Masters\Enums;

use App\Modules\Shared\Enums\ApprovalStatus;

/**
 * Whose turn it is with an item. Together with the approval status:
 *
 *   purchase_draft → inventory_pending → approval_pending → approved
 *
 * A rejection (or a re-opening) sends the item back to the stage the decision names, with the approval status
 * showing it was rejected: "returned to Purchase".
 */
enum ItemStage: string
{
    case PurchaseDraft = 'purchase_draft';
    case InventoryPending = 'inventory_pending';
    case ApprovalPending = 'approval_pending';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseDraft => 'Purchase',
            self::InventoryPending => 'Inventory',
            self::ApprovalPending => 'Awaiting approval',
            self::Approved => 'Approved',
        };
    }

    /** Position on the progress tracker, 1–4. */
    public function step(): int
    {
        return match ($this) {
            self::PurchaseDraft => 1,
            self::InventoryPending => 2,
            self::ApprovalPending => 3,
            self::Approved => 4,
        };
    }

    /** The two stages a person fills in (as opposed to waiting on an approver, or done). */
    public function isWorkStage(): bool
    {
        return $this === self::PurchaseDraft || $this === self::InventoryPending;
    }

    /** Is this stage consistent with the approval engine's state? (Used as a model-level invariant.) */
    public function fits(ApprovalStatus $status): bool
    {
        return match ($this) {
            self::PurchaseDraft, self::InventoryPending => $status === ApprovalStatus::Draft || $status === ApprovalStatus::Rejected,
            self::ApprovalPending => $status === ApprovalStatus::Pending,
            self::Approved => $status === ApprovalStatus::Approved,
        };
    }

    /** Tailwind classes for the stage badge. */
    public function badge(): string
    {
        return match ($this) {
            self::PurchaseDraft => 'bg-slate-100 text-slate-700',
            self::InventoryPending => 'bg-sky-100 text-sky-800',
            self::ApprovalPending => 'bg-amber-100 text-amber-800',
            self::Approved => 'bg-emerald-100 text-emerald-800',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}

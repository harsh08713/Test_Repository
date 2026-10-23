<?php

namespace App\Modules\Masters\Models;

use App\Models\User;
use App\Modules\Masters\Enums\ItemStage;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One move of an item between stages: who, when, from where to where, and why. Append-only — a correction is a
 * new row — and written only by the Item model, so every path through the workflow leaves its trace.
 *
 * @property int $id
 * @property int $item_id
 * @property string $event
 * @property ItemStage|null $from_stage
 * @property ItemStage $to_stage
 * @property ApprovalStatus $approval_status
 * @property int|null $actor_id
 * @property string|null $comment
 * @property Carbon|null $created_at
 */
class ItemStageEntry extends Model
{
    use BelongsToCompany;

    protected $table = 'item_stage_history';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'from_stage' => ItemStage::class,
            'to_stage' => ItemStage::class,
            'approval_status' => ApprovalStatus::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Item stage history is append-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Item stage history is append-only and cannot be deleted.'));
    }

    public static function record(Item $item, ?ItemStage $from, ItemStage $to, ?User $actor, ?string $comment): self
    {
        $entry = new self;
        $entry->forceFill([
            'company_id' => $item->company_id,
            'item_id' => $item->getKey(),
            'event' => self::eventFor($from, $to, $item->approval_status),
            'from_stage' => $from?->value,
            'to_stage' => $to->value,
            'approval_status' => $item->approval_status->value,
            'actor_id' => $actor?->getKey(),
            'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
            'created_at' => now(),
        ])->save();

        return $entry;
    }

    /** Name the move from where it started and ended. */
    public static function eventFor(?ItemStage $from, ItemStage $to, ApprovalStatus $status): string
    {
        return match (true) {
            $from === null => 'created',
            $from === ItemStage::PurchaseDraft && $to === ItemStage::InventoryPending => 'sent_to_inventory',
            $from === ItemStage::InventoryPending && $to === ItemStage::PurchaseDraft => 'returned_to_purchase',
            $from === ItemStage::InventoryPending && $to === ItemStage::ApprovalPending => 'submitted',
            $from === ItemStage::ApprovalPending && $to === ItemStage::Approved => 'approved',
            $from === ItemStage::ApprovalPending => 'rejected',
            $from === ItemStage::Approved => 'reopened',
            default => 'moved',
        };
    }

    public function label(): string
    {
        return match ($this->event) {
            'created' => 'Started by Purchase',
            'sent_to_inventory' => 'Sent to Inventory',
            'returned_to_purchase' => 'Returned to Purchase',
            'submitted' => 'Submitted for approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected — returned to '.$this->to_stage->label(),
            'reopened' => 'Re-opened — back with '.$this->to_stage->label(),
            default => 'Moved to '.$this->to_stage->label(),
        };
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}

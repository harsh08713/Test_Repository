<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Masters\Models\Item;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of the stock ledger: a signed quantity (in the item's base unit) in or out of a branch, at a cost, with the document or the reason
 * behind it. **Append-only evidence**: it is never edited or removed — a mistake is put right by another movement.
 *
 * @property int $id
 * @property int $company_id
 * @property int $location_id
 * @property int $item_id
 * @property int|null $batch_id
 * @property string $movement_type
 * @property string $quantity
 * @property string $unit_cost
 * @property string $value
 * @property string|null $source_type
 * @property int|null $source_id
 * @property int|null $source_line_id
 * @property string|null $reason
 * @property int|null $created_by
 * @property Carbon $moved_at
 * @property Carbon|null $created_at
 */
#[Fillable([])]
class StockMovement extends Model
{
    use BelongsToCompany, BelongsToLocation;

    public const UPDATED_AT = null;

    public const RECEIPT = 'receipt';

    public const ISSUE = 'issue';

    public const TRANSFER_IN = 'transfer_in';

    public const TRANSFER_OUT = 'transfer_out';

    public const ADJUSTMENT_IN = 'adjustment_in';

    public const ADJUSTMENT_OUT = 'adjustment_out';

    public const SCRAP = 'scrap';

    public const PURCHASE_RETURN = 'purchase_return';

    /** @var array<string, string> */
    public const LABELS = [
        self::RECEIPT => 'Receipt', self::ISSUE => 'Issue', self::TRANSFER_IN => 'Transfer in', self::TRANSFER_OUT => 'Transfer out',
        self::ADJUSTMENT_IN => 'Adjustment (found)', self::ADJUSTMENT_OUT => 'Adjustment (lost)', self::SCRAP => 'Scrap', self::PURCHASE_RETURN => 'Purchase return',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A stock movement is evidence: it is never changed. Put a mistake right with another movement.'));
        static::deleting(fn () => throw new LogicException('A stock movement is evidence: it is never removed.'));
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:4', 'value' => 'decimal:2', 'moved_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function label(): string
    {
        return self::LABELS[$this->movement_type] ?? $this->movement_type;
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

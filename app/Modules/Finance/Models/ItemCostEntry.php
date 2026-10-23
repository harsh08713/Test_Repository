<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Modules\Masters\Models\Item;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One change of what an item costs: the standard cost edited on the item, or the moving average after a receipt. **Append-only evidence**.
 *
 * @property int $item_id
 * @property int|null $location_id
 * @property string $method standard|moving_average
 * @property string|null $unit_cost per base unit
 * @property string|null $previous_cost
 * @property string|null $source_type
 * @property int|null $source_id
 * @property int|null $changed_by
 * @property Carbon $effective_at
 */
#[Fillable([])]
class ItemCostEntry extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'item_cost_history';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cost history is evidence: it is never changed.'));
        static::deleting(fn () => throw new LogicException('Cost history is evidence: it is never removed.'));
    }

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:4', 'previous_cost' => 'decimal:4', 'effective_at' => 'datetime'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public static function record(int $companyId, int $itemId, ?int $locationId, string $method, ?string $cost, ?string $previous, ?string $sourceType = null, ?int $sourceId = null, ?int $by = null): void
    {
        if ($cost === $previous) {
            return;
        }

        $entry = new self;
        $entry->forceFill([
            'company_id' => $companyId, 'item_id' => $itemId, 'location_id' => $locationId, 'method' => $method, 'unit_cost' => $cost, 'previous_cost' => $previous,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'changed_by' => $by, 'effective_at' => now(),
        ])->save();
    }
}

<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Masters\Models\Item;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What is on hand now of an item at a branch (per batch, or `batch_key` 0 for stock without one), with its value. Derived from the stock
 * ledger and kept in step by StockService inside the same transaction; `StockService::rebuildBalances()` makes it again from the ledger.
 * Nothing else writes it.
 *
 * @property int $location_id
 * @property int $item_id
 * @property int $batch_key
 * @property int|null $batch_id
 * @property string $quantity
 * @property string $value
 */
#[Fillable([])]
class StockBalance extends Model
{
    use BelongsToCompany, BelongsToLocation;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'value' => 'decimal:2'];
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
}

<?php

namespace App\Modules\Production\Models;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Finished goods (and rejects) reported for an order. Append-only evidence: written by ProductionOrderService, never edited or deleted — a mistake is put right by a stock correction and a note. */
class ProductionOutput extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['good_quantity' => 'decimal:3', 'rejected_quantity' => 'decimal:3', 'unit_cost' => 'decimal:4', 'recorded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('This entry is a record and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('This entry is a record and cannot be deleted.'));
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}

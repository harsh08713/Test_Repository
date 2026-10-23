<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Spare parts taken from stock for a work order. Append-only evidence: written by MaintenanceOrderService, never edited or deleted — a mistake is put right by a stock correction and a note.
 *
 * @property int $maintenance_order_id
 * @property int $item_id
 * @property int|null $batch_id
 * @property string $quantity
 * @property string $entry_key
 * @property Carbon $issued_at
 */
class MaintenancePart extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'issued_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('This entry is a record and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('This entry is a record and cannot be deleted.'));
    }

    /** @return BelongsTo<MaintenanceOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(MaintenanceOrder::class, 'maintenance_order_id');
    }
}

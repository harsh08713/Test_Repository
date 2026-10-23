<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Hours worked on a work order. Append-only evidence: written by MaintenanceOrderService, never edited or deleted — a mistake is put right by a note.
 *
 * @property int $maintenance_order_id
 * @property int $user_id
 * @property Carbon $work_date
 * @property string $hours
 * @property string|null $note
 * @property string $entry_key
 */
class MaintenanceLabour extends Model
{
    use BelongsToCompany;

    protected $table = 'maintenance_labour';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['hours' => 'decimal:2', 'work_date' => 'date:Y-m-d'];
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

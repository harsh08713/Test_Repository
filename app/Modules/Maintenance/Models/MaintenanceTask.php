<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One thing to do on a work order. Only the "done" mark ever changes, and only once.
 *
 * @property int $maintenance_order_id
 * @property int $line_no
 * @property string $description
 * @property Carbon|null $done_at
 * @property int|null $done_by
 */
class MaintenanceTask extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['done_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $t) {
            if ($t->getRawOriginal('done_at') !== null) {
                throw new LogicException('A task that is done stays done.');
            }

            if ($t->isDirty(['maintenance_order_id', 'line_no', 'description', 'company_id'])) {
                throw new LogicException('Only the done mark of a task can be set.');
            }
        });
        static::deleting(fn () => throw new LogicException('Tasks are a record and cannot be deleted.'));
    }
}

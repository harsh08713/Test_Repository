<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One thing to do for one person, copied from the standard list. Only the "done" columns ever change — and only once: what was done, by whom and when is not rewritten.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property string $kind
 * @property int|null $source_item_id
 * @property string $title
 * @property string $responsible
 * @property Carbon $due_on
 * @property Carbon|null $done_at
 * @property int|null $done_by
 * @property string|null $note
 */
class EmployeeChecklistItem extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'done_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $item) {
            if ($item->getRawOriginal('done_at') !== null) {
                throw new LogicException('A finished checklist item is not changed.');
            }

            if ($item->isDirty(['employee_id', 'kind', 'title', 'responsible', 'due_on', 'source_item_id', 'company_id'])) {
                throw new LogicException('Only the finished mark of a checklist item can be set.');
            }
        });
        static::deleting(fn () => throw new LogicException('Checklist items are not deleted.'));
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}

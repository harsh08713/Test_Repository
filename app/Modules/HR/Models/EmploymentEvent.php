<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One thing that happened to a person's employment (joined, moved, promoted, got a new manager, confirmed, gave notice, left, came back). **Append-only evidence**:
 * a correction is a new row, never an edit.
 *
 * @property int $employee_id
 * @property string $event
 * @property Carbon $effective_on
 * @property string|null $from_value
 * @property string|null $to_value
 * @property string|null $remarks
 * @property int|null $recorded_by
 * @property Carbon $created_at
 */
#[Fillable([])]
class EmploymentEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const LABELS = [
        'joined' => 'Joined', 'department' => 'Department changed', 'designation' => 'Designation changed', 'branch' => 'Moved branch', 'manager' => 'Manager changed',
        'type' => 'Employment type changed', 'confirmed' => 'Confirmed', 'notice' => 'Notice recorded', 'exited' => 'Left the company', 'rejoined' => 'Rejoined',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Employment history is evidence: it is never changed.'));
        static::deleting(fn () => throw new LogicException('Employment history is evidence: it is never removed.'));
    }

    protected function casts(): array
    {
        return ['effective_on' => 'date', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function label(): string
    {
        return self::LABELS[$this->event] ?? $this->event;
    }
}

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
 * One line of the leave ledger: days granted (opening, accrual, adjustment), taken (negative), given back (reversal) or lapsed. **Append-only evidence**; a balance
 * is the sum of a person's lines of one type in one leave year. `period_key` is what makes each line happen once.
 *
 * @property int $employee_id
 * @property int $leave_type_id
 * @property int $leave_year
 * @property string $entry_type
 * @property numeric-string $days
 * @property string $period_key
 * @property int|null $leave_request_id
 * @property Carbon $entry_date
 * @property string|null $remarks
 * @property int|null $recorded_by
 */
#[Fillable([])]
class LeaveLedgerEntry extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'leave_ledger';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The leave ledger is evidence: it is never changed.'));
        static::deleting(fn () => throw new LogicException('The leave ledger is evidence: it is never removed.'));
    }

    protected function casts(): array
    {
        return ['days' => 'decimal:1', 'entry_date' => 'date', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
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
}

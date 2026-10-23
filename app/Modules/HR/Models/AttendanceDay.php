<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a day came to for a person (present, half day, absent, leave, holiday, weekly off), written only by `AttendanceService::closeDay`.
 *
 * @property int $employee_id
 * @property Carbon $work_date
 * @property string $status
 * @property Carbon|null $first_in
 * @property Carbon|null $last_out
 * @property int $worked_minutes
 * @property int $late_minutes
 * @property numeric-string $leave_days
 * @property bool $missing_out
 */
#[Fillable([])]
class AttendanceDay extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['present' => 'Present', 'half_day' => 'Half day', 'absent' => 'Absent', 'leave' => 'On leave', 'holiday' => 'Holiday', 'weekly_off' => 'Weekly off'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['work_date' => 'date', 'first_in' => 'datetime', 'last_out' => 'datetime', 'leave_days' => 'decimal:1', 'missing_out' => 'boolean', 'closed_at' => 'datetime'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Modules\HR\Policies\LeaveRequestPolicy;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A request to put a day's punches right ("I forgot to punch out"). Decided only through `AttendanceService`; once decided it does not change.
 *
 * @property int $employee_id
 * @property Carbon $work_date
 * @property string $in_time
 * @property string $out_time
 * @property string $reason
 * @property string $state
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 */
#[Fillable([])]
#[UsePolicy(LeaveRequestPolicy::class)]
class AttendanceRegularisation extends TenantModel
{
    use BelongsToLocation;

    protected $attributes = ['state' => 'pending'];

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            if ((string) $row->getRawOriginal('state') !== 'pending') {
                throw new LogicException('A decided regularisation does not change.');
            }
        });
    }

    protected function casts(): array
    {
        return ['work_date' => 'date', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

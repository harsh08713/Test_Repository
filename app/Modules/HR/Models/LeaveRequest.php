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
 * A request for leave. Made, decided and cancelled only through `LeaveService`. Once decided it does not change: an approved one can only be cancelled
 * (and then the days go back), a rejected or cancelled one not at all.
 *
 * @property int $employee_id
 * @property int $leave_type_id
 * @property Carbon $from_date
 * @property Carbon $to_date
 * @property string|null $half_day
 * @property numeric-string $days
 * @property string $reason
 * @property string $state
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 */
#[Fillable([])]
#[UsePolicy(LeaveRequestPolicy::class)]
class LeaveRequest extends TenantModel
{
    use BelongsToLocation;

    public const STATES = ['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    protected $attributes = ['state' => 'pending'];

    protected static function booted(): void
    {
        static::updating(function (self $request) {
            $was = (string) $request->getRawOriginal('state');
            $moving = ['state', 'decided_by', 'decided_at', 'decision_note', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'updated_by', 'updated_at'];

            if (in_array($was, ['rejected', 'cancelled'], true) || ($was === 'approved' && array_diff(array_keys($request->getDirty()), $moving) !== [])) {
                throw new LogicException('A decided leave request does not change.');
            }
        });
    }

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date', 'days' => 'decimal:1', 'decided_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function number(): string
    {
        return 'LV-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }
}

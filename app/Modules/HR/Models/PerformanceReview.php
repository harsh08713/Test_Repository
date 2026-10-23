<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * PerformanceReview (H4). Changed only through its service.
 *
 * @property int $employee_id
 * @property int $review_cycle_id
 * @property int|null $reviewer_id
 * @property string $state
 * @property int|null $self_rating
 * @property string|null $self_comments
 * @property int|null $manager_rating
 * @property string|null $manager_comments
 * @property int|null $final_rating
 * @property Carbon|null $finalised_at
 * @property int|null $finalised_by
 */
#[Fillable([])]
class PerformanceReview extends TenantModel
{
    public const STATES = ['pending' => 'Waiting for the person', 'self_done' => 'Waiting for the manager', 'manager_done' => 'Waiting for HR', 'finalised' => 'Final'];

    public const RATINGS = [1 => 'Needs improvement', 2 => 'Below expectations', 3 => 'Meets expectations', 4 => 'Exceeds expectations', 5 => 'Outstanding'];

    protected $attributes = ['state' => 'pending'];

    protected static function booted(): void
    {
        static::updating(function (self $review) {
            if ((string) $review->getRawOriginal('state') === 'finalised') {
                throw new LogicException('A final review does not change.');
            }
        });
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<ReviewCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class, 'review_cycle_id');
    }

    protected function casts(): array
    {
        return ['finalised_at' => 'datetime'];
    }
}

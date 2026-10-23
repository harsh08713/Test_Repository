<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PerformanceGoal (H4). Changed only through its service.
 *
 * @property int $employee_id
 * @property int $review_cycle_id
 * @property string $title
 * @property string|null $description
 * @property int $weight
 * @property int $progress
 */
#[Fillable(['title', 'description', 'weight'])]
class PerformanceGoal extends TenantModel
{
    protected $attributes = ['progress' => 0];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected function casts(): array
    {
        return ['weight' => 'integer', 'progress' => 'integer'];
    }
}

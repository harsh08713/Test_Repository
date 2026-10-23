<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TrainingEnrolment (H4). Changed only through its service.
 *
 * @property int $training_program_id
 * @property int $employee_id
 * @property string $state
 * @property numeric-string|null $score
 */
#[Fillable([])]
class TrainingEnrolment extends TenantModel
{
    public const STATES = ['enrolled' => 'Enrolled', 'completed' => 'Completed', 'absent' => 'Absent', 'dropped' => 'Dropped'];

    protected $attributes = ['state' => 'enrolled'];

    /** @return BelongsTo<TrainingProgram, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(TrainingProgram::class, 'training_program_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected function casts(): array
    {
        return ['score' => 'decimal:1'];
    }
}

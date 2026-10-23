<?php

namespace App\Modules\HR\Models;

use App\Modules\HR\Policies\TrainingPolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * TrainingProgram (H4). Changed only through its service.
 *
 * @property string $title
 * @property string|null $trainer
 * @property string $mode
 * @property string|null $venue
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property int $capacity
 * @property string $state
 */
#[Fillable(['code', 'title', 'trainer', 'mode', 'venue', 'starts_on', 'ends_on', 'capacity', 'remarks'])]
#[UsePolicy(TrainingPolicy::class)]
class TrainingProgram extends TenantModel
{
    public const STATES = ['planned' => 'Planned', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

    public const MODES = ['in_person' => 'In person', 'online' => 'Online'];

    protected $attributes = ['state' => 'planned', 'mode' => 'in_person', 'capacity' => 20];

    /** @return HasMany<TrainingEnrolment, $this> */
    public function enrolments(): HasMany
    {
        return $this->hasMany(TrainingEnrolment::class);
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'capacity' => 'integer'];
    }
}

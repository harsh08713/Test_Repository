<?php

namespace App\Modules\HR\Models;

use App\Modules\HR\Policies\RecruitmentPolicy;
use App\Modules\Masters\Models\Department;
use App\Modules\Masters\Models\Designation;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * JobOpening (H4). Changed only through its service.
 *
 * @property string $title
 * @property int $location_id
 * @property int|null $department_id
 * @property int|null $designation_id
 * @property int|null $hiring_manager_id
 * @property int $positions
 * @property string|null $description
 * @property string $state
 * @property Carbon|null $opened_on
 * @property Carbon|null $closed_on
 * @property string|null $close_reason
 */
#[Fillable(['code', 'title', 'department_id', 'designation_id', 'hiring_manager_id', 'positions', 'description', 'remarks'])]
#[UsePolicy(RecruitmentPolicy::class)]
class JobOpening extends TenantModel
{
    use BelongsToLocation;

    public const STATES = ['draft' => 'Draft', 'open' => 'Open', 'closed' => 'Closed'];

    protected $attributes = ['state' => 'draft', 'positions' => 1];

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Designation, $this> */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /** @return HasMany<JobApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    protected function casts(): array
    {
        return ['opened_on' => 'date', 'closed_on' => 'date', 'positions' => 'integer'];
    }
}

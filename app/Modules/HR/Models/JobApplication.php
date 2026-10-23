<?php

namespace App\Modules\HR\Models;

use App\Modules\HR\Policies\RecruitmentPolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * JobApplication (H4). Changed only through its service.
 *
 * @property int $candidate_id
 * @property int $job_opening_id
 * @property string $stage
 * @property string|null $reject_reason
 * @property int|null $hired_employee_id
 */
#[Fillable([])]
#[UsePolicy(RecruitmentPolicy::class)]
class JobApplication extends TenantModel
{
    public const STAGES = ['applied' => 'Applied', 'screening' => 'Screening', 'interview' => 'Interview', 'offer' => 'Offer', 'hired' => 'Hired', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'];

    /** Where an application may go from each stage by `moveStage`. Offer and hired are reached only by `makeOffer` and `hire`. */
    public const MOVES = ['applied' => ['screening', 'rejected', 'withdrawn'], 'screening' => ['interview', 'rejected', 'withdrawn'], 'interview' => ['rejected', 'withdrawn'], 'offer' => ['rejected', 'withdrawn'], 'hired' => [], 'rejected' => [], 'withdrawn' => []];

    protected $table = 'applications';

    protected $attributes = ['stage' => 'applied'];

    /** @return BelongsTo<Candidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /** @return BelongsTo<JobOpening, $this> */
    public function opening(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class, 'job_opening_id');
    }

    /** @return HasMany<Interview, $this> */
    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class, 'application_id');
    }

    /** @return HasOne<Offer, $this> */
    public function offer(): HasOne
    {
        return $this->hasOne(Offer::class, 'application_id');
    }

    /** @return HasMany<ApplicationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ApplicationEvent::class, 'application_id');
    }

    protected function casts(): array
    {
        return [];
    }
}

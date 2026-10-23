<?php

namespace App\Modules\HR\Models;

use App\Modules\HR\Policies\RecruitmentPolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Candidate (H4). Changed only through its service.
 *
 * @property string $name
 * @property string|null $mobile
 * @property string|null $email
 * @property string $source
 * @property string|null $current_employer
 * @property numeric-string|null $experience_years
 * @property string|null $notes
 */
#[Fillable(['name', 'mobile', 'email', 'source', 'current_employer', 'experience_years', 'notes'])]
#[UsePolicy(RecruitmentPolicy::class)]
class Candidate extends TenantModel
{
    public const SOURCES = ['referral' => 'Referral', 'portal' => 'Job portal', 'agency' => 'Agency', 'walk_in' => 'Walk-in', 'other' => 'Other'];

    protected $attributes = ['source' => 'other'];

    /** @return HasMany<JobApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    protected function casts(): array
    {
        return ['experience_years' => 'decimal:1'];
    }
}

<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Offer (H4). Changed only through its service.
 *
 * @property int $application_id
 * @property int|null $designation_id
 * @property Carbon $joining_date
 * @property string $state
 * @property Carbon|null $responded_on
 * @property string|null $note
 */
#[Fillable([])]
class Offer extends TenantModel
{
    protected $attributes = ['state' => 'draft'];

    /** @return BelongsTo<JobApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'application_id');
    }

    protected function casts(): array
    {
        return ['joining_date' => 'date', 'responded_on' => 'date'];
    }
}

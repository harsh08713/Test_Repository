<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Interview (H4). Changed only through its service.
 *
 * @property int $application_id
 * @property int $round
 * @property Carbon $scheduled_at
 * @property string $mode
 * @property int $interviewer_id
 * @property string $state
 * @property int|null $rating
 * @property string|null $recommendation
 * @property string|null $feedback
 */
#[Fillable([])]
class Interview extends TenantModel
{
    public const MODES = ['in_person' => 'In person', 'phone' => 'Phone', 'video' => 'Video'];

    protected $attributes = ['state' => 'scheduled', 'mode' => 'in_person', 'round' => 1];

    /** @return BelongsTo<JobApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_id');
    }

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'round' => 'integer', 'rating' => 'integer'];
    }
}

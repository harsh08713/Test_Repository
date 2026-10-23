<?php

namespace App\Modules\Workspace\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use App\Modules\Workspace\Enums\SlaKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The clock on one thing being timed. Written only by SlaSweep.
 *
 * @property int $id
 * @property int $sla_policy_id
 * @property SlaKind $kind
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $owner_id
 * @property Carbon $started_at
 * @property Carbon $due_at
 * @property string $state running | met | stopped
 * @property Carbon|null $stopped_at
 * @property string|null $stop_reason
 * @property Carbon|null $breached_at
 * @property Carbon|null $escalated_at
 * @property Carbon|null $paused_at
 * @property int $paused_minutes
 * @property string|null $pause_reason
 */
class SlaClock extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => SlaKind::class, 'started_at' => 'datetime', 'due_at' => 'datetime', 'stopped_at' => 'datetime',
            'breached_at' => 'datetime', 'escalated_at' => 'datetime', 'paused_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('An SLA clock is kept as a record and cannot be deleted.'));
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<SlaPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}

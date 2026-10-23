<?php

namespace App\Modules\Loss\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One move of something with a workflow (a loss incident, a corrective action): from, to, by whom, why. **Append-only evidence**.
 *
 * @property int $id
 * @property int $company_id
 * @property string $subject_type morph alias
 * @property int $subject_id
 * @property string|null $from_state
 * @property string $to_state
 * @property string $action
 * @property string|null $reason
 * @property int|null $actor_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 */
#[Fillable([])]
class WorkflowEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Workflow history is evidence: it is never changed.'));
        static::deleting(fn () => throw new LogicException('Workflow history is evidence: it is never removed.'));
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Write one, now.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public static function record(Model $subject, ?string $from, string $to, string $action, ?User $actor, ?string $reason = null, ?array $metadata = null): self
    {
        $event = new self;
        $event->forceFill([
            'company_id' => (int) $subject->getAttribute('company_id'), 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
            'from_state' => $from, 'to_state' => $to, 'action' => $action, 'reason' => $reason, 'actor_id' => $actor?->getKey(), 'metadata' => $metadata,
            'created_at' => now(),
        ])->save();

        return $event;
    }
}

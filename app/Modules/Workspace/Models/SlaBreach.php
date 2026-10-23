<?php

namespace App\Modules\Workspace\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use App\Modules\Workspace\Enums\SlaKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The record that a clock ran out: when, for whom, and who was told. Append-only; written only by SlaSweep.
 *
 * @property int $id
 * @property int $sla_clock_id
 * @property int $sla_policy_id
 * @property SlaKind $kind
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $owner_id
 * @property int|null $escalated_to
 * @property Carbon $due_at
 * @property Carbon $detected_at
 * @property bool $answered_late
 */
class SlaBreach extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['kind' => SlaKind::class, 'due_at' => 'datetime', 'detected_at' => 'datetime', 'answered_late' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The SLA breach log is append-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('The SLA breach log is append-only and cannot be deleted.'));
    }

    /** @return BelongsTo<SlaClock, $this> */
    public function clock(): BelongsTo
    {
        return $this->belongsTo(SlaClock::class, 'sla_clock_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function escalatedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_to');
    }
}

<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Modules\CRM\Enums\FollowupStatus;
use App\Modules\CRM\Enums\FollowupType;
use App\Modules\CRM\Policies\FollowupPolicy;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Something to do with a lead at a time: a call, a visit, an e-mail, a WhatsApp message, a meeting — planned, then done
 * with an outcome, or called off. Its `status` is its own state (planned / done / cancelled), not the generic
 * active/inactive of a master.
 *
 * It hangs on its subject through a morph (`subject_type` / `subject_id`): a lead today; deals, quotations and customers
 * get theirs in later phases without a new table.
 *
 * @property string $subject_type
 * @property int $subject_id
 * @property Carbon $scheduled_at
 * @property FollowupType $type
 * @property int $assigned_to
 * @property FollowupStatus $status
 * @property int|null $outcome_id
 * @property string|null $notes
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 * @property int|null $previous_id
 * @property Carbon|null $reminder_sent_at
 * @property Carbon|null $escalated_at
 * @property int|null $location_id
 */
#[Fillable(['scheduled_at', 'type', 'notes'])]
#[UsePolicy(FollowupPolicy::class)]
class Followup extends TenantModel
{
    use BelongsToLocation, ValidatesParentCompany;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'escalated_at' => 'datetime',
            'type' => FollowupType::class,
            'status' => FollowupStatus::class,
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return BelongsTo<FollowupOutcome, $this> */
    public function outcome(): BelongsTo
    {
        return $this->belongsTo(FollowupOutcome::class, 'outcome_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), FollowupStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === FollowupStatus::Pending;
    }

    /** Planned, and its time has passed. */
    public function isOverdue(): bool
    {
        return $this->isPending() && $this->scheduled_at->isPast();
    }

    protected function companyScopedParents(): array
    {
        return ['outcome_id' => FollowupOutcome::class];
    }

    protected function auditLogName(): string
    {
        return 'followups';
    }
}

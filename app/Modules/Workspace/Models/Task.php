<?php

namespace App\Modules\Workspace\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\TenantModel;
use App\Modules\Workspace\Enums\Recurrence;
use App\Modules\Workspace\Enums\TaskPriority;
use App\Modules\Workspace\Enums\TaskStatus;
use App\Modules\Workspace\Policies\TaskPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Something a person has to do — personal or for the team — optionally about a record. Its `status` is its own state (to do → in progress →
 * done | called off), not the active/inactive of a master. Written only by TaskService.
 *
 * @property int $id
 * @property TaskStatus $status
 * @property TaskPriority $priority
 * @property string $title
 * @property string|null $description
 * @property Carbon|null $due_at
 * @property Carbon|null $remind_at
 * @property int $assigned_to
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property Recurrence $recurrence
 * @property Carbon|null $recurrence_until
 * @property string|null $completion_note
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 * @property string|null $cancel_reason
 * @property int|null $previous_id
 * @property Carbon|null $reminder_sent_at
 * @property Carbon|null $overdue_notified_at
 * @property int|null $location_id
 * @property int|null $created_by
 */
#[Fillable(['title', 'description', 'priority', 'due_at', 'remind_at', 'recurrence', 'recurrence_until'])]
#[UsePolicy(TaskPolicy::class)]
class Task extends TenantModel
{
    use BelongsToLocation;

    protected $attributes = [
        'status' => 'open',
        'priority' => 'normal',
        'recurrence' => 'none',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'recurrence' => Recurrence::class,
            'due_at' => 'datetime',
            'remind_at' => 'datetime',
            'recurrence_until' => 'date',
            'completed_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return HasMany<TaskAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class)->orderBy('id');
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    /** Still to do, and its deadline has passed. */
    public function isOverdue(): bool
    {
        return $this->isLive() && $this->due_at !== null && $this->due_at->isPast();
    }

    protected function auditLogName(): string
    {
        return 'tasks';
    }
}

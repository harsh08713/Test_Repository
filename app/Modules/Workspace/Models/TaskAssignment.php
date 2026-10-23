<?php

namespace App\Modules\Workspace\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One giving or handing over of a task — the first row is who it was first given to. Append-only; written only by TaskService.
 *
 * @property int $id
 * @property int $task_id
 * @property int|null $from_user_id
 * @property int $to_user_id
 * @property string|null $reason
 * @property int|null $actor_id
 * @property Carbon $created_at
 */
class TaskAssignment extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Task assignment history is append-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Task assignment history is append-only and cannot be deleted.'));
    }

    /** @return BelongsTo<User, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

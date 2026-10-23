<?php

namespace App\Modules\Workspace\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A file on a task. The row stays when the file is removed (who attached it and who removed it are a record); the file itself is deleted.
 * Written only by TaskAttachmentService.
 *
 * @property int $id
 * @property int $task_id
 * @property string $name
 * @property string $path
 * @property string|null $mime
 * @property int $size
 * @property int|null $uploaded_by
 * @property Carbon|null $removed_at
 * @property int|null $removed_by
 * @property Carbon $created_at
 */
class TaskAttachment extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'removed_at' => 'datetime', 'size' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('A task attachment is kept as a record and cannot be deleted.'));
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isPresent(): bool
    {
        return $this->removed_at === null;
    }
}

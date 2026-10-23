<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One move of an application between stages. **Append-only evidence.**
 *
 * @property int $application_id
 * @property string|null $from_stage
 * @property string $to_stage
 * @property string|null $note
 * @property int|null $recorded_by
 * @property Carbon $created_at
 */
#[Fillable([])]
class ApplicationEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The application history is evidence: it is never changed.'));
        static::deleting(fn () => throw new LogicException('The application history is evidence: it is never removed.'));
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One punch in or out. **Append-only evidence**: a missed punch is put right by a regularisation, which adds punches (source "regularised"), never edits one.
 *
 * @property int $employee_id
 * @property Carbon $punched_at
 * @property string $direction
 * @property string $source
 * @property int|null $regularisation_id
 * @property string|null $ip_address
 */
#[Fillable([])]
class AttendancePunch extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A punch is evidence: it is never changed.'));
        static::deleting(fn () => throw new LogicException('A punch is evidence: it is never removed.'));
    }

    protected function casts(): array
    {
        return ['punched_at' => 'datetime', 'created_at' => 'datetime'];
    }
}

<?php

namespace App\Modules\Quality\Models;

use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Every hold and release of a batch, and why. Append-only evidence.
 *
 * @property int $batch_id
 * @property string $action hold|release
 * @property string $reason
 * @property int|null $inspection_id
 * @property int|null $non_conformance_id
 * @property int|null $by_user
 * @property Carbon $at
 */
class BatchHold extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A hold or release is a record and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A hold or release is a record and cannot be deleted.'));
    }
}

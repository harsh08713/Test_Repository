<?php

namespace App\Modules\Shared\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Maintains created_by / updated_by from the authenticated user.
 *
 * @mixin Model
 */
trait HasAuditColumns
{
    public static function bootHasAuditColumns(): void
    {
        static::creating(function (Model $model) {
            $userId = Auth::id();

            if ($userId !== null) {
                if ($model->getAttribute('created_by') === null) {
                    $model->setAttribute('created_by', $userId);
                }

                $model->setAttribute('updated_by', $userId);
            }
        });

        static::updating(function (Model $model) {
            $userId = Auth::id();

            if ($userId !== null && ! $model->isDirty('updated_by')) {
                $model->setAttribute('updated_by', $userId);
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

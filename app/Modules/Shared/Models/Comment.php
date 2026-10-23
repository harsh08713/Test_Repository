<?php

namespace App\Modules\Shared\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A note left on any record by a user who may not be able to edit it (e.g. Operations on Item Master).
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $body
 * @property string $visibility
 * @property Carbon|null $created_at
 * @property-read User|null $user
 */
#[Fillable(['company_id', 'user_id', 'body'])]
class Comment extends Model
{
    use BelongsToCompany, SoftDeletes;

    /** @return MorphTo<Model, $this> */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

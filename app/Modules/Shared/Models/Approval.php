<?php

namespace App\Modules\Shared\Models;

use App\Models\User;
use App\Modules\Shared\Enums\ApprovalLevelStatus;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One level of one submission of an approvable document. Decisions are written once
 * (status, comment, acted_by, acted_at) and never edited afterwards. Created and decided
 * only through ApprovalService, hence no mass-assignable attributes.
 *
 * @property int $id
 * @property int $company_id
 * @property string $approvable_type
 * @property int $approvable_id
 * @property DocumentType $document_type
 * @property int $cycle
 * @property int $level
 * @property string|null $level_name
 * @property string|null $approver_role
 * @property int|null $approver_user_id
 * @property ApprovalLevelStatus $status
 * @property bool $is_parallel
 * @property string|null $document_hash
 * @property int|null $due_hours
 * @property Carbon|null $due_at
 * @property bool $overridden
 * @property int|null $submitted_by
 * @property string|null $comment
 * @property int|null $acted_by
 * @property Carbon|null $acted_at
 * @property Carbon|null $created_at
 * @property-read Model|null $approvable
 * @property-read User|null $submitter
 * @property-read User|null $actor
 */
class Approval extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'status' => ApprovalLevelStatus::class,
            'acted_at' => 'datetime',
            'due_at' => 'datetime',
            'is_parallel' => 'boolean',
            'overridden' => 'boolean',
            'cycle' => 'integer',
            'level' => 'integer',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalLevelStatus::Pending;
    }
}

<?php

namespace App\Modules\Shared\Models;

use App\Models\User;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Concerns\Auditable;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuration row: level N of a document type must be approved by a role or a specific user.
 *
 * @property int $id
 * @property int $company_id
 * @property DocumentType $document_type
 * @property int $level
 * @property string|null $name
 * @property string|null $approver_role
 * @property int|null $approver_user_id
 * @property int|null $due_hours
 */
#[Fillable(['company_id', 'document_type', 'level', 'name', 'approver_role', 'approver_user_id', 'due_hours'])]
class ApprovalLevel extends Model
{
    use Auditable, BelongsToCompany;

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'level' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}

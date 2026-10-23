<?php

namespace App\Modules\Shared\Models;

use App\Modules\Shared\Contracts\CommentableRecord;
use App\Modules\Shared\Models\Concerns\Auditable;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use App\Modules\Shared\Models\Concerns\HasAuditColumns;
use App\Modules\Shared\Models\Concerns\HasComments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Base class for company-owned masters and transactions: tenant-scoped, audited,
 * soft-deleted, commentable, with created_by/updated_by maintained.
 *
 * Add `Approvable` for approval-workflow documents and `BelongsToLocation` for branch-owned ones.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $status
 * @property string|null $remarks
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
abstract class TenantModel extends Model implements CommentableRecord
{
    use Auditable, BelongsToCompany, HasAuditColumns, HasComments, SoftDeletes;

    /**
     * Mirror the column defaults in memory, so a freshly created instance already knows its status
     * and the audit log records real before/after values instead of nulls.
     */
    protected $attributes = [
        'status' => 'active',
    ];
}

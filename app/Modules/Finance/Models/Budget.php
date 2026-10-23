<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Policies\BudgetPolicy;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Concerns\Approvable;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a cost centre may lose in a month — for one loss category, or (no category) of every category together. An approval document: only an
 * approved budget is compared with actuals.
 *
 * @property int $cost_center_id
 * @property int|null $loss_category_id
 * @property Carbon $period_start first day of the month
 * @property string $amount
 */
#[UsePolicy(BudgetPolicy::class)]
class Budget extends TenantModel implements ApprovableDocument
{
    use Approvable, ValidatesParentCompany;

    /** What may still be written to a budget that is waiting for approval or approved. */
    private const MOVING_COLUMNS = ['approval_status', 'approved_by', 'approved_at', 'updated_by', 'updated_at'];

    /** A budget has no active/inactive status: its approval says where it stands. */
    protected $attributes = [];

    protected static function booted(): void
    {
        static::updating(function (self $budget) {
            if (in_array((string) $budget->getRawOriginal('approval_status'), ['pending', 'approved'], true) && array_diff(array_keys($budget->getDirty()), self::MOVING_COLUMNS) !== []) {
                throw new \LogicException('A budget that is waiting for approval or approved cannot be changed: re-open it first.');
            }
        });
    }

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    }

    public function approvalDocumentType(): DocumentType
    {
        return DocumentType::Budget;
    }

    public function approvalUrl(): ?string
    {
        return route('finance.budgets.show', $this);
    }

    public function approvalTitle(): string
    {
        return 'Budget '.$this->period_start->format('M Y').' — '.($this->costCenter->name ?? 'cost centre');
    }

    public function defaultApproverRole(): ?string
    {
        return null;   // anyone holding budget.approve (not the maker)
    }

    /** @throws ApprovalStateException */
    public function beforeSubmitForApproval(): void
    {
        if ($this->costCenter === null || $this->costCenter->status !== 'active') {
            throw ApprovalStateException::notReady('The cost centre is no longer active.');
        }
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /** @return BelongsTo<LossCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(LossCategory::class, 'loss_category_id');
    }

    protected function companyScopedParents(): array
    {
        return ['cost_center_id' => CostCenter::class];
    }
}

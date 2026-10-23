<?php

namespace App\Modules\Shared\Models\Concerns;

use App\Models\User;
use App\Modules\Shared\Enums\ApprovalLevelStatus;
use App\Modules\Shared\Enums\ApprovalStatus;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\Approval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Arr;

/**
 * Makes a model go through the approval workflow (see ApprovalService).
 *
 * The table needs `approval_status`, `approved_by`, `approved_at` (Blueprint::masterColumns()).
 * Those columns are deliberately NOT mass-assignable: only ApprovalService changes them.
 *
 * @mixin Model
 *
 * @property ApprovalStatus $approval_status
 */
trait Approvable
{
    /** Which document type (and therefore permission form / approval levels) this model belongs to. */
    abstract public function approvalDocumentType(): DocumentType;

    public function initializeApprovable(): void
    {
        $this->mergeCasts([
            'approval_status' => ApprovalStatus::class,
            'approved_at' => 'datetime',
        ]);

        // New records start as drafts (matches the column default, so audit diffs are accurate).
        $this->attributes['approval_status'] ??= ApprovalStatus::Draft->value;
    }

    /** @return MorphMany<Approval, $this> */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable')->orderBy('cycle')->orderBy('level');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Approval rows of the latest submission only.
     *
     * @return MorphMany<Approval, $this>
     */
    public function currentApprovals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable')
            ->where('cycle', fn ($query) => $query->selectRaw('max(cycle)')
                ->from('approvals as latest')
                ->whereColumn('latest.approvable_id', 'approvals.approvable_id')
                ->whereColumn('latest.approvable_type', 'approvals.approvable_type'))
            ->orderBy('level');
    }

    public function nextPendingApproval(): ?Approval
    {
        return $this->currentApprovals()
            ->where('status', ApprovalLevelStatus::Pending->value)
            ->first();
    }

    /**
     * Only approved records may be used in transactions, dropdowns and API output.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('approval_status'), ApprovalStatus::Approved->value);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('approval_status'), ApprovalStatus::Pending->value);
    }

    /**
     * Approved and active: what transaction pickers should use.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('approval_status'), ApprovalStatus::Approved->value)
            ->where($this->qualifyColumn('status'), 'active');
    }

    public function isApproved(): bool
    {
        return $this->approval_status === ApprovalStatus::Approved;
    }

    public function approvalStatus(): ApprovalStatus
    {
        return $this->approval_status;
    }

    public function approvalCompanyId(): int
    {
        return (int) $this->getAttribute('company_id');
    }

    public function isPendingApproval(): bool
    {
        return $this->approval_status === ApprovalStatus::Pending;
    }

    /**
     * Hash of what the approver is signing off on. Models whose content lives partly in child rows (order
     * lines, UOM conversions...) override approvalFingerprintParts() to include them.
     */
    public function approvalFingerprint(): string
    {
        return hash('sha256', json_encode(self::sortKeysRecursively($this->approvalFingerprintParts()), JSON_THROW_ON_ERROR));
    }

    /**
     * The data covered by the fingerprint. By default every stored column except those the workflow itself
     * (or bookkeeping) changes.
     *
     * @return array<string, mixed>
     */
    protected function approvalFingerprintParts(): array
    {
        return Arr::except($this->getAttributes(), [
            'id', 'approval_status', 'approved_by', 'approved_at',
            'created_at', 'created_by', 'updated_at', 'updated_by', 'deleted_at',
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortKeysRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeysRecursively($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    public function isEditableByWorkflow(): bool
    {
        return $this->approval_status->isEditable();
    }

    /** Hook: the role routed to when the company has configured no approval levels for this type (null = anyone with the approve permission). */
    public function defaultApproverRole(): ?string
    {
        return null;
    }

    /** Hook: throw ApprovalStateException::notReady() here if prerequisites are missing (e.g. Item stages). */
    public function beforeSubmitForApproval(): void {}

    /** Short human label shown in inboxes and notifications. */
    public function approvalTitle(): string
    {
        $identifier = $this->getAttribute('code') ?? $this->getAttribute('number') ?? $this->getKey();
        $name = $this->getAttribute('name');

        return trim($this->approvalDocumentType()->label().' '.$identifier.($name ? " — {$name}" : ''));
    }

    /** Where a reviewer can open the record. Models override this once they have a screen. */
    public function approvalUrl(): ?string
    {
        return null;
    }
}

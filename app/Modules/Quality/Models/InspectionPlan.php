<?php

namespace App\Modules\Quality\Models;

use App\Modules\Masters\Models\Item;
use App\Modules\Quality\Policies\InspectionPlanPolicy;
use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\Concerns\Approvable;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * What to check on an item at one stage. An approval master (maker–checker): once waiting or approved it never changes — a change is a new plan, and the old one is made inactive.
 * Only an approved, active plan can start an inspection.
 *
 * @property string $code
 * @property string $name
 * @property int $item_id
 * @property string $stage incoming|in_process|final
 * @property string $status active|inactive
 */
#[Fillable(['code', 'name', 'stage', 'status', 'remarks'])]
#[UsePolicy(InspectionPlanPolicy::class)]
class InspectionPlan extends TenantModel implements ApprovableDocument
{
    use Approvable, ValidatesParentCompany;

    public const STAGES = ['incoming' => 'Incoming goods', 'in_process' => 'In process', 'final' => 'Final'];

    private const MOVING_COLUMNS = ['approval_status', 'approved_by', 'approved_at', 'status', 'updated_by', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $plan) {
            if (in_array((string) $plan->getRawOriginal('approval_status'), ['pending', 'approved'], true) && array_diff(array_keys($plan->getDirty()), self::MOVING_COLUMNS) !== []) {
                throw new LogicException('An inspection plan that is waiting for approval or approved cannot be changed: make a new one and make this one inactive.');
            }
        });
    }

    public function approvalDocumentType(): DocumentType
    {
        return DocumentType::InspectionPlan;
    }

    public function approvalUrl(): ?string
    {
        return route('quality.plans.show', $this);
    }

    public function approvalTitle(): string
    {
        return "Inspection plan {$this->code} — {$this->name}";
    }

    public function defaultApproverRole(): ?string
    {
        return null;
    }

    /** @throws ApprovalStateException */
    public function beforeSubmitForApproval(): void
    {
        if ($this->parameters()->doesntExist()) {
            throw ApprovalStateException::notReady('Add at least one thing to check before sending the plan for approval.');
        }
    }

    /** @return array<string, mixed> */
    protected function approvalFingerprintParts(): array
    {
        return [
            'plan' => collect($this->getAttributes())->only(['company_id', 'code', 'name', 'item_id', 'stage', 'remarks'])->all(),
            'parameters' => $this->parameters()->get()->map(fn (InspectionPlanParameter $p) => [$p->line_no, $p->name, $p->kind, $p->unit_text, (string) $p->min_value, (string) $p->max_value, $p->is_critical])->all(),
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return HasMany<InspectionPlanParameter, $this> */
    public function parameters(): HasMany
    {
        return $this->hasMany(InspectionPlanParameter::class)->orderBy('line_no');
    }

    public function isUsable(): bool
    {
        return $this->isApproved() && $this->status === 'active';
    }

    protected function companyScopedParents(): array
    {
        return ['item_id' => Item::class];
    }
}

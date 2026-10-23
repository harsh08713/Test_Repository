<?php

namespace App\Modules\Loss\Models;

use App\Models\User;
use App\Modules\Loss\Policies\CorrectiveActionPolicy;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A corrective or preventive action (CAPA) on a loss incident: who does it, by when, what it should save, and — once done — what it was verified
 * to have saved. Its state moves only through CapaService: open → in progress → done → verified (or back to in progress, or rejected).
 * "Overdue" is not a state: it is an open or in-progress action past its due date.
 *
 * @property int $location_id
 * @property int $loss_incident_id
 * @property int|null $root_cause_analysis_id
 * @property string $action_number
 * @property string $action_type corrective|preventive
 * @property string $title
 * @property string|null $description
 * @property int $owner_id
 * @property int|null $approver_id
 * @property Carbon $due_date
 * @property string|null $expected_benefit_amount
 * @property string|null $baseline_value
 * @property string $state open|in_progress|done|verified|rejected
 * @property Carbon|null $completed_at
 * @property string|null $completion_notes
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $verification_notes
 * @property string|null $verified_result_amount realised savings: counted only once verified
 * @property Carbon|null $escalated_at
 */
#[UsePolicy(CorrectiveActionPolicy::class)]
class CorrectiveAction extends TenantModel
{
    use BelongsToLocation, ValidatesParentCompany;

    public const STATES = ['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done, to verify', 'verified' => 'Verified', 'rejected' => 'Rejected'];

    /** A CAPA has no active/inactive `status`: its state says where it stands. */
    protected $attributes = ['state' => 'open'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d', 'expected_benefit_amount' => 'decimal:2', 'baseline_value' => 'decimal:2', 'verified_result_amount' => 'decimal:2',
            'completed_at' => 'datetime', 'verified_at' => 'datetime', 'escalated_at' => 'datetime',
        ];
    }

    public function isOverdue(?Carbon $today = null): bool
    {
        return in_array($this->state, ['open', 'in_progress'], true) && $this->due_date->toDateString() < ($today ?? now())->toDateString();
    }

    public function isFinished(): bool
    {
        return in_array($this->state, ['verified', 'rejected'], true);
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->state] ?? $this->state;
    }

    /** @return BelongsTo<LossIncident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(LossIncident::class, 'loss_incident_id');
    }

    /** @return BelongsTo<RootCauseAnalysis, $this> */
    public function rootCause(): BelongsTo
    {
        return $this->belongsTo(RootCauseAnalysis::class, 'root_cause_analysis_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    protected function companyScopedParents(): array
    {
        return ['loss_incident_id' => LossIncident::class];
    }
}

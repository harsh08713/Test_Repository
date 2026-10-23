<?php

namespace App\Modules\Maintenance\Models;

use App\Models\User;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Maintenance\Policies\MaintenanceOrderPolicy;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A maintenance work order. Its `state` moves only through MaintenanceOrderService; downtime, labour hours and the value of the parts are **worked out** from the entries
 * (MaintenanceFigures), never stored.
 *
 * @property string $order_number
 * @property int $location_id
 * @property int $asset_id
 * @property int|null $maintenance_plan_id
 * @property Carbon|null $plan_due_on
 * @property string $type breakdown|preventive|corrective
 * @property string $priority low|normal|high|urgent
 * @property string $title
 * @property string|null $description
 * @property int|null $reported_by
 * @property int|null $assigned_to
 * @property Carbon|null $due_on
 * @property string $state open|in_progress|completed|cancelled
 * @property Carbon|null $started_at
 * @property Carbon|null $stopped_at
 * @property Carbon|null $restored_at
 * @property Carbon|null $completed_at
 * @property string|null $completion_note
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int|null $loss_incident_id
 */
#[UsePolicy(MaintenanceOrderPolicy::class)]
class MaintenanceOrder extends TenantModel
{
    use BelongsToLocation, ValidatesParentCompany;

    public const TYPES = ['breakdown' => 'Breakdown', 'preventive' => 'Preventive', 'corrective' => 'Corrective'];

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

    public const STATES = ['open' => 'Open', 'in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

    protected $attributes = ['state' => 'open', 'priority' => 'normal'];

    protected static function booted(): void
    {
        static::updating(function (self $o) {
            if (in_array((string) $o->getRawOriginal('state'), ['completed', 'cancelled'], true)) {
                throw new LogicException('A completed or cancelled work order does not change.');
            }
        });

        static::deleting(fn () => throw new LogicException('A work order is never deleted: cancel it instead.'));
    }

    protected function casts(): array
    {
        return [
            'plan_due_on' => 'date:Y-m-d', 'due_on' => 'date:Y-m-d', 'started_at' => 'datetime', 'stopped_at' => 'datetime', 'restored_at' => 'datetime',
            'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<MaintenancePlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** @return BelongsTo<LossIncident, $this> */
    public function lossIncident(): BelongsTo
    {
        return $this->belongsTo(LossIncident::class);
    }

    /** @return HasMany<MaintenanceTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class)->orderBy('line_no');
    }

    /** @return HasMany<MaintenancePart, $this> */
    public function parts(): HasMany
    {
        return $this->hasMany(MaintenancePart::class)->orderBy('id');
    }

    /** @return HasMany<MaintenanceLabour, $this> */
    public function labour(): HasMany
    {
        return $this->hasMany(MaintenanceLabour::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->state, ['open', 'in_progress'], true);
    }

    public function isFinished(): bool
    {
        return in_array($this->state, ['completed', 'cancelled'], true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_on !== null && $this->due_on->lt(now()->startOfDay());
    }

    protected function companyScopedParents(): array
    {
        return ['asset_id' => Asset::class, 'maintenance_plan_id' => MaintenancePlan::class, 'loss_incident_id' => LossIncident::class];
    }
}

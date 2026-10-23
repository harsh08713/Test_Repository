<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Maintenance\Policies\MaintenancePlanPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A preventive schedule for one asset: every `every_n` days / weeks / months. A work order is made `lead_days` before it falls due (by `maintenance:generate-orders`, once for each due date);
 * when that order is completed the schedule moves on from the **day it was completed**.
 *
 * @property string $code
 * @property int $asset_id
 * @property string $name
 * @property string|null $checklist
 * @property int $every_n
 * @property string $unit days|weeks|months
 * @property int $lead_days
 * @property string|null $estimated_hours
 * @property Carbon $next_due_on
 * @property Carbon|null $last_done_on
 * @property string $status active|inactive
 */
#[Fillable(['code', 'name', 'checklist', 'every_n', 'unit', 'lead_days', 'estimated_hours', 'status', 'remarks'])]
#[UsePolicy(MaintenancePlanPolicy::class)]
class MaintenancePlan extends TenantModel
{
    use ValidatesParentCompany;

    public const UNITS = ['days' => 'days', 'weeks' => 'weeks', 'months' => 'months'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('A schedule is never deleted: make it inactive.'));
    }

    protected function casts(): array
    {
        return ['next_due_on' => 'date:Y-m-d', 'last_done_on' => 'date:Y-m-d', 'estimated_hours' => 'decimal:1', 'every_n' => 'integer', 'lead_days' => 'integer'];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** When it falls due after a job done on $date. */
    public function dueAfter(Carbon $date): Carbon
    {
        $from = $date->copy()->startOfDay();

        return match ($this->unit) {
            'weeks' => $from->addWeeks($this->every_n),
            'months' => $from->addMonthsNoOverflow($this->every_n),
            default => $from->addDays($this->every_n),
        };
    }

    public function everyLabel(): string
    {
        return 'every '.($this->every_n === 1 ? rtrim($this->unit, 's') : $this->every_n.' '.$this->unit);
    }

    protected function companyScopedParents(): array
    {
        return ['asset_id' => Asset::class];
    }
}

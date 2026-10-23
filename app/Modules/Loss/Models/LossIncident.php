<?php

namespace App\Modules\Loss\Models;

use App\Models\User;
use App\Modules\Finance\Models\CostCenter;
use App\Modules\Industry\Models\IndustryTemplate;
use App\Modules\Industry\Models\LossCategory;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Loss\Policies\LossIncidentPolicy;
use App\Modules\Masters\Models\Department;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Unit;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A reported loss. Its `state` moves only through the industry template's workflow (LossWorkflowService); its money is in impact lines, kept
 * apart by kind (actual, exposure, opportunity); its causes in root-cause analyses; what is done about it in corrective actions.
 *
 * @property string $incident_number
 * @property int $location_id
 * @property int $industry_template_id
 * @property int $template_version
 * @property int $loss_category_id
 * @property int|null $department_id
 * @property int|null $cost_center_id the cost centre the loss is charged to (budget against actual)
 * @property string|null $process
 * @property int|null $item_id
 * @property int|null $batch_id
 * @property string|null $asset_ref
 * @property string|null $source_type
 * @property int|null $source_id
 * @property int|null $primary_incident_id set when another incident already covers the same source: this one's money is not counted
 * @property Carbon $occurred_at
 * @property Carbon $reported_at
 * @property string|null $quantity
 * @property int|null $unit_id
 * @property string $currency
 * @property string $severity low|medium|high|critical
 * @property bool $is_safety
 * @property bool $is_quality
 * @property bool $is_compliance
 * @property string $title
 * @property string $description
 * @property array<string, mixed>|null $custom_fields
 * @property int $reporter_id
 * @property int|null $investigator_id
 * @property int|null $owner_id
 * @property string $state
 * @property Carbon|null $contained_at
 * @property string|null $containment_notes
 * @property Carbon|null $escalated_at
 * @property Carbon|null $closed_at
 */
#[UsePolicy(LossIncidentPolicy::class)]
class LossIncident extends TenantModel
{
    use BelongsToLocation, ValidatesParentCompany;

    public const SEVERITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    /** An incident has no active/inactive `status`: its workflow state says where it stands. */
    protected $attributes = ['currency' => 'INR', 'is_safety' => false, 'is_quality' => false, 'is_compliance' => false];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime', 'reported_at' => 'datetime', 'contained_at' => 'datetime', 'escalated_at' => 'datetime', 'closed_at' => 'datetime',
            'quantity' => 'decimal:3', 'is_safety' => 'boolean', 'is_quality' => 'boolean', 'is_compliance' => 'boolean', 'custom_fields' => 'array',
        ];
    }

    /** Does it need to be escalated the moment it is reported: a safety, quality or compliance risk, or critical? */
    public function needsImmediateEscalation(): bool
    {
        return $this->is_safety || $this->is_quality || $this->is_compliance || $this->severity === 'critical';
    }

    public function isDuplicate(): bool
    {
        return $this->primary_incident_id !== null;
    }

    // ---- relations ----------------------------------------------------------------------------------------------

    /** @return BelongsTo<IndustryTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(IndustryTemplate::class, 'industry_template_id');
    }

    /** @return BelongsTo<LossCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(LossCategory::class, 'loss_category_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investigator_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<LossIncident, $this> */
    public function primary(): BelongsTo
    {
        return $this->belongsTo(self::class, 'primary_incident_id');
    }

    /** @return HasMany<LossImpactLine, $this> */
    public function impactLines(): HasMany
    {
        return $this->hasMany(LossImpactLine::class)->orderBy('id');
    }

    /** @return HasMany<RootCauseAnalysis, $this> */
    public function rootCauses(): HasMany
    {
        return $this->hasMany(RootCauseAnalysis::class)->orderByDesc('id');
    }

    /** @return HasMany<CorrectiveAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(CorrectiveAction::class)->orderBy('id');
    }

    protected function companyScopedParents(): array
    {
        // the template and its loss category are system data with no company: LossIncidentService checks them against the company's templates
        return [
            'cost_center_id' => CostCenter::class, 'department_id' => Department::class, 'item_id' => Item::class, 'batch_id' => Batch::class, 'unit_id' => Unit::class, 'primary_incident_id' => self::class,
        ];
    }
}

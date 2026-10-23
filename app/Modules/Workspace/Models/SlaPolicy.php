<?php

namespace App\Modules\Workspace\Models;

use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use App\Modules\Workspace\Enums\SlaKind;
use App\Modules\Workspace\Policies\SlaPolicyPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How long one kind of thing may wait (see the migration). Applies by lead source, team and priority; the most specific policy that matches wins.
 *
 * @property int $id
 * @property string $name
 * @property SlaKind $kind
 * @property int|null $lead_source_id
 * @property int|null $sales_team_id
 * @property string|null $priority
 * @property int $target_minutes
 * @property bool $business_hours
 * @property bool $escalate_to_manager
 * @property string $status
 */
#[Fillable(['name', 'kind', 'priority', 'target_minutes', 'business_hours', 'escalate_to_manager', 'status', 'remarks'])]
#[UsePolicy(SlaPolicyPolicy::class)]
class SlaPolicy extends TenantModel
{
    use ValidatesParentCompany;

    protected function casts(): array
    {
        return ['kind' => SlaKind::class, 'target_minutes' => 'integer', 'business_hours' => 'boolean', 'escalate_to_manager' => 'boolean'];
    }

    /** @return BelongsTo<LeadSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    /** @return BelongsTo<SalesTeam, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(SalesTeam::class, 'sales_team_id');
    }

    protected function companyScopedParents(): array
    {
        return ['lead_source_id' => LeadSource::class, 'sales_team_id' => SalesTeam::class];
    }

    protected function auditLogName(): string
    {
        return 'sla_policies';
    }
}

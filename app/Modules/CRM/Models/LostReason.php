<?php

namespace App\Modules\CRM\Models;

use App\Modules\CRM\Policies\CrmMasterPolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

/**
 * Why a lead (and later a deal) was lost. A lead cannot be marked lost without one, so "why do we lose" can be answered.
 *
 * @property string $name
 */
#[Fillable(['code', 'name', 'status', 'remarks'])]
#[UsePolicy(CrmMasterPolicy::class)]
class LostReason extends TenantModel
{
    protected function auditLogName(): string
    {
        return 'lost_reasons';
    }
}

<?php

namespace App\Modules\Workspace\Models;

use App\Modules\Shared\Models\TenantModel;
use App\Modules\Workspace\Policies\SlaPolicyPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Support\Carbon;

/**
 * A day the company is closed: SLA minutes counted in business hours skip it. Removing one removes the row for good (the audit log keeps the fact),
 * so the same day can be added again.
 *
 * @property int $id
 * @property Carbon $holiday_date
 * @property string $name
 */
#[Fillable(['holiday_date', 'name'])]
#[UsePolicy(SlaPolicyPolicy::class)]
class Holiday extends TenantModel
{
    protected $attributes = [];

    protected function casts(): array
    {
        return ['holiday_date' => 'date'];
    }

    protected function auditLogName(): string
    {
        return 'holidays';
    }
}

<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Policies\CostCenterPolicy;
use App\Modules\Masters\Models\Department;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place costs are collected (a plant, a department, a production line). A plain master; it may sit under another cost centre.
 * Budgets and loss incidents point at it. (Branch-scoped reads are not applied: a cost centre is a company-wide label.)
 *
 * @property string $name
 * @property int|null $parent_id
 * @property int|null $location_id
 * @property int|null $department_id
 */
#[Fillable(['code', 'name', 'parent_id', 'location_id', 'department_id', 'status', 'remarks'])]
#[UsePolicy(CostCenterPolicy::class)]
class CostCenter extends TenantModel
{
    use ValidatesParentCompany;

    /** @return BelongsTo<CostCenter, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    protected function companyScopedParents(): array
    {
        return ['parent_id' => self::class, 'location_id' => Location::class, 'department_id' => Department::class];
    }
}

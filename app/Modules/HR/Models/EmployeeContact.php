<?php

namespace App\Modules\HR\Models;

use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone to call about an employee.
 *
 * @property int $employee_id
 * @property string $name
 * @property string $relationship
 * @property string $mobile
 * @property bool $is_primary
 */
#[Fillable(['name', 'relationship', 'mobile', 'is_primary'])]
class EmployeeContact extends TenantModel
{
    protected $attributes = [];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
